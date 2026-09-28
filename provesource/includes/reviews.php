<?php

/**
 * WooCommerce product reviews for ProveSource review notifications.
 *
 * The plugin pushes the reviews, so the merchant pastes no review API keys: the latest approved reviews are sent on
 * connect and from the "Import reviews" button, then each review again whenever it is approved, edited,
 * unapproved, marked spam, trashed or deleted. Only what the notification shows is sent: the author's display name,
 * rating, text, date, the verified owner flag and the product. Never an email or an IP.
 *
 * Every send runs as an Action Scheduler job and reads the review as it is when the job runs, so a review changed
 * twice before its job runs is sent once, in its latest state.
 *
 * @package provesource
 */

if (!defined('ABSPATH')) {
    die;
}

define('PROVESRC_SEND_REVIEW_ACTION', 'provesrc_send_review');
define('PROVESRC_IMPORT_REVIEWS_ACTION', 'provesrc_import_reviews');
define('PROVESRC_OPTION_REVIEWS_IMPORT', 'provesrc_reviews_import');
define('PROVESRC_REVIEWS_IMPORT_LIMIT', 100);
define('PROVESRC_REVIEW_TEXT_LENGTH', 2000);

add_action('comment_post', 'provesrc_reviews_comment_posted', 20, 2);
add_action('transition_comment_status', 'provesrc_reviews_status_changed', 20, 3);
add_action('edit_comment', 'provesrc_reviews_comment_edited', 20, 1);
add_action('delete_comment', 'provesrc_reviews_comment_deleted', 20, 2);

// Action Scheduler jobs
add_action(PROVESRC_SEND_REVIEW_ACTION, 'provesrc_send_review', 10, 1);
add_action(PROVESRC_IMPORT_REVIEWS_ACTION, 'provesrc_import_reviews', 10, 0);

add_action('wp_ajax_provesrc_import_reviews', 'provesrc_ajax_import_reviews');

/** hooks */

function provesrc_reviews_comment_posted($commentId, $approved)
{
    // held and spam reviews are sent once they are approved
    if ($approved === 1 || $approved === '1') {
        provesrc_reviews_queue($commentId, 'comment_post');
    }
}

function provesrc_reviews_status_changed($newStatus, $oldStatus, $comment)
{
    if ($newStatus === $oldStatus || ($newStatus !== 'approved' && $oldStatus !== 'approved')) {
        return;
    }
    provesrc_reviews_queue($comment, 'transition_comment_status');
}

function provesrc_reviews_comment_edited($commentId)
{
    $comment = get_comment($commentId);
    if ($comment && $comment->comment_approved === '1') {
        provesrc_reviews_queue($comment, 'edit_comment');
    }
}

function provesrc_reviews_comment_deleted($commentId, $comment = null)
{
    // runs before the delete, the job finds the review gone and sends the removal
    if (!$comment) {
        $comment = get_comment($commentId);
    }
    if ($comment && $comment->comment_approved === '1') {
        provesrc_reviews_queue($comment, 'delete_comment');
    }
}

/**
 * Queues one review to be sent (or removed) in the background. $event is the hook that changed it, for the log.
 */
function provesrc_reviews_queue($commentOrId, $event)
{
    static $queuedInRequest = array();
    $comment = get_comment($commentOrId);
    if (!$comment || !provesrc_is_connected() || !provesrc_has_woocommerce() || !provesrc_is_product_review($comment)) {
        return;
    }
    $commentId = (int) $comment->comment_ID;
    if (isset($queuedInRequest[$commentId])) {
        return;
    }
    $queuedInRequest[$commentId] = true;

    $args = array($commentId);
    if (function_exists('as_enqueue_async_action')) {
        if (provesrc_is_action_queued(PROVESRC_SEND_REVIEW_ACTION, $args)) {
            provesrc_log('review already queued', array('commentId' => $commentId, 'event' => $event));
            return;
        }
        as_enqueue_async_action(PROVESRC_SEND_REVIEW_ACTION, $args, PROVESRC_ACTION_GROUP);
        provesrc_log('review queued', array('commentId' => $commentId, 'event' => $event));
        return;
    }

    provesrc_log('action scheduler unavailable, sending review now', array('commentId' => $commentId, 'event' => $event));
    try {
        provesrc_send_review($commentId);
    } catch (Exception $err) {
        // already logged by provesrc_send_review, never break saving the review
    }
}

/**
 * Queues sending the latest approved reviews. Call it once the site is connected (after /wp/setup succeeds);
 * resending reviews is safe, the server updates them in place.
 */
function provesrc_reviews_queue_import()
{
    if (!provesrc_is_connected() || !provesrc_has_woocommerce()) {
        return false;
    }
    if (function_exists('as_enqueue_async_action')) {
        $isQueued = provesrc_is_action_queued(PROVESRC_IMPORT_REVIEWS_ACTION, array());
        if (!$isQueued) {
            as_enqueue_async_action(PROVESRC_IMPORT_REVIEWS_ACTION, array(), PROVESRC_ACTION_GROUP);
        }
        provesrc_log('reviews import queued', array('alreadyQueued' => $isQueued));
        return true;
    }
    provesrc_log('action scheduler unavailable, importing reviews now');
    try {
        provesrc_import_reviews();
    } catch (Exception $err) {
        return false;
    }
    return true;
}

/** hooks - END */

/** jobs */

/**
 * Sends one review as it is now: approved with a rating, or its status (unapproved, spam, trash, deleted) so the
 * server removes it. A failed request throws so the job is marked failed.
 */
function provesrc_send_review($commentId)
{
    $comment = get_comment($commentId);
    if ($comment && !provesrc_is_product_review($comment)) {
        return;
    }
    $review = $comment ? provesrc_get_review_payload($comment) : array('id' => absint($commentId), 'status' => 'deleted');
    provesrc_post_reviews(array($review));
    provesrc_log('review sent', array('commentId' => $commentId, 'status' => $review['status']));
}

/**
 * Sends the latest approved product reviews that have a rating.
 */
function provesrc_import_reviews()
{
    if (!provesrc_has_woocommerce()) {
        return;
    }
    $comments = get_comments(provesrc_reviews_query(array(
        'number' => PROVESRC_REVIEWS_IMPORT_LIMIT,
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC',
    )));
    $reviews = array();
    foreach ($comments as $comment) {
        $reviews[] = provesrc_get_review_payload($comment);
    }
    if (!empty($reviews)) {
        provesrc_post_reviews($reviews);
    }
    update_option(PROVESRC_OPTION_REVIEWS_IMPORT, array('time' => time(), 'count' => count($reviews)), false);
    provesrc_log('reviews imported', array('count' => count($reviews)));
}

/** jobs - END */

/** settings */

/**
 * The reviews block of the settings page: how many reviews the store has and an "Import reviews" button.
 * Prints nothing without WooCommerce.
 */
function provesrc_reviews_settings_section()
{
    if (!provesrc_has_woocommerce()) {
        return;
    }
    $count = provesrc_count_reviews();
    $lastImport = get_option(PROVESRC_OPTION_REVIEWS_IMPORT);
    $connected = provesrc_is_connected();
    ?>
    <div class="ps-reviews-section">
        <h2>WooCommerce Reviews</h2>
        <p class="description">
            Show your product reviews in a ProveSource reviews notification. Approved reviews are sent automatically,
            and removed again when they are unapproved, marked as spam or deleted.
        </p>
        <p>
            <strong><?php echo esc_html(number_format_i18n($count)); ?></strong>
            approved product <?php echo esc_html(_n('review', 'reviews', $count, 'provesource')); ?> with a rating.
            <?php if (is_array($lastImport) && !empty($lastImport['time'])) { ?>
                Last import:
                <?php echo esc_html(number_format_i18n((int) $lastImport['count'])); ?> sent
                <?php echo esc_html(human_time_diff((int) $lastImport['time'])); ?> ago.
            <?php } ?>
        </p>
        <button type="button" id="provesrc_import_reviews_button" class="button button-secondary"
            <?php disabled(!$connected || $count < 1); ?>>
            Import Reviews
        </button>
        <span id="provesrc_import_reviews_result" class="description" style="margin-left:10px"></span>
    </div>
    <?php
}

function provesrc_ajax_import_reviews()
{
    if (!isset($_POST['security']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['security'])), 'provesrc_import_reviews_nonce')) {
        wp_send_json_error('Invalid request');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    if (!provesrc_is_connected()) {
        wp_send_json_error('Connect ProveSource first');
    }
    $transientKey = 'provesrc_last_reviews_import_time';
    if (get_transient($transientKey)) {
        wp_send_json_error('Reviews can only be imported once per minute');
    }

    provesrc_log('importing reviews manually');
    if (!provesrc_reviews_queue_import()) {
        wp_send_json_error('Failed to import reviews, enable debug mode and check the debug log');
    }
    set_transient($transientKey, time(), MINUTE_IN_SECONDS);
    $sending = min(provesrc_count_reviews(), PROVESRC_REVIEWS_IMPORT_LIMIT);
    wp_send_json_success(sprintf('Sending your latest %d reviews to ProveSource in the background.', $sending));
}

/** settings - END */

/** helpers */

/**
 * A top level review on a product. Replies (the store answering a review) have a parent and no rating.
 */
function provesrc_is_product_review($comment)
{
    return (int) $comment->comment_parent === 0
        && in_array($comment->comment_type, array('review', 'comment', ''), true)
        && get_post_type($comment->comment_post_ID) === 'product';
}

/**
 * get_comments args for approved top level product reviews that have a rating.
 */
function provesrc_reviews_query($args = array())
{
    return array_merge(array(
        'post_type' => 'product',
        'status' => 'approve',
        // "comment" also matches the empty type older WooCommerce versions stored reviews with
        'type__in' => array('review', 'comment'),
        'parent' => 0,
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the rating is only stored as comment meta
        'meta_query' => array(
            array(
                'key' => 'rating',
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ),
        ),
    ), $args);
}

function provesrc_count_reviews()
{
    return (int) get_comments(provesrc_reviews_query(array('count' => true)));
}

/**
 * The review as the server takes it. Anything but an approved review with a rating goes out as just its id and
 * status, which removes it.
 */
function provesrc_get_review_payload($comment)
{
    $commentId = (int) $comment->comment_ID;
    $status = wp_get_comment_status($comment);
    $rating = (int) get_comment_meta($commentId, 'rating', true);
    if ($status !== 'approved' || $rating < 1) {
        return array('id' => $commentId, 'status' => $status ? $status : 'deleted');
    }
    $productId = (int) $comment->comment_post_ID;
    $date = strtotime($comment->comment_date_gmt . ' UTC');
    $image = get_the_post_thumbnail_url($productId, 'thumbnail');
    return array(
        'id' => $commentId,
        'status' => 'approved',
        'rating' => min($rating, 5),
        'author' => $comment->comment_author,
        'text' => wp_html_excerpt($comment->comment_content, PROVESRC_REVIEW_TEXT_LENGTH),
        'date' => $date > 0 ? $date * 1000 : null,
        'verified' => (bool) get_comment_meta($commentId, 'verified', true),
        'product' => array(
            'id' => $productId,
            // the raw title, get_the_title() adds HTML entities (&#8217;) that would show up in the notification
            'name' => get_post_field('post_title', $productId, 'raw'),
            'link' => get_permalink($productId),
            'image' => $image ? $image : null,
        ),
    );
}

function provesrc_post_reviews($reviews)
{
    $res = provesrc_send_request('/webhooks/track/woocommerce-reviews', array(
        'siteUrl' => get_site_url(),
        'reviews' => $reviews,
    ), null, null, 30);
    $responseCode = (int) wp_remote_retrieve_response_code($res);
    if (is_wp_error($res) || $responseCode < 200 || $responseCode >= 300) {
        $reason = is_wp_error($res) ? $res->get_error_message() : 'status ' . $responseCode;
        provesrc_log('reviews webhook failed', array('count' => count($reviews), 'reason' => $reason));
        throw new Exception('ProveSource reviews webhook failed: ' . esc_html($reason));
    }
    return $res;
}

/** helpers - END */
