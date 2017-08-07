<?php
// /wp-content/plugins/realtime-comments/ajax.php
error_reporting(0);

if (!defined( 'SHORTINT')) { define('SHORTINT', true); }
if (!defined( 'DOING_AJAX')) { define('DOING_AJAX', true); }

/* prevent plugins loading */
if (!defined( 'WP_INSTALLING')) { define('WP_INSTALLING', true); }

/** Load WordPress Bootstrap */
require_once( '../../../wp-load.php' );

/** Allow for cross-domain requests (from the frontend). */
send_origin_headers();
send_nosniff_header();
nocache_headers();

@header( 'Content-Type: text/json; charset=' . get_option( 'blog_charset' ) );
@header( 'X-Robots-Tag: noindex' );


function rtc_query_var($var, $type) {
    $query_vars = $_GET;
    if (isset($query_vars[$var])) {
        $result = $query_vars[$var];
        if ($type === 'intval') {
            return intval($result);
        } else if ($type === 'string') {
            return $result;
        }
    }
    return false;
}

$action = rtc_query_var('action', 'string');
$postid = rtc_query_var('postid', 'intval');


// if(function_exists('wp_verify_nonce')) echo 'Verify nonce! - ';

switch ($action) {
    case 'rtc_update':
    $bookmark = rtc_query_var('rtc_bookmark', 'intval');
    $max_c_id = rtc_query_var('max_c_id', 'intval');
    if($postid === false || $bookmark === false || $max_c_id === false) {
        $response=array(
            'status'=>500,
            'msg'=>'postid or bookmark or max_c_id not set'
        );
    } else {

        $results = $wpdb->get_results( 
            'SELECT cache_ID, comment_ID as id, comment_parent_ID as parent, status, html 
             FROM '.$wpdb->prefix.'rtc_cache c 
             WHERE c.post_ID='.$postid.' AND (c.last_modified>='.$bookmark.' OR c.comment_ID>'.$max_c_id.') 
             ORDER BY c.comment_ID ASC', OBJECT );

        $response=array(
            'status'=>200,
            'bookmark'=>time(),
            'comments'=>$results,
            );
    }
    break;
    case 'rtc_next_page':
        $last_comment = rtc_query_var('last_comment', 'intval');
        if($postid === false) {
        $response=array(
            'status'=>500,
            'msg'=>'postid not set'
            );
        } else {
            require_once('helper.php');
            $number = get_option('comments_per_page');

            // logic copied from wp-includes/comment-template.php function comments_template() line 1162
            $comment_args=array(
                'post_id'=>$postid,
                'order' => 'ASC',
                'orderby' => 'comment_ID',
                'status' => 'approve',
                'parent' => '0',
                'number' => $number,
            );

            if ( isset($user_ID) && $user_ID ) {
                $comment_args['include_unapproved'] = array( $user_ID );
            } else if ( isset($comment_author_email) && !empty( $comment_author_email )) {
                $comment_args['include_unapproved'] = array( $comment_author_email );
            }

            // this way works starting from 3.5
            $comments = get_comments( $comment_args );
            $comments = RTC_helper::get_next_comments_page($comments, $last_comment, $number);
            $html = RTC_helper::get_comment_html($comments->comments);
            $response=array(
                'status'=>200,
                'last_comment'=>$comments->last_comment,
                'html'=>$html,
                );
        }
    break;
    default:
        $response=array(
            'status'=>500,
            'msg'=>'action not set'
        );

}
die(json_encode($response));
