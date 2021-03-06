<?php
function pinim_get_menu_url($args = array()){
    $defaults = array(
        'post_type' => pinim()->pin_post_type
    );

    $args = wp_parse_args($args, $defaults);
     
    //url encode
    $args = array_combine(
            array_map( 'rawurlencode', array_keys( $args ) ),
            array_map( 'rawurlencode', array_values( $args ) )
    );

    return add_query_arg($args,admin_url('edit.php'));
}

/**
 * Checks if a featured pin image already has been imported (eg. If we have two pins with the same image)
 * @param type $img_url
 * @return boolean
 */

function pinim_image_exists($img_url){
    $query_args = array(
        'post_type'         => 'attachment',
        'post_status'       => 'inherit',
        'meta_query'        => array(
            array(
                'key'     => '_pinterest-image-url',
                'value'   => $img_url,
                'compare' => '='
            )
        ),
        'posts_per_page'    => 1
    );

    $query = new WP_Query($query_args);
    if (!$query->have_posts()) return false;
    return $query->posts[0]->ID;
}

/**
 * Get a single pinterest meta (if key is defined) or all the pinterest metas for a post ID
 * @param type $key (optional)
 * @param type $post_id
 * @return type
 */

function pinim_get_pin_meta($key = false, $post_id = false, $single = false){
    $pin_metas = array();
    $prefix = '_pinterest-';
    $metas = get_post_custom($post_id);

    foreach((array)$metas as $meta_key=>$meta){
        $splitkey = explode($prefix,$meta_key);
        if (!isset($splitkey[1])) continue;
        $pin_key = $splitkey[1];
        $pin_metas[$pin_key] = $meta;

    }
    
    if ( empty($pin_metas) ) return;
    
    if ($key){
        $pin_metas = $pin_metas[$key];
    }

    if($single){
        return $pin_metas[0];
    }else{
        return $pin_metas;
    }


}

function pinim_get_pin_log($post_id,$keys = null){
    $log = unserialize(pinim_get_pin_meta('log',$post_id,true));
    return pinim_get_array_value($keys, $log);
}

function pinim_get_boards_options($keys = null){
    
    if (!pinim()->user_boards_options) {
        pinim()->user_boards_options = get_user_meta( get_current_user_id(), pinim()->meta_name_user_boards_options, true);
    }
    
    return pinim_get_array_value($keys, pinim()->user_boards_options);

}

function pinim_classes_attr($classes){
    echo pinim_get_classes_attr($classes);
}

function pinim_get_classes_attr($classes){
    if (empty($classes)) return;
    return' class="'.implode(' ',$classes).'"';
}

function pinim_get_root_category_id(){
    if (!$category_id = pinim()->get_options('category_root_id')){
        if ($root_term = pinim_get_term_id(pinim()->root_term_name,'category')){
            return $root_term['term_id'];
        }
    }
    return false;
}

function pinim_get_pin_id_for_post($post_id = null){
    global $post;
    if (!$post) $post_id = $post->ID;
    return get_post_meta($post_id,'_pinterest-pin_id',true);
}

?>
