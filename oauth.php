<?php
defined( 'ABSPATH' ) or die( 'Nope, not accessing this' );

// eventorganizer / triggered after an event has been updated
// http://codex.wp-event-organiser.com/hook-eventorganiser_save_event.html
add_action('eventorganiser_save_event', 'wpgancio_eo_save_event', 15);

function wpgancio_eo_save_event ($post_id) {
  $event = get_post( $post_id );

  // do not save if it's a draft
  if ($event->post_status != 'publish') {
    return;
  }

  function tagName ($tag) {
    return sanitize_title($tag->name);
  }

  $tags = [];
  $tmp_tags = get_the_terms( $event, 'event-tag' );
  if ($tmp_tags) {
    $tags = array_map('tagName', $tmp_tags);
  }

  $start_datetime = eo_get_schedule_start( 'U', $post_id );

  // get place details
  $venue_id = eo_get_venue($post_id);
  $place_name = eo_get_venue_name($venue_id);
  $place_address = eo_get_venue_address($venue_id);

  $body = array (
    'title' => $event->post_title,
    'tags' => $tags,
    'description' => $event->post_content,
    'start_datetime' => intval($start_datetime),
    'place_name' => $place_name,
    'place_address' => $place_address['address'] . ", " . $place_address['city']
  );

  // add image if specified
  $image_url = get_the_post_thumbnail_url($post_id);
  if ($image_url) {
    $body['image_url'] = esc_url($image_url);
  }

  wpgancio_send_event_to_instance($body, $post_id);
}

add_action('wp_trash_post', 'wpgancio_eo_delete_post', 15);

function wpgancio_eo_delete_post ($post_id) {
  $post = get_post($post_id);
  
  if ($post->post_type == 'event') {
    $instance_url = get_option('wpgancio_instance_url') ?: get_site_option('wpgancio_instance_url');
    $gancio_id = get_post_meta($post_id, 'wpgancio_gancio_id', TRUE);
    if ($gancio_id) {
      $http = _wp_http_get_object();
      $http->request( "{$instance_url}/api/event/{$gancio_id}", array(
        'method' => 'DELETE',
        'headers' => array (
          'Authorization' => 'Bearer ' . (get_option('wpgancio_token') ?: get_site_option('wpgancio_token'))
        )));
    }
  }
}

// The Event Calendar / triggered after an event has been created and updated
// https://docs.theeventscalendar.com/reference/hooks/tec_events_custom_tables_v1_after_insert_event/
add_action('tec_events_custom_tables_v1_after_insert_event', 'wpgancio_tec_save_event', 15);
add_action('tec_events_custom_tables_v1_after_update_event', 'wpgancio_tec_save_event', 15);

function wpgancio_tec_save_event($post_id) {
  $event = get_post( $post_id );

  // do not save if it's a draft
  if ($event->post_status != 'publish' ) {
    return;
  }

  // get the tags
  function tagName ($tag) {
    return sanitize_title($tag->name);
  }

  $tags = [];
  $tmp_tags = get_the_terms( $event, 'post_tag' );
  if($tmp_tags) {
    $tags = array_map('tagName', $tmp_tags);
  }
  
  // get the event start time 
  // the timestamp generated byt the plugin function is 2h shifted, substract 7200 s --> 2h
  $start_datetime = strtotime(tribe_get_start_date( $event, true, Tribe__Date_Utils::DBDATETIMEFORMAT )) - 7200;

  // get the event place details
  $venue_name    = tribe_get_venue( $event->id );
  $venue_address = tribe_get_address($event->id) . ', ' . tribe_get_zip($event->id) . ', ' . tribe_get_stateprovince($event->id) . ', ' . tribe_get_country($event->id);

  // prepare the request's body
  $body = array (
    'title' => $event->post_title,
    'tags' => $tags,
    'description' => $event->post_content,
    'start_datetime' => intval($start_datetime),
    'place_name' => $venue_name,
    'place_address' => $venue_address
  );

  // add image if specified
  $image_url = get_the_post_thumbnail_url($post_id);
  if ($image_url) {
    $body['image_url'] = esc_url($image_url);
  }

  wpgancio_send_event_to_instance($body, $post_id);
}

function wpgancio_send_event_to_instance($body, $post_id) {
  // gancio instance details
  $gancio_id = get_post_meta($post_id, 'wpgancio_gancio_id', TRUE);
  $instance_url = get_option('wpgancio_instance_url') ?: get_site_option('wpgancio_instance_url');

  // update
  if ($gancio_id) {
    $body['id'] = $gancio_id;
    $http = _wp_http_get_object();
    $response = $http->request( $instance_url . '/api/event', array(
      'method' => 'PUT',
      'headers' => array (
        'Authorization' => 'Bearer ' . (get_option('wpgancio_token') ?: get_site_option('wpgancio_token')),
        'Content-Type' => 'application/json'
      ), 'body' => wp_json_encode($body) ));
  } else { // or create
    $response = wp_remote_post($instance_url . '/api/event', array(
      'headers' => array (
        'Authorization' => 'Bearer ' . (get_option('wpgancio_token') ?: get_site_option('wpgancio_token')),
        'Content-Type' => 'application/json'
      ), 'body' => wp_json_encode($body) ));
  }

  if (is_wp_error($response)) {
    $error_message = $response->get_error_message();
    set_transient("wpgancio_error_", esc_html($error_message), 45);
    return;
  }

  if (wp_remote_retrieve_response_code($response) != 200) {
    set_transient("wpgancio_error_{$post_id}", wp_remote_retrieve_body($response), 45);
    return;
  }
  
  $data = json_decode(wp_remote_retrieve_body($response));

  $event_url = $instance_url . '/event/' . ($data->slug ? $data->slug : $data->id);
  set_transient("wpgancio_message_{$post_id}", "Event updated. <a href='{$event_url}'>{$event_url}</a>");
  update_post_meta($post_id, 'wpgancio_gancio_id', intval($data->id));
}

add_action('wp_trash_post', 'wpgancio_tec_delete_post', 15);

function wpgancio_tec_delete_post ($post_id) {
  $post = get_post($post_id);
  
  if ($post->post_type == 'tribe_events') {
    $instance_url = get_option('wpgancio_instance_url') ?: get_site_option('wpgancio_instance_url');
    $gancio_id = get_post_meta($post_id, 'wpgancio_gancio_id', TRUE);
    if ($gancio_id) {
      $http = _wp_http_get_object();
      $http->request( "{$instance_url}/api/event/{$gancio_id}", array(
        'method' => 'DELETE',
        'headers' => array (
          'Authorization' => 'Bearer ' . (get_option('wpgancio_token') ?: get_site_option('wpgancio_token'))
        )));
    }
  }
}


add_action( 'admin_notices', 'wpgancio_error_message' );
function wpgancio_error_message () {
  global $post_id;
  if ( $error = get_transient( "wpgancio_error_{$post_id}" ) ) { ?>
    <div class="error">
        <p>[WPGancio] <?php echo $error; ?></p>
    </div><?php
    delete_transient("wpgancio_error_{$post_id}");
  }

  if ( $message = get_transient( "wpgancio_message_{$post_id}" ) ) { ?>
    <div class="notice success">
        <p>[WPGancio] <?php echo $message; ?></p>
    </div><?php
    delete_transient("wpgancio_message_{$post_id}");
  }
}
