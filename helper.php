<?php

class RTC_helper {
    static function get_comment_html($comments) {
        global $wp_version;
        $html = '';
        $default_args = array();
        $values=get_option('rtc-settings', $default_args); // use default_args!!

        $args=array(
            'echo' => false,
            'style' => 'ol', // {ol|ul|div}
            'format' => 'html5',
            'reverse_top_level' => true,
            );
        if(!is_array($comments)) {
            $comments = array($comments);
        }

        if (isset($values['comment_walker']) && $values['comment_walker'] && function_exists($values['comment_walker'])) {
            $args['callback'] = $values['comment_walker'];
        }
        if (isset($values['avatar_size']) && $values['avatar_size']) {
            $args['avatar_size'] = $values['avatar_size'];
        }
        if (isset($values['comment_style']) && $values['comment_style']) {
            $args['style'] = $values['comment_style'];
        }

        if(version_compare($wp_version, '3.8', '<')) {
            ob_start();
            wp_list_comments($args, $comments);
            $html = ob_get_clean();
        } else {
            $html = wp_list_comments($args, $comments);
        }
        return $html;
    }

    private static function add_children(&$parent, &$tree) {
        if (isset($parent->children) && is_array($parent->children)) {
            for ($i=0; $i<count($parent->children); $i++) {
                $tree[] = $parent->children[$i];
                RTC_helper::add_children($parent->children[$i], $tree);
            }
        }
    }

    public static function get_next_comments_page($comments, $last_comment_id, $comments_per_page) {
        global $wp_version;
        if(version_compare($wp_version, '4.4', '>=')) {
            return self::get_next_comments_page_44($comments, $last_comment_id, $comments_per_page);        
        } else {
            return self::get_next_comments_page_35($comments, $last_comment_id, $comments_per_page);        
        }

    }

    public static function get_next_comments_page_44($comments, $last_comment_id, $comments_per_page) {
        $return = new stdClass;
        var_dump($comments);
        for($i=0; $i<count($comments); $i++) {
            // var_dump
        }
        $return->comments = $comments;
        $return->last_comment = $comments[0]->comment_ID;
        return $return;
    }

    public static function get_next_comments_page_35($comments, $last_comment_id, $comments_per_page) {
        $toplevel = array();
        $orphans = array();
        $tree = array();
        $count_toplevel = 0;
        $last_comment = 0;
        $comments_slice = array();
        for($i=0; $i<count($comments); $i++) {
            $comment = &$comments[$i];
            if ($comment->comment_parent === '0' && $comment->comment_ID<$last_comment_id) {
                // rootlevel comment will be added to toplevel array, if comment_ID is smaller than last comment visible on screen
                $toplevel[] = &$comment;
                $count_toplevel +=1;
                $tree[$comment->comment_ID] = &$comment;
            } else if ($comment->comment_parent != '0') {
                // if comment is not root, then we will find it's parent
                if (isset($tree[$comment->comment_parent])) {
                    if(!isset($tree[$comment->comment_parent]->children)) {
                        $tree[$comment->comment_parent]->children = array();
                    }
                    $tree[$comment->comment_ID] = &$comment;
                    $tree[$comment->comment_parent]->children[] = &$comment;
                } else {
                    // leave orphans alone, add to toplevel or attempt to find parents later?
                    $orphans[] = &$comment;
                }
            }
            unset($comment);
        }

        // $toplevel = array_reverse($toplevel);
        if (count($toplevel)>$comments_per_page) {
            $toplevel = array_slice($toplevel, -1*$comments_per_page);
            $last_comment = $toplevel[0]->comment_ID;
        } 


        for ($i=0; $i<$comments_per_page; $i++) {
            if(isset($toplevel[$i])) {
                RTC_helper::add_children($toplevel[$i], $toplevel);
            }
        }
        // var_dump($tree);
        // var_dump($toplevel);

        $return = new stdClass;
        $return->comments = $toplevel;
        $return->last_comment = $last_comment;

        return $return;
    }


}
