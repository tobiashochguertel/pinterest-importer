<?php
    
class Pinim_Pending_Imports {
    
    /**
    * @var The one true Instance
    */
    private static $instance;

    public static function instance() {
            if ( ! isset( self::$instance ) ) {
                    self::$instance = new Pinim_Pending_Imports;
                    self::$instance->init();
            }
            return self::$instance;
    }
    
    private function __construct() { /* Do nothing here */ }
    
    function init(){

        add_action( 'admin_menu',array(&$this,'admin_menu'),10,2);
        add_action( 'current_screen', array( $this, 'process_bulk_pin_action'), 9 );
        add_action( 'current_screen', array( $this, 'process_pin_action'), 9 );
        add_action( 'current_screen', array( $this, 'page_pending_import_init') );

    }
    
    function admin_menu(){
        
        $pending_menu_title = __('Pending importation','pinim');

        pinim()->page_pending_imports = add_submenu_page(
            sprintf('edit.php?post_type=%s',pinim()->pin_post_type), 
            __('Pending importation','pinim'), //page title
            $pending_menu_title, //menu title
            pinim_get_pin_capability(), //capability required
            'pending-importation', 
            array($this, 'page_pending_import')
        );

    }
    
    private function bulk_import_pins($pending_pins){

        $existing_pin_ids = pinim()->get_processed_pin_ids();
        $imported_pins = array();
        
        //remove pins that already exists in the DB
        $dupe_count = 0;
        foreach((array)$pending_pins as $key=>$pin){
            
            if ( in_array( $pin->pin_id,$existing_pin_ids ) ){
                pinim()->debug_log(sprintf('pin #%s already exist, skip it',$pin->pin_id),'bulk_import_pins');
                unset($pending_pins[$key]);
                $dupe_count+=1;
                continue;
            }
        }
        
        if (!$pending_pins) return true;

        foreach((array)$pending_pins as $key=>$pin){

            //save pin
            $success = $pin->save();
            if (!is_wp_error($success)){
                $imported_pins[] = $pin;
            }else{
                pinim()->debug_log(sprintf('error while importing pin #%s: %s',$pin->pin_id,$success->get_error_message()),'bulk_import_pins');
                add_settings_error('feedback_pending_import', 'import_pin_'.$pin->pin_id, $success->get_error_message(),'inline');
            }

        }

        $bulk_count = count($pending_pins);
        $success_count = count($imported_pins);

        add_settings_error('feedback_pending_import', 'import_pins', 
            sprintf( _n( '%s/%s pin have been successfully imported.', '%s/%s pins have been successfully imported.', $success_count,'pinim' ), $success_count,$bulk_count ),
            'updated inline'
        );
    }
    
    function process_bulk_pin_action(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_pending-importation') return;
        
        $bulk_pin_ids = array();
        $bulk_pins = array();
        
        
        //process boards listing form
        $form_pins = isset($_POST['pinim_form_pins']) ? $_POST['pinim_form_pins'] : null;

        //bulk action
        $action = ( isset($_REQUEST['action']) && ($_REQUEST['action']!=-1)  ? $_REQUEST['action'] : null);
        if (!$action){
            $action = ( isset($_REQUEST['action2']) && ($_REQUEST['action2']!=-1)  ? $_REQUEST['action2'] : null);
        }
        
        if (!$action || !$form_pins) return;

        //get pins
        
        $form_pins = array_filter( //keep only boards that are checked
            (array)$_POST['pinim_form_pins'],
            function ($e) {
                return isset($e['bulk']);
            }
        );
        
        //get pin ids
        foreach((array)$form_pins as $form_pin){
            $bulk_pin_ids[] = $form_pin['id'];
            
        }
        
        //
        pinim()->debug_log(json_encode(array('action'=>$action,'pin_ids'=>$bulk_pin_ids)),'process_bulk_pin_action');
        //
        
        //get pins
        foreach((array)$bulk_pin_ids as $pin_id){
            $bulk_pins[] = new Pinim_Pending_Pin($pin_id);
        }

        if(!$bulk_pins) return;

        switch ($action) {

            case 'bulk_import_pins':
                $success = $this->bulk_import_pins($bulk_pins);

                //redirect to processed pins
                $url = pinim_get_menu_url();
                wp_redirect( $url );

            break;
        }
        
    }
    
    function process_pin_action(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_pending-importation') return;
        
        //process URL board action

        $action = isset($_GET['action']) ? $_GET['action'] : null;

        switch ($action) {

            case 'import_pin':
                $pin_id = isset($_GET['pin_id']) ? $_GET['pin_id'] : null;
                if (!$pin_id) return;
                
                //save pin
                $pin = new Pinim_Pending_Pin($pin_id);
                $pins = array($pin);
                $success = $this->bulk_import_pins($pins);
                if (is_wp_error($success)){
                    add_settings_error('feedback_pending_import', 'import_pin', $success->get_error_message(),'inline');
                }

            break;

            case 'import_all_pins':
                $all_raw_pins = $this->get_all_raw_pins();
                $all_pins = array();
                foreach((array)$all_raw_pins as $raw_pin){
                    $all_pins[] = new Pinim_Pending_Pin($raw_pin);
                }
                
                $success = $this->bulk_import_pins($all_pins);
                
                if (is_wp_error($success)){
                    add_settings_error('feedback_pending_import', 'import_pin', $success->get_error_message(),'inline');
                }
                
            break;
                
            case 'import_board_pins':
                $board_id = isset($_GET['board_id']) ? $_GET['board_id'] : null;
                if (!$board_id) return;
                
                $board = pinim_boards()->get_board($board_id);
                if ( is_wp_error($board) ) return $board;

                foreach((array)$board->raw_pins as $raw_pin){
                    $board_pins[] = new Pinim_Pending_Pin($raw_pin);
                }
                $success = pinim_pending_imports()->bulk_import_pins($board_pins);
                
                if (is_wp_error($success)){
                    add_settings_error('feedback_pending_import', 'import_pin', $success->get_error_message(),'inline');
                }
                
            break;
                
        }
    }

    function page_pending_import_init(){
        $screen = get_current_screen();
        if ($screen->id != 'pin_page_pending-importation') return;

        /*
        INIT PENDING  PINS
        */
        
        $pins = array();
        $this->table_pins = new Pinim_Pins_Table();
        
        if ( !pinim_pending_imports()->get_all_raw_pins() ){
            $boards_url = pinim_get_menu_url(array('page'=>'boards'));
            add_settings_error('feedback_pending_import','not_logged',sprintf(__('To list the pins you can import here, you first need to <a href="%s">cache some Pinterest Boards</a>.','pinim'),$boards_url),'error inline');
        }
        
        //display pins
        $all_raw_pins = $this->get_all_raw_pins();
        
        //remove pins that already exists in the DB
        $existing_pin_ids = pinim()->get_processed_pin_ids();
        foreach((array)$all_raw_pins as $key=>$pin){
            if ( in_array( $pin['id'],$existing_pin_ids ) ){
                unset($all_raw_pins[$key]);
                continue;
            }
        }

        foreach ((array)$all_raw_pins as $raw_pin){
            $pins[] = new Pinim_Pending_Pin($raw_pin);
        }
        
        $this->table_pins->input_data = $pins;
        $this->table_pins->prepare_items();
        
        
    }

    function page_pending_import(){
?>
        <div class="wrap">
            <h2><?php _e('Pins pending importation','pinim');?></h2>
            <?php settings_errors('feedback_pending_import');?>
            <form action="<?php echo pinim_get_menu_url(array('page'=>'pending-importation'));?>" method="post">
                <?php
                $this->table_pins->views_display();
                $this->table_pins->views();
                $this->table_pins->display();                            
                ?>
            </form>
        </div>
        <?php
    }

    function get_all_raw_pins(){

        $pins = array();

        $boards = pinim_boards()->get_boards();
        
        if (!is_wp_error($boards)) {

            foreach ((array)$boards as $board){
                if ( !$board->raw_pins ) continue;
                $pins = array_merge($pins,$board->raw_pins);
            }
            
        }

        return $pins;

    }
    


}

function pinim_pending_imports() {
	return Pinim_Pending_Imports::instance();
}
pinim_pending_imports();