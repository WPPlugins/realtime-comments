<?php
/**
 * Plugin Name: Realtime Comments
 * New accepted comments from all users are updated in pages real-time, without the need to refresh the page. Allows comments section work interactively, like a chatroom. Pure WP plugin, no third party involvement, no account registration or third party application needed. Comments re-classified as trash, spam, or unapproved will be dynamically removed from users screen. 
 * Version: 0.8
 * Author: Eero Hermlin
 * Author URI: http://eero.hermlin.era.ee/
 * Requires at least: 3.0
 * Tested up to: 4.4.2
 * License: GPLv2
 */

if(!defined('REALTIMECOMMENTS_VERSION')) {
    define('REALTIMECOMMENTS_VERSION', '0.8');
}

if(!defined('ABSPATH')) {
  die('You are not allowed to call this page directly.');
}

require_once('rtc-page-selector-walker.class.php');
require_once('helper.php');


class RealTimeComments {
    private $refresh = 2000;
    private $anim = 1;
    private $order = false; // {'asc'|'desc'|false}
    private $now = 0;
    private $old_wp = false;
    private $mode = 'all'; // {'all'|'new'}
    private $last_comment = 0;


    private $post_types = array();
    private $selected_pages = array();
    private $max_c_id = 0;

    private $comment_style = 'ol';
    private $comment_walker = '';
    private $avatar_size = '';

    private $comment_list_el = '#comments';
    private $comment_list_tag = 'ol';
    private $comment_list_class = 'comment-list';
    private $comment_tag = 'li';
    private $comment_id_prefix = '#comment-';
    private $children_class = 'children';
    private $tambov = '0';
    private $advanced_user = null;
    private $debuginfo = null;
    private $values;
    private $dyn_next = null;

    private $refresh_options=array(
        500  => '0.5',
        1000 => '1.0',
        1500 => '1.5',
        2000 => '2',
        3000 => '3',
        5000 => '5',
        10000=> '10',
        30000=> '30',
        60000=> '60',
        );

    private $order_options = array(       // get_option('comment_order') {asc|desc}
        '' => 'as general setting',
        'asc' => 'to bottom',
        'desc' => 'to top',
    );

    private $avatar_size_options = array (
        '' => '',
        '34' => '34px (Twenty Fourteen)',
        '40' => '40px (Twenty Ten)',
        '44' => '44px (Twenty Twelve)',
        '50' => '50px',
        '56' => '56px',
        '62' => '62px',
        '68' => '68px (Twenty Eleven)',
        '74' => '74px (Twenty Thirteen)',
    );

    private $comment_style_options = array (
        'ol' => 'ol',
        'ul' => 'ul',
        'div' => 'div'
    );

    private $comment_walker_options = array (
        '' => '',
        'twentyten_comment' => 'twentyten_comment',
        'twentyeleven_comment' => 'twentyeleven_comment',
        'twentytwelve_comment' => 'twentytwelve_comment', 
    );


    public function __construct() {
        global $wp_version, $post;
        $this->now=time();

        register_activation_hook( __FILE__, array( $this, 'install' ) );
        register_deactivation_hook( __FILE__, array( $this, 'uninstall' ) );

        add_action( 'wp_set_comment_status', array($this, 'update_last_modified') );
        add_action( 'wp_insert_comment', array($this, 'update_last_modified') );
        add_action( 'edit_comment', array($this, 'update_last_modified') );
        add_action( 'switch_theme', array($this, 'comment_walker_update'), 100, 1);
        add_filter( 'wp_list_comments_args', array($this, 'reverse_comments'));

        // comments_array filter does not work properly on 4.4+?
        add_filter( 'comments_array', array($this, 'show_last_comments_only'));
        add_action( 'wp_enqueue_scripts', array($this, 'enqueue_script') );
        add_action( 'admin_enqueue_scripts', array($this, 'enqueue_admin_style_n_script') );
        add_action( 'wp_footer', array($this, 'wp_footer'));

        if(is_admin()) {
            // implement admin screen updates
            if(version_compare($wp_version, '3.0', '<')) {
                add_action( 'admin_notices', array($this, 'wp_version_error'));
            }

            add_action( 'admin_menu', array($this, 'admin_page')); 
            add_action( 'admin_init', array($this, 'register_and_build_fields')); 
            add_filter( 'plugin_action_links_'.plugin_basename(__FILE__), array($this, 'plugin_settings_link') );
        } 

        $default_args = array();

        $values=get_option('rtc-settings', $default_args); // use default_args!!
        $this->values = $values;

        if(is_array($values)) {
            $this->overwrite_defaults($values, array(
                'refresh', 
                'order', 
                'avatar_size', 
                'comment_style', 
                'comment_walker',
                'comment_list_el', 
                'comment_list_tag',
                'comment_list_class',
                'comment_tag',
                'comment_id_prefix',
                'children_class',
                'tambov',
                ));
            if (isset($values['advanced_user'])) $this->advanced_user = '1';
            if (isset($values['debuginfo'])) $this->debuginfo = '1';
            if (isset($values['selected_pages']) && is_array($values['selected_pages'])) $this->selected_pages = $values['selected_pages'];
            if (isset($values['post_types']) && is_array($values['post_types'])) $this->post_types = $values['post_types'];
            if (isset($values['dyn_next'])) $this->dyn_next = '1';
        } 


        // Comments per page: get_option('comments_per_page') (counting top-level comments)
        // Comments order: get_option('comment_order') {asc|desc}
        // pagination links https://codex.wordpress.org/Template_Tags/paginate_comments_links
        // 
    }

    /* 
    ========================================================================== 

                           S E T U P     F U N C T I O N S

    ========================================================================== 
    */


    public function reverse_comments($args) {
        // wp-includes/comment-template.php function wp_list_comments() line 2045
        global $post;
        if ($this->order && ($this->order != get_option('comment_order'))) { 
            if (
             (isset($post->ID) && in_array(get_post_type($post->ID), array_keys($this->post_types))) ||
             (isset($post->ID) && in_array($post->ID, $this->selected_pages))
            ) {
                $args['reverse_top_level'] = true;
            }
        }
        if ($this->dyn_next) {
            $args['page'] = 1; // override WP pagination logic        
        }
        return $args;
    }

    public function show_last_comments_only($comments) {
        // wp-includes/comment-template.php function comments_template() line 1185
        // this function disables "normal" pagination and replaces it with ajax loading

        if ($this->dyn_next) {
            // if dynamic pagination then will show always newest comments
            $cpage = get_query_var('cpage');
            $comments_per_page = get_option('comments_per_page');
            
            $commentsobject = RTC_helper::get_next_comments_page($comments, 999999999999, $comments_per_page);
            $this->last_comment = $commentsobject->last_comment;
            return $commentsobject->comments;
        }
        return $comments;
    }

    private function overwrite_defaults($values, $keys) {
        foreach($keys as $key) {
            if(isset($values[$key]) && isset($this->$key) && $values[$key]!=$this->$key) {
                $this->$key = $values[$key];
            }
        }
    }

    /* 
    ========================================================================== 

                           A D M I N     F U N C T I O N S

    ========================================================================== 
    */

    // Add settings link on plugin page
    function plugin_settings_link($links) { 
      $settings_link = '<a href="admin.php?page=rtc_admin_menu">'.__('Settings').'</a>'; 
      array_push($links, $settings_link); 
      return $links; 
    }    
    
    public function admin_page() { 
        // add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
        add_menu_page( 'Realtime Comments', 'Realtime Comments', 'administrator', 'rtc_admin_menu', array($this, 'create_menu_page'), '', '25.000154');
        // add_menu_page( 'Debug', 'Debug', 'edit_plugins', 'rtc_admin_debug', array($this, 'create_debug_page'), '', '25.000155');

    }

    public function create_menu_page() {
        ?> 
        <div id="wp_plugin_realtime_comments"> 
        <h2>Realtime Comments</h2>
            <form method="post" action="options.php" enctype="multipart/form-data"> 
            <?php settings_fields('rtc_settings'); ?> 
            <ul id="ee_tabs">
                <li class="rtc_menu_basic selected" onClick="$EE.showTab(this, 'rtc_menu_basic')"><?php _e('Main settings', 'realtime-comments') ?></li>
                <li class="rtc_menu_advanced" onClick="$EE.showTab(this, 'rtc_menu_advanced')"><?php _e('Advanced settings', 'realtime-comments') ?></li>
                <li class="rtc_menu_developer" onClick="$EE.showTab(this, 'rtc_menu_developer')"><?php _e('Developer', 'realtime-comments') ?></li>
            </ul>
            <div class="ee_tab_container">
            <div class="ee_tab_content rtc_menu_basic">
            <?php do_settings_sections('rtc_menu'); ?> 
            </div>
            <div class="ee_tab_content rtc_menu_advanced hide">
            <?php do_settings_sections('rtc_menu_adv'); ?> 
            </div>
            <div class="ee_tab_content rtc_menu_developer hide">
            <?php do_settings_sections('rtc_menu_dev'); ?> 
            </div>
            </div>
            <p class="submit"> <input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save'); ?>" /> </p> 
            </form> 
        </div> 
        <?php     
    }

    public function create_debug_page() {
        ?>
        <p> Your current theme is: <?=get_option('template') ?></p>

        <?php
    }

    function register_and_build_fields() { 
        // register_setting( $option_group, $option_name, $sanitize_callback );
        register_setting('rtc_settings', 'rtc-settings', array($this, 'validate_my_option')); 

        /* Main */
        // add_settings_section( $id, $title, $callback, $page );
        add_settings_section('rtc_main_settings', __('Main Settings', 'realtime-comments'), array($this, 'create_rtc_intro'), 'rtc_menu'); 
        add_settings_field('refresh_input', __('Refresh frequency (seconds)', 'realtime-comments'), array($this, 'refresh_input'), 'rtc_menu', 'rtc_main_settings'); 
        add_settings_field('order_input', __('New comments appear', 'realtime-comments'), array($this, 'order_input'), 'rtc_menu', 'rtc_main_settings');

        // Buggy in WP 4.4+
        // add_settings_field('dyn_next', __('Use dynamic "Load next comments" button', 'realtime-comments'), array($this, 'dyn_next'), 'rtc_menu', 'rtc_main_settings');

        add_settings_field('pages_select', __('Use Realtime Comments for', 'realtime-comments'), array($this, 'pages_select'), 'rtc_menu', 'rtc_main_settings');

        /* Advanced */
        add_settings_section('rtc_advanced_settings', __('Advanced Settings', 'realtime-comments'), array($this, 'create_advanced_settings_intro'), 'rtc_menu_adv');
        add_settings_field('comments_walker', __('My theme has custom comments walker function (required for Twenty Ten, Twenty Eleven, Twenty Twelve, and for child themes based on those)', 'realtime-comments'), array($this, 'comment_walker_select'), 'rtc_menu_adv', 'rtc_advanced_settings');
        add_settings_field('avatar_size', __('Avatar size (not important for responsive themes or when custom walker function is filled)', 'realtime-comments'), array($this, 'avatar_size_input'), 'rtc_menu_adv', 'rtc_advanced_settings'); 
        add_settings_field('comments_style', __('Comments style', 'realtime-comments'), array($this, 'comment_style_select'), 'rtc_menu_adv', 'rtc_advanced_settings');

        /* Developer */
        add_settings_section('rtc_developer_settings', __('Developer Settings', 'realtime-comments'), array($this, 'create_developer_settings_intro'), 'rtc_menu_dev');
        add_settings_field('advanced_user', __('Override default values with custom values below', 'realtime-comments'), array($this, 'advanced_user_checkbox'), 'rtc_menu_dev', 'rtc_developer_settings');
        add_settings_field('debuginfo', __('Echo debug info into javascript console', 'realtime-comments'), array($this, 'debuginfo_checkbox'), 'rtc_menu_dev', 'rtc_developer_settings');
        add_settings_field('javascript_engine', __('HTML Container definitions (Change if you have custom walker function)', 'realtime-comments'), array($this, 'comment_list_elements_input'), 'rtc_menu_dev', 'rtc_developer_settings');
    } 

    function create_rtc_intro() {
        echo '<p>'.__('Settings for plugin', 'realtime-comments').'</p>';
        // var_dump($this->values);
    }

    function create_advanced_settings_intro() {
        echo '<p>'.__('Some advanced settings to try if plugin does not work out of the box.', 'realtime-comments').'</p>';
    }

    function create_developer_settings_intro() {
        echo '<p>'.__('If you have custom Theme what is not supported by default, you can set here some even more advanced settings.', 'realtime-comments').'</p>';
    }

    function validate_my_option($input) { 
        // validate entered value
        // will be called together with update_option('rtc_settings', 'value');
        $output=array();
        // var_dump($input);

        $this->basic_validate('refresh', $this->refresh_options, $input, $output);
        $this->basic_validate('order', false, $input, $output);
        $this->basic_validate('avatar_size', false, $input, $output);
        $this->basic_validate('comment_style', false, $input, $output);

        if(isset($input['comment_walker_input']) && $input['comment_walker_input']) {
            // User entry has higher precedence than selection
            $input['comment_walker'] = $input['comment_walker_input'];
        } 
        if (isset($input['comment_walker']) && $input['comment_walker'] && (isset($input['skip']) || function_exists($input['comment_walker']))) {
            $this->basic_validate('comment_walker', false, $input, $output);
        }

        $this->basic_validate('advanced_user', false, $input, $output);
        $this->basic_validate('debuginfo', false, $input, $output);
        $this->basic_validate('comment_list_el', false, $input, $output);
        $this->basic_validate('comment_list_tag', false, $input, $output);
        $this->basic_validate('comment_list_class', false, $input, $output);
        $this->basic_validate('comment_tag', false, $input, $output);
        $this->basic_validate('comment_id_prefix', false, $input, $output);
        $this->basic_validate('children_class', false, $input, $output);
        $this->basic_validate('dyn_next', false, $input, $output);
        $output['tambov'] = intval($input['tambov']);

        if(is_array($input['post_types'])) {
            $output['post_types']=$input['post_types'];
        } else {
            $output['post_types']=array();
        }
        if(is_array($input['selected_pages'])) {
            $output['selected_pages']=$input['selected_pages'];
        } else {
            $output['selected_pages']=array();
        }
        return $output; 
    } 

    private function basic_validate($key, $allowed_values, &$input, &$output) {
        if (isset($input[$key]) && $input[$key]) {
            if (is_array($allowed_values)) {
                if(array_key_exists($input[$key], $allowed_values)) {
                    $output[$key] = $input[$key];
                }
            } else {
                $output[$key] = $input[$key];
            }
        } 
    }

    function refresh_input() { 
        echo $this->create_select('refresh', $this->refresh_options, ''.$this->refresh);
    } 


    function order_input() {
        echo $this->create_select('order', $this->order_options, $this->order);
    }

    function dyn_next() {
        echo $this->create_checkbox('[dyn_next]', isset($this->dyn_next), '', '');
    }

    function pages_select() {
        $disabled_types = array('attachment', 'revision', 'nav_menu_item');
        $post_types = get_post_types(array('public'=>true), 'objects');
        if(is_array($post_types)) {
            foreach ( $post_types as $post_type ) {
                if(!in_array($post_type->name, $disabled_types)) {
                    $id = $post_type->name;
                    $label = __('All ', 'realtime-comments').$post_type->label;
                    echo $this->create_checkbox('[post_types]['.$id.']', isset($this->post_types[$id]), '', $label);
                }
            }
        }
        echo __('and/or on following pages:', 'realtime-comments').'<br>';
        echo '<select name="rtc-settings[selected_pages][]" multiple="multiple" size="8" style="height: 14em">';
        echo Rtc_Page_Selector_Walker::get_pages_selection($this->selected_pages);
        echo '</select>';

    }

    function avatar_size_input() {
        echo $this->create_select('avatar_size', $this->avatar_size_options, $this->avatar_size);
    }

    function comment_walker_select() {
        if($this->comment_walker && !in_array($this->comment_walker, $this->comment_walker_options)) {
            // add user's custom value to the list
            $this->comment_walker_options[$this->comment_walker] = $this->comment_walker;
          
        } 
        echo $this->create_select('comment_walker', $this->comment_walker_options, $this->comment_walker).' '.__('OR enter your own value ', 'realtime-comments');
        echo $this->create_input('comment_walker_input', '', '', '');
    }

    function comment_style_select() {
        echo $this->create_select('comment_style', $this->comment_style_options, $this->comment_style);
    }

    function advanced_user_checkbox() {
        echo $this->create_checkbox('[advanced_user]', isset($this->advanced_user), '', '');
    }

    function debuginfo_checkbox() {
        echo $this->create_checkbox('[debuginfo]', isset($this->debuginfo), '', '');
    }


    function comment_list_elements_input() {
        echo $this->create_input('comment_list_el', $this->comment_list_el, '', __('Comment container element id. Usually: #comments', 'realtime-comments'))."<br />\n";
        echo $this->create_input('comment_list_tag', $this->comment_list_tag, '', __('Comment list element tag. Default: ol'))."<br />\n";
        echo $this->create_input('comment_list_class', $this->comment_list_class, '', __('Comment list element class. Default: comment-list (themes 2013+) or commentlist (themes -2012)', 'realtime-comments'))."<br />\n";
        echo $this->create_input('comment_tag', $this->comment_tag, '', __('Comment item tag. Default: li', 'realtime-comments'))."<br />\n";
        echo $this->create_input('comment_id_prefix', $this->comment_id_prefix, '', __('Comment item id prefix. Default: #comment- (themes 2013+) or #li-comment- (themes -2012)', 'realtime-comments'))."<br />\n";
        echo $this->create_input('children_class', $this->children_class, '', __('Children item class. Usually: children', 'realtime-comments'))."<br />\n";
        echo $this->create_input('tambov', $this->tambov, '', __('Tambov constant. Default: 5', 'realtime-comments'))."<br />\n";
    
    }

    private static function create_checkbox($input_field_name, $checked, $prefix = '', $comment = '') {
        return '<label>'.$prefix.'<input type="checkbox" name="rtc-settings'.$input_field_name.'" value="1" '.checked(true, $checked, false).'>'.$comment.'</label>'."<br />\n";
    }
    
    private static function create_input($input_field_name, $value, $prefix = '', $comment = '') {
        return '<label>'.$prefix.'<input type="text" name="rtc-settings['.$input_field_name.']" value="'.htmlentities($value).'"> '.$comment.'</label>';
    }

    private static function create_select($select_field_name, $all_values, $selected_values, $is_multiple = false) {
        $rv='<select name="rtc-settings['.$select_field_name.']">'."\n";
        foreach($all_values as $key => $label) {
            $rv.='<option value="'.$key.'" ';
            if ((is_array($selected_values) && in_array($key, $selected_values)) ||
                (is_string($selected_values) && $key == $selected_values)) {
                    $rv.=' selected="1"';
            } 
            $rv.='>'.$label.'</option>'."\n";
        }
        $rv.='</select>'."\n";
        return $rv;
    }

    public function enqueue_admin_style_n_script( $hook_suffix ) {
        wp_register_script( 'realtime-comments-plugin-admin', plugins_url('js/adminscript.js', __FILE__ ), array('jquery'), REALTIMECOMMENTS_VERSION, false );
        wp_enqueue_style( 'realtime-comments-plugin', plugins_url( 'css/adminstyle.css', __FILE__ ), array(), REALTIMECOMMENTS_VERSION );  
        wp_enqueue_script( 'realtime-comments-plugin-admin' );
    }



    /* 
    ========================================================================== 

                      F R O N T E N D     F U N C T I O N S

    ========================================================================== 
    */
    public function update_last_modified($comment_id) {
        global $wpdb;
        // update_comment_meta( $comment_id, 'rtc_last_modified', $this->now );
        $comment = get_comment($comment_id);
        if($comment->comment_approved === '1' || $comment->comment_approved === 'approve') {
            $html = RTC_helper::get_comment_html($comment);
            $status = 'I';
        } else {
            $html = $comment->comment_approved;
            $status = 'D';
        }

        $wpdb->query( 
            $wpdb->prepare( 
                'DELETE FROM '.$wpdb->prefix.'rtc_cache WHERE last_modified < %d',
                $this->now-($this->refresh/500) // delete 2 times interval
                )
            );    

        $wpdb->insert($wpdb->prefix.'rtc_cache', array(
            'comment_ID' => $comment_id,
            'comment_parent_ID' => $comment->comment_parent,
            'post_ID' => $comment->comment_post_ID,
            'status' => $status,
            'html' => $html,
            'last_modified' => time())
            ); 
    }

    public function enqueue_script() {
        global $post, $wp_query;

        if (
            (isset($post->ID) && is_array($this->post_types) && in_array(get_post_type($post->ID), array_keys($this->post_types))) ||
            (isset($post->ID) && is_array($this->selected_pages) && in_array($post->ID, $this->selected_pages))
            ) {

            wp_enqueue_style( 'rtc-plugin-core', plugins_url( 'css/style.css', __FILE__ ), array(), REALTIMECOMMENTS_VERSION );  
            wp_register_script( 'rtc-plugin', plugins_url('js/script.js', __FILE__ ), array('jquery'), REALTIMECOMMENTS_VERSION, false );
            wp_enqueue_script( 'rtc-plugin'); 

            $page_comments = get_option('page_comments');
            /*
            $is_last_page = '1';
            if ($page_comments) {
                $current_page = intval(get_query_var('cpage'));
                if ( empty($max_page) )
                    $max_page = $wp_query->max_num_comment_pages;
                if ( empty($max_page) )
                    $max_page = get_comment_pages_count();
                
                if ($current_page>0 and $current_page < $max_page) {
                    $is_last_page = '0';
                }
            }
            */

            $data = array(
                'ajaxurl' => plugins_url('ajax.php', __FILE__),
                'nonce' => wp_create_nonce('realtime-comments'),
                'refresh_interval' => $this->refresh,
                'dyn_next' => ($this->dyn_next ? 1 : 0),    // {1|0}
                'bookmark' => $this->now,
                'max_c_id' => $this->get_max_comment_id($post->ID),
                'postid' => $post->ID,
                'order' => ($this->order ? $this->order : get_option('comment_order')),
                'comments_per_page' => ($page_comments ? get_option('comments_per_page') : 0),
                'is_last_page' => '0', 
                'debuginfo' => $this->debuginfo,
                'comment_list_el' => '#comments',
            );

            if (isset($this->debuginfo)) {
                $data['debuginfo'] = 'page_comments:'.$page_comments.'.';
            }

            if (isset($this->advanced_user)) {
                $data['comment_list_el'] = $this->comment_list_el;
                $data['comment_list_tag'] = $this->comment_list_tag;
                $data['comment_list_class'] = $this->comment_list_class; // 'comment-list',
                $data['comment_tag'] = $this->comment_tag;
                $data['comment_id_prefix'] = $this->comment_id_prefix;
                $data['children_class'] = $this->children_class;
                $data['tambov'] = $this->tambov;
            }
            wp_localize_script('rtc-plugin', '$RTC', $data);
        }
    }

    public function wp_footer() {
        global $wp_query;
        $page_comments = get_option('page_comments');
        $is_last_page = '1';
        if ($page_comments) {
            $current_page = intval(get_query_var('cpage'));
            if ( empty($max_page) )
                $max_page = $wp_query->max_num_comment_pages;
            if ( empty($max_page) )
                $max_page = get_comment_pages_count();
            
            if ($current_page>0 and $current_page < $max_page) {
                $is_last_page = '0';
            }
        }
        // echo '<p>You have '.$max_page.' comment pages and current one is '.$current_page.'</p>';
        ?>
<script type='text/javascript'>
/* <![CDATA[ */
if (typeof $RTC === 'object') { $RTC.is_last_page = '<?php echo $is_last_page; ?>'; $RTC.last_comment = <?php echo $this->last_comment; ?>;}
/* ]]> */
</script>
        <?php
    }


    private function get_max_comment_id($postid) {
        global $wpdb;
            // $comments = $wpdb->get_results( "SELECT max(c.comment_ID) as r FROM $wpdb->comments c WHERE c.comment_post_ID=$postid" );  
            $comments = $wpdb->get_results( "SELECT max(c.comment_ID) as r FROM $wpdb->comments c" );  
            return max(0, $comments[0]->r);
    }

    /* 
    ========================================================================== 

                       S T A T I C     F U N C T I O N S

    ========================================================================== 
    */
    
    public static function comment_walker_discovery($theme, &$values) {
        // use only if $theme is not user input but got by using get_option('template');
        // because we're not 
        $values['skip'] = '1';
        $values['comment_walker'] = '';
        $values['avatar_size'] = '';

        /*
        let's try to discover theme and guess wp_list_comments parameters. There are two kind of approach:
        1) twentyten, twentyeleven and twentytwelve have own walkers
        twentyten: wp_list_comments( array( 'callback' => 'twentyten_comment' )); // $avatar_size = 40;
        twentyeleven: wp_list_comments( array( 'callback' => 'twentyeleven_comment' )); // $avatar_size = 68;
        twentytwelve: wp_list_comments( array( 'callback' => 'twentytwelve_comment', 'style' => 'ol' )); // $avatar_size = 44; ?

        2) twentythirteen, twentyfourteen and twentyfifteen have custom wp_list_comments values
        twentythirteen: wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size' => 74 ));
        twentyfourteen: wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size'=> 34 ));
        twentyfifteen: wp_list_comments( array('style' => 'ol', 'short_ping' => true, 'avatar_size' => 56 ));

        get_template_directory() - Retrieves the absolute path to the directory of the current theme, without the trailing slash.
        get_stylesheet_directory() - Retrieve stylesheet directory Path for the current theme/child theme
        get_template() - Retrieves the directory name of the current theme, without the trailing slash.
        get_option('template') gives 'twentytwelve'
        do_action('switch_theme' ...) gives new name 'Twenty Twelve';
        do_action('after_switch_theme' ... ) gives old name 'Twenty Eleven'
        */

        switch ($theme) {
            case 'twentyten':
            case 'Twenty Ten':
                $values['comment_walker'] = 'twentyten_comment';
                break;
            case 'twentyeleven':
            case 'Twenty Eleven':
                $values['comment_walker'] = 'twentyeleven_comment';
                break;
            case 'twentytwelve':
            case 'Twenty Twelve':
                $values['comment_walker'] = 'twentytwelve_comment';
                break;
            case 'twentythirteen':
            case 'Twenty Thirteen':
                $values['avatar_size'] = '74';
                break;
            case 'twentyfourteen':
            case 'Twenty Fourteen':
                $values['avatar_size'] = '34';
                break;
            case 'twentyfifteen':
            case 'Twenty Fifteen':
                $values['avatar_size'] = '56';
                break;
            default:
                if (function_exists($theme.'_comment')) {
                    $values['comment_walker'] = $theme.'_comment';
                }
                break;
        }
        return true;
    }

    public static function comment_walker_update($theme) {
        $values = get_option('rtc-settings');
        $new_walker = RealTimeComments::comment_walker_discovery($theme, $values);
        update_option('rtc-settings', $values); 
    }


    public static function install() {
        if ( wp_next_scheduled( 'realtimecommentscleanup' ) ) {
            wp_clear_scheduled_hook( 'realtimecommentscleanup' );
        }    
        $values = get_option('rtc-settings');
        $theme = get_option('template');

        if(!isset($values['post_types'])) {
            $values['post_types'] = array('post' => '1', 'page' => '1');
        }
        if(!isset($values['selected_pages'])) {
            $values['selected_pages'] = array();
        }

        RealTimeComments::comment_walker_discovery($theme, $values);

        RealTimeComments::drop_db();
        RealTimeComments::create_db();
        update_option('rtc-settings', $values);
        update_option('rtc-version', REALTIMECOMMENTS_VERSION );
    }

    public static function create_db() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = 'CREATE TABLE '.$wpdb->prefix.'rtc_cache (
          cache_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          comment_ID bigint(20) unsigned NOT NULL,
          comment_parent_ID bigint(20) unsigned NOT NULL DEFAULT 0,
          post_ID bigint(20) unsigned NOT NULL,
          html text NOT NULL,
          status char(1) NOT NULL DEFAULT \'I\',
          last_modified bigint(20) unsigned,
          PRIMARY KEY (cache_ID),
          KEY post_ID (post_ID),
          KEY last_modified (last_modified)
        ) '.$charset_collate.';';
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public static function uninstall() {
        global $wpdb;
        // clean up. delete options and commentmeta
        // delete_option( 'rtc-settings' );
        RealTimeComments::drop_db();
    }

    public static function drop_db() {
        global $wpdb;
        $wpdb->query('DELETE FROM '.$wpdb->commentmeta.' WHERE meta_key = "rtc_last_modified"');
        $wpdb->query('DROP TABLE IF EXISTS '.$wpdb->prefix.'rtc_cache');
    }

    public static function wp_version_error() {
        global $wp_version;
        ?>
        <div class="error below-h2">
        <p>Realtime Comments plugin is tested to be working on Wordpress 3.5 or newer versions. You have <?=$wp_version ?></p>
        </div>
        <?php    
    }
}

new RealTimeComments();