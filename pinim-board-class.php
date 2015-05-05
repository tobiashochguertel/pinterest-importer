<?php

if(!class_exists('WP_List_Table')){
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Pinim_Board{
    
    var $board_id;
    var $options;
    var $data;
    var $pins;
    
    
    function __construct($board_id){
        $this->board_id = $board_id;
        
    }
    
    function get_options($key = null){
        
        $boards_options = pinim_get_boards_options();
        
        //keep only our board
        $matching_boards = array_filter(
            $boards_options,
            function ($e) {
                return $e['id'] == $this->board_id;
            }
        );  
        
        $board_keys = array_values($matching_boards);
        $board_options = array_shift($board_keys);

        if (!isset($key)) return $board_options;
        if (!isset($board_options[$key])) return false;
        return $board_options[$key];

    }
    
    function get_categories(){
        
        $cats_ids = array();

        if (!$cats_ids = $this->get_options('categories')){
            $root_term_id = pinim_get_root_category_id();
            
            $cat_name = $this->get_datas('name');
            if ($this->board_id=='likes'){
                $cat_name = pinim()->likes_term_name;
            }

            $board_term = pinim_get_term_id($cat_name,'category',array('parent' => $root_term_id));
            if ( is_wp_error($board_term)) return $board_term;
            
            $cats_ids[] = $board_term['term_id'];
        }

        return (array)$cats_ids;

    }
    
    function set_options($input){
        
        //TO FIX have defaults ?

        //sanitize
        
        $new_input = array(
            'id'    => $input['id']
        );
        
        //included
        if( isset( $input['included'] )  ){
            $new_input['included'] = $input['included']; 
        }else{
            $new_input['included'] = 'off';
        }
        
        //custom category
        if ( isset($input['categories']) && ($input['categories']=='custom') && isset($input['category_custom']) && get_term_by('id', $input['category_custom'], 'category') ){ //custom cat
                $new_input['categories'] = $input['category_custom'];
        }else{
            $input['categories']=='auto';
        }

        //privacy
        if( isset( $input['private'] )  ){
            $new_input['private'] = $input['private']; 
        }else{
            $new_input['private'] = 'off';
        }
        
        $boards_settings = pinim_get_boards_options();
        array_unshift($boards_settings, $new_input); //add at the start
        
        //remove duplicates
        $parse_boards = array_filter(
            $boards_settings,
                function ( $board ) {
                  static $idlist = array();
                  
                  if ( in_array( $board['id'], $idlist ) ){
                      return false;
                  }

                  $idlist[] = $board['id'];
                  return true;    
                }
        );
        
        $parse_boards = array_filter($parse_boards); //reset keys

        if ($success = update_user_meta( get_current_user_id(), 'pinim_boards_settings', $parse_boards)){
            return $boards_settings;
        }else{
            return new WP_Error( 'pinim', sprintf(__( 'Error while saving settings for board#%1$s', 'pinim' ),$board_id));
        }

    }
    
    /*
     * Get data from Pinterest
     */
    
    function get_datas($key = null){
        $boards_datas = pinim_get_boards_data();

        //keep only our board
        $matching_boards = array_filter(
            $boards_datas,
            function ($e) {
                return $e['id'] == $this->board_id;
            }
        );  

        $board_keys = array_values($matching_boards);
        $board_datas = array_shift($board_keys);
        
        if (!$board_datas){
            return new WP_Error( 'get_datas_board_'.$this->board_id, sprintf(__( 'Unable to load datas for board #%1$s', 'wordpress-importer' ),$this->board_id));
        }

        if (!isset($key)) return $board_datas;
        if (!isset($board_datas[$key])) return false;
        return $board_datas[$key];
    }
    
    function get_pins_queue(){
        $all_queues = (array)pinim()->get_session_data('queues');
        if (isset($all_queues[$this->board_id])){
            return $all_queues[$this->board_id];
        }
        
    }
    
    function is_queue_complete(){
        $count = count( $this->get_cached_pins() );
        if ($count  < $this->get_datas('pin_count')) return false;
        return true;
    }
    
    function is_fully_imported(){
        $cached_pins = $this->get_cached_pins();
        $imported_pins_ids = pinim_tool_page()->existing_pin_ids;
        
        foreach($cached_pins as $pin){
            if (!in_array($pin['id'],$imported_pins_ids)) return false;
        }
        
        return true;
        
    }
    
    /*
     * Append new pins to the board.
     */
    
    function set_pins_queue($queue){
        $existing_pins = array();
        
        $all_queues = (array)pinim()->get_session_data('queues');
        
        if(isset($all_queues[$this->board_id]['pins'])){
            $existing_pins = $all_queues[$this->board_id]['pins'];
        }
        
        $queue = array(
            'pins'      => array_merge($existing_pins,$queue['pins']),
            'bookmark'  => $queue['bookmark']
        );

        $all_queues[$this->board_id] = $queue;
        
        return pinim()->save_session_data('queues',$all_queues);
    }
    
    function reset_pins_queue(){
        $all_queues = (array)pinim()->get_session_data('queues');
        unset($all_queues[$this->board_id]);
        return pinim()->save_session_data('queues',$all_queues);
    }
    
    function get_cached_pins(){
        return get_all_cached_pins($this->board_id);
    }
    
    /**
     * $cache = 'auto', 'disabled', 'only'
     * @param type $cache
     * @return \WP_Error
     */
    
    function get_pins($reset = false){
        
        if (!isset($this->pins)){
            $pins = array();
            $board_queue = $this->get_pins_queue();
            $bookmark = null;
            
            if (isset($board_queue['bookmark'])){ //uncomplete queue
                $bookmark = $board_queue['bookmark'];
                $reset = false; //do not reset queue, it is not filled yet
            }
            

            if ( $reset || !$board_queue || $bookmark ){
                
                if ($reset){
                    $this->reset_pins_queue();
                }
                
                $login = pinim()->pinterest_do_login();

                $board_url = $this->get_datas('url');
                $board_queue = pinim()->Pinterest->get_all_board_pins_custom($this->board_id,$board_url,$bookmark);

                if (is_wp_error($board_queue)){
                    
                    $error = $board_queue;
                    
                    //check if we have an incomplete queue
                    $error_code = $error->get_error_code();
                    $board_queue = $error->get_error_data($error_code);
                    $this->set_pins_queue($board_queue);
                }

                $this->set_pins_queue($board_queue);
            }

            $board_pins = $board_queue['pins'];
            foreach ((array)$board_pins as $pin_raw){
                $this->pins[] = new Pinim_Pin($pin_raw['id']);
            }
        }
        
        return $this->pins;
        
    }
    
    function get_link_action_cache(){
        //Refresh cache
        $link_args = array(
            'step'      => 1,
            'action'    => 'boards_cache_pins',
            'board_ids'  => $this->board_id,
            'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Refresh pins cache','pinim')

        );

        return $link;
    }
    
    
    function get_link_action_import($board_id = null){
        //Refresh cache
        $link_args = array(
            'step'      => 1,
            'action'    => 'boards_import_pins',
            'board_ids'  => $this->board_id,
            'pin_status'    => 'pending',
            'paged'     => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Import pins','pinim')

        );

        return $link;
    }
    
    function get_link_action_update($board_id = null){
        //Refresh cache
        $link_args = array(
            'step'          => 1,
            'action'        => 'boards_import_pins',
            'board_ids'     => $this->board_id,
            'pin_status'    => 'processed',
            'paged'         => ( isset($_REQUEST['paged']) ? $_REQUEST['paged'] : null),
        );

        $link = sprintf(
            '<a href="%1$s">%2$s</a>',
            pinim_get_tool_page_url($link_args),
            __('Update pins','pinim')

        );

        return $link;
    }
    
    function get_remote_url(){
        $url = pinim()->pinterest_url.$this->get_datas('url');
        return $url;
    }
    
}

class Pinim_Boards_Table extends WP_List_Table {
    
    var $input_data = array();

    /** ************************************************************************
     * REQUIRED. Set up a constructor that references the parent constructor. We 
     * use the parent reference to set some default configs.
     ***************************************************************************/
    function __construct($data){
        global $status, $page;
        
        $this->input_data = $data;

        //Set parent defaults
        parent::__construct( array(
            'singular'  => __('board','pinim'),     //singular name of the listed records
            'plural'    => __('boards','pinim'),    //plural name of the listed records
            'ajax'      => true        //does this table support ajax?
        ) );
        
    }


    /** ************************************************************************
     * Recommended. This method is called when the parent class can't find a method
     * specifically build for a given column. Generally, it's recommended to include
     * one method for each column you want to render, keeping your package class
     * neat and organized. For example, if the class needs to process a column
     * named 'title', it would first see if a method named $this->column_title() 
     * exists - if it does, that method will be used. If it doesn't, this one will
     * be used. Generally, you should try to use custom column methods as much as 
     * possible. 
     * 
     * Since we have defined a column_title() method later on, this method doesn't
     * need to concern itself with any column with a name of 'title'. Instead, it
     * needs to handle everything else.
     * 
     * For more detailed insight into how columns are handled, take a look at 
     * WP_List_Table::single_row_columns()
     * 
     * @param array $item A singular item (one full row's worth of data)
     * @param array $column_name The name/slug of the column to be processed
     * @return string Text or HTML to be placed inside the column <td>
     **************************************************************************/
    function column_default($item, $column_name){
        switch($column_name){
            case 'thumbnail':
            case 'category':
                return $item[$column_name];
            default:
                return print_r($item,true); //Show the whole array for troubleshooting purposes
        }
    }


    /** ************************************************************************
     * Recommended. This is a custom column method and is responsible for what
     * is rendered in any column with a name/slug of 'title'. Every time the class
     * needs to render a column, it first looks for a method named 
     * column_{$column_title} - if it exists, that method is run. If it doesn't
     * exist, column_default() is called instead.
     * 
     * This example also illustrates how to implement rollover actions. Actions
     * should be an associative array formatted as 'slug'=>'link html' - and you
     * will need to generate the URLs yourself. You could even ensure the links
     * 
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/

    function column_title($board){


        //Build row actions
        $actions = array(
            'single_board_cache_pins'     => $board->get_link_action_cache(),
            'view'                        => sprintf('<a href="%1$s" target="_blank">%2$s</a>',$board->get_remote_url(),__('View on Pinterest','pinim'),'view'),
        );
        
        //import link
        if (pinim_get_screen_boards_import_status()=='completed'){
            $actions['single_board_update_pins']    = $board->get_link_action_update();
        }else{
            $actions['single_board_import_pins']    = $board->get_link_action_import();
        }
        
        
        
        //Return the title contents
        return sprintf('%1$s <span style="color:silver">(id:%2$s)</span>%3$s',
            /*$1%s*/ $board->get_datas('name'),
            /*$2%s*/ $board->board_id,
            /*$3%s*/ $this->row_actions($actions)
        );
    }


    /** ************************************************************************
     * REQUIRED if displaying checkboxes or using bulk actions! The 'cb' column
     * is given special treatment when columns are processed. It ALWAYS needs to
     * have it's own method.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @param array $item A singular item (one full row's worth of data)
     * @return string Text to be placed inside the column <td> (movie title only)
     **************************************************************************/
    function column_cb($board){
        $hidden = sprintf('<input type="hidden" name="%1$s[boards][%2$s][id]" value="%2$s" />',
            'pinim_tool',
            $board->board_id
        );
        $bulk = sprintf(
            '<input type="checkbox" name="%1$s[boards][%2$s][bulk]" value="on" />',
            'pinim_tool',
            $board->board_id
        );
        return $hidden.$bulk;
    }
    
    
    function column_private($board){

        //privacy
        $secret_checked_str = checked(true, false, false );
        $is_secret_board = ($board->get_datas('privacy')=='secret');

        if ($private = $board->get_options('private')){
            $secret_checked_str = checked($private, 'on', false );
        }else{
            $secret_checked_str = checked($is_secret_board, true, false );
        }
        
        return sprintf(
            '<input type="checkbox" name="%1$s[boards][%2$s][private]" value="on" %3$s/>',
            'pinim_tool',
            $board->board_id,
            $secret_checked_str
        );
        
    }
    
    function column_thumbnail($board){
        if ( !$images = $board->get_datas('cover_images') ) return;
        $image_key = array_values($images);
        $image = array_shift($image_key);
        return sprintf(
            '<img src="%1$s" />',
            $image['url']
        );
    }
    
    function column_category($board){
        
        $root_cat = pinim_get_root_category_id();

        if ( !$selected_cat = $board->get_options('categories') ){
            $selected_cat = $root_cat;
        }
        
        $is_auto_cat = ($selected_cat == $root_cat);

        $checked_auto_str = checked($is_auto_cat, true, false );
        
        $category_auto = sprintf(
            '<input type="radio" name="%1$s[boards][%2$s][categories]" value="auto" %3$s/>%4$s',
            'pinim_tool',
            $board->board_id,
            $checked_auto_str,
            __('auto','pinim')
        );
        
        $cat_args = array(
            'hide_empty'    => false,
            'depth'         => 20, //TO FIX better value here ?
            'hierarchical'  => 1,
            'echo'          => false,
            'selected'      => $selected_cat,
            'name'          => sprintf('%1$s[boards][%2$s][category_custom]','pinim_tool',$board->board_id)
        );

        $checked_custom_str = checked($is_auto_cat, false, false );
        $custom_cats = wp_dropdown_categories( $cat_args );
        
        $category_custom = sprintf(
            '<input type="radio" name="%1$s[boards][%2$s][categories]" value="custom" %3$s/>%4$s',
            'pinim_tool',
            $board->board_id,
            $checked_custom_str,
            __('custom','pinim')
        );
        
        return "<span>".$category_auto."</span><span>".$category_custom."</span><br/><span>".$custom_cats."</span>";
        
    }
    
    function column_pin_count($board){
        return $board->get_datas('pin_count');
    }
    
    function column_pin_count_cached($board){
        
        $percent = 0;

        $count = count( $board->get_cached_pins() ); //check queue, do not populate pins
        if ($total_pins  = $board->get_datas('pin_count')){
            $percent = $count / $total_pins * 100;
        }
        $count_str = $count;
        if ($percent>=100){
            $count_str = '<strong>'.$count_str.'</strong>';
        }

        return sprintf(
            '<span class="board-cached-count">%1$s</span> <span class="board-cached-pc" data-cached-pc="%2$s" style="color:silver">(%2$s%%)</span>',
            $count_str,
            round($percent)
                
        );
        
    }
    
    function column_pin_count_imported($board){
        
        $percent = 0;
        $imported = 0;
        $cached_pins = $board->get_cached_pins();
        
        foreach ((array)$cached_pins as $raw_pin){
            if (in_array($raw_pin['id'],pinim_tool_page()->existing_pin_ids)) $imported++;
        }
        
        if ($total_pins  = $board->get_datas('pin_count')){
            $percent = $imported / $total_pins * 100;
        }
        $imported_str = $imported;
        if ($percent>=100){
            $imported_str = '<strong>'.$imported_str.'</strong>';
        }

        return sprintf(
            '<span class="board-imported-count">%1$s</span> <span class="board-imported-pc" data-cached-pc="%2$s" style="color:silver">(%2$s%%)</span>',
            $imported_str,
            round($percent)
                
        );
        
    }


    /** ************************************************************************
     * REQUIRED! This method dictates the table's columns and titles. This should
     * return an array where the key is the column slug (and class) and the value 
     * is the column's title text. If you need a checkbox for bulk actions, refer
     * to the $columns array below.
     * 
     * The 'cb' column is treated differently than the rest. If including a checkbox
     * column in your table you must create a column_cb() method. If you don't need
     * bulk actions or checkboxes, simply leave the 'cb' entry out of your array.
     * 
     * @see WP_List_Table::::single_row_columns()
     * @return array An associative array containing column information: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_columns(){
        $columns = array(
            'cb'        => '<input type="checkbox" />', //Render a checkbox instead of text
            'thumbnail'    => '',
            'title'     => __('Board Title','pinim'),
            'category'  => __('Map Category','pinim'),
            'pin_count'    => __('Pins count','pinim'),
            'pin_count_cached'    => __('Pins cached','pinim'),
            'pin_count_imported'    => __('Pins Imported','pinim'),
            'private'    => __('Private','pinim')
        );
        return $columns;
    }


    /** ************************************************************************
     * Optional. If you want one or more columns to be sortable (ASC/DESC toggle), 
     * you will need to register it here. This should return an array where the 
     * key is the column that needs to be sortable, and the value is db column to 
     * sort by. Often, the key and value will be the same, but this is not always
     * the case (as the value is a column name from the database, not the list table).
     * 
     * This method merely defines which columns should be sortable and makes them
     * clickable - it does not handle the actual sorting. You still need to detect
     * the ORDERBY and ORDER querystring variables within prepare_items() and sort
     * your data accordingly (usually by modifying your query).
     * 
     * @return array An associative array containing all the columns that should be sortable: 'slugs'=>array('data_values',bool)
     **************************************************************************/
    function get_sortable_columns() {
        $sortable_columns = array(
            'title'     => array('title',false),     //true means it's already sorted
            'pin_count'    => array('title',false),
            'pin_count_cached'    => array('title',false),
        );
        return $sortable_columns;
    }
    
    /**
     * @param string $which
     */
    protected function extra_tablenav( $which ) {
        ?>
        <div class="alignleft actions">
        <?php
        if ( 'top' == $which && !is_singular() ) {
            
            $requested_status = pinim_get_screen_boards_import_status();
            
                //submit_button( __( 'Update all boards settings','pinim' ), 'button', 'filter_action', false );
                
                if ($requested_status=='cached'){
                    submit_button( __( 'Import all boards pins','pinim' ), 'button', 'filter_action', false );
                }elseif ($requested_status=='pending'){
                    submit_button( __( 'Cache all boards pins','pinim' ), 'button', 'filter_action', false );
                }

        }

        ?>
        </div>
        <?php
    }
    
	/**
	 * Get an associative array ( id => link ) with the list
	 * of views available on this table.
	 *
	 * @since 3.1.0
	 * @access protected
	 *
	 * @return array
	 */
	protected function get_views() {
            
            $link_args = array(
                'step'          => 1,   
            );
            
            $link_waiting_args = $link_args;
            $link_waiting_args['board_status'] = 'waiting';
            $link_waiting_classes = array();
            $waiting_count = 0;
            
            $link_pending_args = $link_args;
            $link_pending_args['board_status'] = 'pending';
            $link_pending_classes = array();
            $pending_count = 0;
            
            $link_completed_args = $link_args;
            $link_completed_args['board_status'] = 'completed';
            $link_completed_classes = array();
            $completed_count = 0;
            
            $boards_data = pinim_get_boards_data();

            foreach((array)$boards_data as $board_data){
                $board = new Pinim_Board($board_data['id']);
                if ($board->is_queue_complete()){
                    $pending_count++;
                }
                if ($board->is_fully_imported()){
                    $completed_count++;
                }
                
            }
            
            
            $waiting_count = count($boards_data) - $pending_count;
            $pending_count -= $completed_count;
            //
            
            $requested_status = pinim_get_screen_boards_import_status();
            
            if ( $requested_status=='pending' ){
                $link_pending_classes[] = 'current';
            }
            if ( $requested_status=='waiting' ){
                $link_waiting_classes[] = 'current';
            }
            if ( $requested_status=='completed' ){
                $link_completed_classes[] = 'current';
            }
            
            $link_waiting = sprintf(
                __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
                pinim_get_tool_page_url($link_waiting_args),
                pinim_get_classes($link_waiting_classes),
                __('Needs cache refresh','pinim'),
                $waiting_count
            );
            
            $link_pending = sprintf(
                __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
                pinim_get_tool_page_url($link_pending_args),
                pinim_get_classes($link_pending_classes),
                __('Pending','pinim'),
                $pending_count
            );
            
            $link_completed = sprintf(
                __('<a href="%1$s"%2$s>%3$s <span class="count">(<span class="imported-count">%4$s</span>)</span></a>'),
                pinim_get_tool_page_url($link_completed_args),
                pinim_get_classes($link_completed_classes),
                __('Completed','pinim'),
                $completed_count
            );


		return array(
                    'waiting'       => $link_waiting,
                    'pending'        => $link_pending,
                    'completed'     => $link_completed
                    
                    
                );
	}

	/**
	 * Display the list of views available on this table.
	 *
	 * @since 3.1.0
	 * @access public
	 */
	public function views() {
		$views = $this->get_views();

		if ( empty( $views ) )
			return;

		echo "<ul class='subsubsub'>\n";
		foreach ( $views as $class => $view ) {
			$views[ $class ] = "\t<li class='$class'>$view";
		}
		echo implode( " |</li>\n", $views ) . "</li>\n";
		echo "</ul>";
	}


    /** ************************************************************************
     * Optional. If you need to include bulk actions in your list table, this is
     * the place to define them. Bulk actions are an associative array in the format
     * 'slug'=>'Visible Title'
     * 
     * If this method returns an empty value, no bulk action will be rendered. If
     * you specify any bulk actions, the bulk actions box will be rendered with
     * the table automatically on display().
     * 
     * Also note that list tables are not automatically wrapped in <form> elements,
     * so you will need to create those manually in order for bulk actions to function.
     * 
     * @return array An associative array containing all the bulk actions: 'slugs'=>'Visible Titles'
     **************************************************************************/
    function get_bulk_actions() {
        $actions = array(
            'boards_update_settings'    => __('Update Board Settings','pinim'),
            'boards_cache_pins'    => __('Cache Pins','pinim'),
            'boards_import_pins'    => __('Import Pins','pinim')
        );
        return $actions;
    }


    /** ************************************************************************
     * Optional. You can handle your bulk actions anywhere or anyhow you prefer.
     * For this example package, we will handle it in the class to keep things
     * clean and organized.
     * 
     * @see $this->prepare_items()
     **************************************************************************/
    function process_bulk_action() {
        

        
    }


    /** ************************************************************************
     * REQUIRED! This is where you prepare your data for display. This method will
     * usually be used to query the database, sort and filter the data, and generally
     * get it ready to be displayed. At a minimum, we should set $this->items and
     * $this->set_pagination_args(), although the following properties and methods
     * are frequently interacted with here...
     * 
     * @global WPDB $wpdb
     * @uses $this->_column_headers
     * @uses $this->items
     * @uses $this->get_columns()
     * @uses $this->get_sortable_columns()
     * @uses $this->get_pagenum()
     * @uses $this->set_pagination_args()
     **************************************************************************/
    function prepare_items() {
        global $wpdb; //This is used only if making any database queries

        /**
         * First, lets decide how many records per page to show
         */
        $per_page = pinim()->get_options('boards_per_page');
        
        
        /**
         * REQUIRED. Now we need to define our column headers. This includes a complete
         * array of columns to be displayed (slugs & titles), a list of columns
         * to keep hidden, and a list of columns that are sortable. Each of these
         * can be defined in another method (as we've done here) before being
         * used to build the value for our _column_headers property.
         */
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        
        
        /**
         * REQUIRED. Finally, we build an array to be used by the class for column 
         * headers. The $this->_column_headers property takes an array which contains
         * 3 other arrays. One for all columns, one for hidden columns, and one
         * for sortable columns.
         */
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        
        /**
         * Optional. You can handle your bulk actions however you see fit. In this
         * case, we'll handle them within our package just to keep things clean.
         */
        $this->process_bulk_action();
        
        
        /**
         * Instead of querying a database, we're going to fetch the example data
         * property we created for use in this plugin. This makes this example 
         * package slightly different than one you might build on your own. In 
         * this example, we'll be using array manipulation to sort and paginate 
         * our data. In a real-world implementation, you will probably want to 
         * use sort and pagination data to build a custom query instead, as you'll
         * be able to use your precisely-queried data immediately.
         */
        $data = $this->input_data;   
        
        /**
         * This checks for sorting input and sorts the data in our array accordingly.
         * 
         * In a real-world situation involving a database, you would probably want 
         * to handle sorting by passing the 'orderby' and 'order' values directly 
         * to a custom query. The returned data will be pre-sorted, and this array
         * sorting technique would be unnecessary.
         */
        function usort_reorder($a,$b){

            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'id'; //If no sort, default to title
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'asc'; //If no order, default to asc
            $result = strcmp($a->get_datas('orderby'), $a->get_datas('orderby')); //Determine sort order
            return ($order==='asc') ? $result : -$result; //Send final sort direction to usort
        }
        usort($data, 'usort_reorder');
        
        
        /***********************************************************************
         * ---------------------------------------------------------------------
         * vvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvvv
         * 
         * In a real-world situation, this is where you would place your query.
         *
         * For information on making queries in WordPress, see this Codex entry:
         * http://codex.wordpress.org/Class_Reference/wpdb
         * 
         * ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
         * ---------------------------------------------------------------------
         **********************************************************************/
        
                
        /**
         * REQUIRED for pagination. Let's figure out what page the user is currently 
         * looking at. We'll need this later, so you should always include it in 
         * your own package classes.
         */
        $current_page = $this->get_pagenum();
        
        /**
         * REQUIRED for pagination. Let's check how many items are in our data array. 
         * In real-world use, this would be the total number of items in your database, 
         * without filtering. We'll need this later, so you should always include it 
         * in your own package classes.
         */
        $total_items = count($data);
        
        
        /**
         * The WP_List_Table class does not handle pagination for us, so we need
         * to ensure that the data is trimmed to only the current page. We can use
         * array_slice() to 
         */
        $data = array_slice($data,(($current_page-1)*$per_page),$per_page);
        
        
        
        /**
         * REQUIRED. Now we can add our *sorted* data to the items property, where 
         * it can be used by the rest of the class.
         */
        $this->items = $data;
        
        
        /**
         * REQUIRED. We also have to register our pagination options & calculations.
         */
        $this->set_pagination_args( array(
            'total_items' => $total_items,                  //WE have to calculate the total number of items
            'per_page'    => $per_page,                     //WE have to determine how many items to show on a page
            'total_pages' => ceil($total_items/$per_page)   //WE have to calculate the total number of pages
        ) );
    }


}
