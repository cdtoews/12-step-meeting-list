<?php

//function: helper for debugging
//used:		ad-hoc
if (!function_exists('dd')) {
	function dd($array) {
		echo '<pre>';
		print_r($array);
		exit;
	}
}

//function: sanitize multi-line text
//used:		tsml_import() and save.php
if (!function_exists('sanitize_text_area')) {
	function sanitize_text_area($value) {
		return implode("\n", array_map('sanitize_text_field', explode("\n", trim($value))));
	}
}

//function:	add an admin screen update message
//used:		tsml_import() and admin_types.php
//$type:		can be success, warning or error
function tsml_alert($message, $type='success') {
	echo '<div class="notice notice-' . $type . ' is-dismissible"><p>' . $message . '</p></div>';
}

//function: enqueue assets for public or admin page
//used: in templates and on admin_edit.php
function tsml_assets() {
	global $tsml_street_only, $tsml_programs, $tsml_strings, $tsml_program, $tsml_google_maps_key, $tsml_mapbox_key, $tsml_google_overrides, $tsml_distance_units, $tsml_defaults, $tsml_language, $tsml_columns, $tsml_nonce;
		
	//google maps api
	if ($tsml_google_maps_key) {
		wp_enqueue_script('google_maps_api', '//maps.googleapis.com/maps/api/js?key=' . $tsml_google_maps_key);
	}
	
	if (is_admin()) {
		//dashboard page assets
		wp_enqueue_style('tsml_admin', plugins_url('../assets/css/admin.min.css', __FILE__), array(), TSML_VERSION);
		wp_enqueue_script('tsml_admin', plugins_url('../assets/js/admin.min.js', __FILE__), array('jquery'), TSML_VERSION, true);
		wp_localize_script('tsml_admin', 'tsml', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'debug' => WP_DEBUG,
			'google_maps_key' => $tsml_google_maps_key, //to see if map should have been called
			'mapbox_key' => $tsml_mapbox_key,
			'nonce' => wp_create_nonce($tsml_nonce),
		));
	} else {
		//public page assets
		wp_enqueue_style('tsml_public', plugins_url('../assets/css/public.min.css', __FILE__), array(), TSML_VERSION);
		wp_enqueue_script('jquery_validate', plugins_url('../assets/js/jquery.validate.min.js', __FILE__), array('jquery'), TSML_VERSION, true);
		wp_enqueue_script('tsml_public', plugins_url('../assets/js/public.min.js', __FILE__), array('jquery'), TSML_VERSION, true);
		wp_localize_script('tsml_public', 'tsml', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'columns' => array_keys($tsml_columns),
			'days' => array(
				__('Sunday', '12-step-meeting-list'),
				__('Monday', '12-step-meeting-list'),
				__('Tuesday', '12-step-meeting-list'),
				__('Wednesday', '12-step-meeting-list'),
				__('Thursday', '12-step-meeting-list'),
				__('Friday', '12-step-meeting-list'),
				__('Saturday', '12-step-meeting-list'),
			),
			'debug' => WP_DEBUG,
			'defaults' => $tsml_defaults,
			'distance_units' => $tsml_distance_units,
			'google_maps_key' => $tsml_google_maps_key, //to see if map should have been called
			'mapbox_key' => $tsml_mapbox_key,
			'nonce' => wp_create_nonce($tsml_nonce),
			'program' => empty($tsml_programs[$tsml_program]['abbr']) ? $tsml_programs[$tsml_program]['name'] : $tsml_programs[$tsml_program]['abbr'],
			'street_only' => $tsml_street_only,
			'strings' => $tsml_strings,
			'types' => empty($tsml_programs[$tsml_program]['types']) ? array() : $tsml_programs[$tsml_program]['types'],
		));
	}
}

//set tsml_bounds from current data
function tsml_bounds() {
	global $wpdb, $tsml_bounds;

	//get north & south
	$latitudes = $wpdb->get_row('SELECT 
			MAX(m.meta_value) north,
			MIN(m.meta_value) south
		FROM ' . $wpdb->postmeta . ' m
		JOIN ' . $wpdb->posts . ' p ON p.ID = m.post_id
		WHERE m.meta_key = "latitude" AND p.post_type = "tsml_location"');

	//get east & west
	$longitudes = $wpdb->get_row('SELECT 
			MAX(m.meta_value) west,
			MIN(m.meta_value) east
		FROM ' . $wpdb->postmeta . ' m
		JOIN ' . $wpdb->posts . ' p ON p.ID = m.post_id
		WHERE m.meta_key = "longitude" AND p.post_type = "tsml_location"');

	//if results, get bounding box and cache it
	if ($latitudes && $longitudes) {

		//add 25% margin to the bounds
		$width = ($longitudes->east - $longitudes->west) / 25;
		$height = ($latitudes->north - $latitudes->south) / 25;

		$tsml_bounds = array(
			'north' => $latitudes->north + $height,
			'east' => $longitudes->east + $width,
			'south' => $latitudes->south - $height,
			'west' => $longitudes->west - $width,
		);

		update_option('tsml_bounds', $tsml_bounds);
	}
}

//try to build a cache of meetings to help with CPU load
function tsml_cache_rebuild() {
	global $tsml_cache;

	$numeric_fields = array('id', 'day', 'latitude', 'longitude', 'location_id', 'group_id', 'region_id');

	//strip empty, null values from array to save 12% space
	$meetings = array_map(function($meeting) use ($numeric_fields) {
		foreach ($meeting as $key => $value) {
			if (empty($meeting[$key]) && $meeting[$key] !== '0') {
				unset($meeting[$key]);
			} elseif (in_array($key, $numeric_fields)) {
				$meeting[$key] -= 0;
			}
		}
		return $meeting;
	}, tsml_get_meetings(array(), false));

	//save to file
	file_put_contents(WP_CONTENT_DIR . $tsml_cache, json_encode($meetings));
}

//called by register_activation_hook in 12-step-meeting-list.php
//hands off to tsml_custom_post_types
function tsml_change_activation_state() {
	tsml_custom_post_types();
	flush_rewrite_rules();
}

//function:	return integer number of live groups
//used:		shortcode, admin-import.php, tsml_ajax_import()
function tsml_count_groups() {
	return count(tsml_get_all_groups('publish'));
}

//function:	return integer number of live locations
//used:		shortcode, admin-import.php, tsml_ajax_import()
function tsml_count_locations() {
	return count(tsml_get_all_locations('publish'));
}

//function:	return integer number of live meetings
//used:		shortcode, admin-import.php, tsml_ajax_import()
function tsml_count_meetings() {
	return count(tsml_get_all_meetings('publish'));
}

//function:	return integer number of live regions
//used:		shortcode, admin-import.php, tsml_ajax_import()
function tsml_count_regions() {
	return count(tsml_get_all_regions());
}

//function:	add local overrides to google (this may someday be removed)
//used:		in user themes
function tsml_custom_addresses($custom_overrides) {
	global $tsml_google_overrides;
	$tsml_google_overrides = array_merge($tsml_google_overrides, $custom_overrides);
}

//fuction:	define custom flags (/men, /women) for your area
//used:		theme's functions.php
function tsml_custom_flags($flags) {
	global $tsml_programs, $tsml_program;
	$tsml_programs[$tsml_program]['flags'] = $flags;
}

//function: register custom post types
//used: 	init.php on every request, also in change_activation_state() for plugin activation or deactivation
function tsml_custom_post_types() {
	global $tsml_slug;
	register_taxonomy('tsml_region', 'tsml_location', array(
		'labels' => array(
			'name' => __('Regions', '12-step-meeting-list'),
			'singular_name' => __('Region', '12-step-meeting-list'),
			'menu_name' => __('Regions', '12-step-meeting-list'),
			'all_items' => __('All Regions', '12-step-meeting-list'),
			'edit_item' => __('Edit Region', '12-step-meeting-list'),
			'view_item' => __('View Region', '12-step-meeting-list'),
			'update_item' => __('Update Region', '12-step-meeting-list'),
			'add_new_item' => __('Add New Region', '12-step-meeting-list'),
			'new_item_name' => __('New Region', '12-step-meeting-list'),
			'parent_item' => __('Parent Region', '12-step-meeting-list'),
			'parent_item_colon' => __('Parent Region:', '12-step-meeting-list'),
			'search_items' => __('Search Regions', '12-step-meeting-list'),
			'popular_items' => __('Popular Regions', '12-step-meeting-list'),
			'not_found' => __('No regions found.', '12-step-meeting-list'),
		),
		'hierarchical' => true,
	));

	register_taxonomy('tsml_district', 'tsml_group', array(
		'labels' => array(
			'name' => __('District', '12-step-meeting-list'),
			'singular_name' => __('District', '12-step-meeting-list'),
			'menu_name' => __('District', '12-step-meeting-list'),
			'all_items' => __('All Districts', '12-step-meeting-list'),
			'edit_item' => __('Edit District', '12-step-meeting-list'),
			'view_item' => __('View District', '12-step-meeting-list'),
			'update_item' => __('Update District', '12-step-meeting-list'),
			'add_new_item' => __('Add New District', '12-step-meeting-list'),
			'new_item_name' => __('New District', '12-step-meeting-list'),
			'parent_item' => __('Parent Area', '12-step-meeting-list'),
			'parent_item_colon' => __('Parent Area:', '12-step-meeting-list'),
			'search_items' => __('Search Districts', '12-step-meeting-list'),
			'popular_items' => __('Popular Districts', '12-step-meeting-list'),
			'not_found' => __('No districts found.', '12-step-meeting-list'),
		),
		'hierarchical' => true,
	));

	register_post_type('tsml_meeting',
		array(
			'labels' => array(
				'name' =>	__('Meetings', '12-step-meeting-list'),
				'singular_name' =>	__('Meeting', '12-step-meeting-list'),
				'not_found' =>	__('No meetings added yet.', '12-step-meeting-list'),
				'add_new_item' =>	__('Add New Meeting', '12-step-meeting-list'),
				'search_items' =>	__('Search Meetings', '12-step-meeting-list'),
				'edit_item' =>	__('Edit Meeting', '12-step-meeting-list'),
				'view_item' =>	__('View Meeting', '12-step-meeting-list'),
			),
			//not sure if we want this on the meeting or on the location
			//'supports' => array('title', 'thumbnail'),
			'supports' => array('title', 'author'),
			'public' => true,
			'has_archive' => true,
			'menu_icon' => 'dashicons-groups',
			'rewrite' => array('slug'=>$tsml_slug),
		)
	);

	register_post_type('tsml_location',
		array(
			'supports' => array('title'),
			'public' => true,
			'show_ui' => false,
			'has_archive' => true,
			'capabilities' => array('create_posts' => false),
			'rewrite' => array('slug'=>'locations'),
			'taxonomies' => array('tsml_region'),
		)
	);	

	register_post_type('tsml_group',
		array(
			'supports' => array('title'),
			'public' => true,
			'show_ui' => false,
			'has_archive' => false,
			'capabilities' => array('create_posts' => false),
		)
	);	
}

//fuction:	define custom meeting types for your area
//used:		theme's functions.php
function tsml_custom_types($types) {
	global $tsml_programs, $tsml_program;
	foreach ($types as $key => $value) {
		$tsml_programs[$tsml_program]['types'][$key] = $value;
	}
	asort($tsml_programs[$tsml_program]['types']);
}

//called by tsml_import() and in the future elsewhere
function tsml_debug($string) {
	global $tsml_timestamp;
	if (!WP_DEBUG) return;
	tsml_alert($string . ' in ' . round(microtime(true) - $tsml_timestamp, 2) . 's', 'warning');
	$tsml_timestamp = microtime(true);
}

//function:	efficiently remove an array of post_ids
//used:		tsml_delete_orphans(), admin-import.php
function tsml_delete($post_ids) {
	global $wpdb;

	//special case	
	if ($post_ids == 'everything') {
		
		$post_ids = get_posts(array(
			'post_type' => array('tsml_meeting', 'tsml_location', 'tsml_group'),
			'post_status' => 'any',
			'fields' => 'ids',
			'numberposts' => -1,
		));
		
		//when we're deleting *everything*, also delete regions & districts
		if ($term_ids = implode(',', $wpdb->get_col('SELECT term_id FROM ' . $wpdb->term_taxonomy . ' WHERE taxonomy IN ("tsml_district", "tsml_region")'))) {
			$wpdb->query('DELETE FROM ' . $wpdb->terms . ' WHERE term_id IN (' . $term_ids . ')');
			$wpdb->query('DELETE FROM ' . $wpdb->term_taxonomy . ' WHERE term_id IN (' . $term_ids . ')');
		}
	} 
	
	if (empty($post_ids) || !is_array($post_ids)) return;
	
	//sanitize
	$post_ids = array_map('intval', $post_ids);
	$post_ids = array_unique($post_ids);
	$post_ids = implode(', ', $post_ids);
	
	//run deletes
	$wpdb->query('DELETE FROM ' . $wpdb->posts . ' WHERE ID IN (' . $post_ids . ')');
	$wpdb->query('DELETE FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . $post_ids . ')');
	$wpdb->query('DELETE FROM ' . $wpdb->term_relationships . ' WHERE object_id IN (' . $post_ids . ')');
}

//function: efficiently deletes all orphaned locations and groups (have no meetings associated)
//used:		save_post filter
function tsml_delete_orphans() {
	global $wpdb;
	$location_ids = $wpdb->get_col('SELECT l.ID FROM ' . $wpdb->posts . ' l WHERE l.post_type = "tsml_location" AND (SELECT COUNT(*) FROM ' . $wpdb->posts . ' m WHERE m.post_type="tsml_meeting" AND m.post_parent = l.id) = 0');
	$group_ids = $wpdb->get_col('SELECT g.ID FROM ' . $wpdb->posts . ' g WHERE g.post_type = "tsml_group" AND (SELECT COUNT(*) FROM ' . $wpdb->postmeta . ' m WHERE m.meta_key="group_id" AND m.meta_value = g.id) = 0');
	tsml_delete(array_merge($location_ids, $group_ids));

	//edge case: draft-ify locations with only unpublished meetings
	$location_ids = $wpdb->get_col('SELECT l.ID FROM ' . $wpdb->posts . ' l 
		WHERE l.post_type = "tsml_location" AND 
			(SELECT COUNT(*) FROM ' . $wpdb->posts . ' m 
			WHERE m.post_type="tsml_meeting" AND m.post_status="publish" AND m.post_parent = l.id) = 0');
	if (count($location_ids)) {
		$wpdb->query('UPDATE ' . $wpdb->posts . ' l SET l.post_status = "draft" WHERE ID IN (' . implode(', ', $location_ids) . ')');
	}
}

//send a nice-looking email (used by tsml_ajax_feedback() and save.php (change notifications)
function tsml_email($to, $subject, $message, $reply_to=false) {

	$headers = array('Content-Type: text/html; charset=UTF-8');
	if ($reply_to) $headers[] = 'Reply-To: ' . $reply_to;
	
	//prepend subject as h1
	$message = '<h1>' . $subject . '</h1>' . $message;
	
	//inline styles where necessary
	$message = str_replace('<h1>', '<h1 style="margin: 0; font-weight:bold; font-size:24px;">', $message);
	$message = str_replace('<hr>', '<hr style="margin: 15px 0; border: 0; height: 1px; background: #cccccc;">', $message);
	$message = str_replace('<p>', '<p style="margin: 1em 0;">', $message);
	$message = str_replace('<a ', '<a style="color: #6699cc; text-decoration: underline;" ', $message);

	//wrap message in email-compliant html
	$message = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
		<title>' . $subject . '</title>
		<style type="text/css">
		</style>
	</head>
	<body style="width:100% !important; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; margin:0; padding:0; background-color:#eeeeee;">
		<table cellpadding="0" cellspacing="0" border="0" style="background-color:#eeeeee; width:100%; height:100%;">
			<tr>
				<td valign="top" style="text-align:center;padding-top:15px;">
					<table cellpadding="0" cellspacing="0" border="0" align="center">
						<tr>
							<td width="630" valign="top" style="background-color:#ffffff; text-align:left; padding:15px; font-size:15px; font-family:Arial, sans-serif;">
								' . $message . '
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</body>
</html>';

	return wp_mail($to, '[' . get_bloginfo('name') . '] ' . $subject, $message, $headers);
}

//take a full address and return it formatted for the front-end
//used on template pages
function tsml_format_address($formatted_address, $street_only=false) {
	$parts = explode(',', esc_attr($formatted_address));
	$parts = array_map('trim', $parts);
	if (in_array(end($parts), array('USA', 'US'))) {
		array_pop($parts);
		if (count($parts) > 1) {
			$state_zip = array_pop($parts);
			$parts[count($parts) - 1] .= ', ' . $state_zip;
		}
	}
	if ($street_only) return array_shift($parts);
	return implode('<br>', $parts);
}

//function: takes 0, 18:30 and returns Sunday, 6:30 pm (depending on your settings)
//used:		admin_edit.php, archive-meetings.php, single-meetings.php
function tsml_format_day_and_time($day, $time, $separator=', ', $short=false) {
	global $tsml_days;
	/* translators: Appt is abbreviation for Appointment */
	if (empty($tsml_days[$day]) || empty($time)) return $short ? __('Appt', '12-step-meeting-list') : __('Appointment', '12-step-meeting-list');
	return ($short ? substr($tsml_days[$day], 0, 3) : $tsml_days[$day]) . $separator . '<time>' . tsml_format_time($time) . '</time>';
}

//function:	appends men or women (or custom flags) if type present
//used:		archive-meetings.php
function tsml_format_name($name, $types=array()) {
	global $tsml_program, $tsml_programs;
	if (empty($tsml_programs[$tsml_program]['flags']) || !is_array($tsml_programs[$tsml_program]['flags'])) return $name;
	$append = array();
	foreach ($types as $type) {
		if (in_array($type, $tsml_programs[$tsml_program]['flags'])) {
			$append[] = $tsml_programs[$tsml_program]['types'][$type];
		}
	}
	return count($append) ? $name . ' <small>' . implode(', ', $append) . '</small>' : $name;
}

//function: takes 18:30 and returns 6:30 pm (depending on your settings)
//used:		tsml_get_meetings(), single-meetings.php, admin_lists.php
function tsml_format_time($string, $empty='Appointment') {
	if (empty($string)) return empty($empty) ? '' : __($empty, '12-step-meeting-list');
	if ($string == '12:00') return __('Noon', '12-step-meeting-list');
	if ($string == '23:59' || $string == '00:00') return __('Midnight', '12-step-meeting-list');
	$date = strtotime($string);
	return date(get_option('time_format'), $date);
}

//get the next meeting start datetime for schema.org microdata
//used:		single-meeting.php
if (!function_exists('tsml_format_next_start')) {
	function tsml_format_next_start($meeting) {
		if (empty($meeting->time)) return null;
		$days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
		$string = 'next ' . $days[$meeting->day] . ' ' . $meeting->time . ' ' . get_option('timezone_string');
		return date('c', strtotime($string));
	}
}

//function: takes a time string, eg 6:30 pm, and returns 18:30
//used:		tsml_import(), tsml_time_duration()
function tsml_format_time_reverse($string) {
	$time_parts = date_parse($string);
	return sprintf('%02d', $time_parts['hour']) . ':' . sprintf('%02d', $time_parts['minute']);
}

//function:	convert a string to utf8 if it needs it
//used:		by tsml_import()
function tsml_format_utf8(&$item, $key) {
	if (!function_exists('mb_detect_encoding')) return;
	if (!mb_detect_encoding($item, 'utf-8', true)) {
		$item = utf8_encode($item);
	}
}

//function: display meeting list on home page (must be set to a static page)
//used:		by themes that want it, such as https://github.com/meeting-guide/one-page-meeting-list
function tsml_front_page($wp_query){
	if (is_admin()) return; //don't do this to inside pages
	if ($wp_query->get('page_id') == get_option('page_on_front')) {
		$wp_query->set('post_type', 'tsml_meeting');
		$wp_query->set('page_id', '');
		$wp_query->is_page = 0;
		$wp_query->is_singular = 0;
		$wp_query->is_post_type_archive = 1;
		$wp_query->is_archive = 1;
	}
}

//function: request accurate address information from google
//used:		tsml_ajax_import(), tsml_ajax_geocode()
function tsml_geocode($address) {
	global $tsml_curl_handle, $tsml_language, $tsml_google_overrides, $tsml_bounds;

	//check overrides first before anything
	if (array_key_exists($address, $tsml_google_overrides)) {
		return $tsml_google_overrides[$address];
	}

	//check cache
	$addresses	= get_option('tsml_addresses', array());
	if (array_key_exists($address, $addresses)) {
		$addresses[$address]['status'] = 'cache';
		return $addresses[$address];
	}

	//initialize curl handle if necessary
	if (!$tsml_curl_handle) {
		$tsml_curl_handle = curl_init();
		curl_setopt_array($tsml_curl_handle, array(
			CURLOPT_HEADER => 0, 
			CURLOPT_RETURNTRANSFER => true, 
			CURLOPT_TIMEOUT => 60,
			CURLOPT_SSL_VERIFYPEER => false,
		));	
	}

	//start list of options for geocoding request
	$options = array(
		'key' => 'AIzaSyCwIhOSfKs47DOe24JXM8nxfw1gC05BaiU',
		'address' => $address,
		'language' => $tsml_language,
	);

	//bias the viewport if we know the bounds
	if ($tsml_bounds) {
		$options['bounds'] = $tsml_bounds['south'] . ',' . $tsml_bounds['west'] . '|' . $tsml_bounds['north'] . ',' . $tsml_bounds['east'];			
	}

	//send request to google
	curl_setopt($tsml_curl_handle, CURLOPT_URL, 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($options));

	$result = curl_exec($tsml_curl_handle);
	
	//could not connect error
	if (empty($result)) {
		return array(
			'status' => 'error', 
			'reason' => 'Google could not validate the address <code>' . $address . '</code>. Response was <code>' . curl_error($tsml_curl_handle) . '</code>',
		);
	}
	
	//decode result
	$data = json_decode($result);

	if ($data->status == 'OK') {
		//ok great
	} elseif ($data->status == 'OVER_QUERY_LIMIT') {
		//if over query limit, wait two seconds and retry, or then exit
		//this isn't structured well. what if there are zero_results on the second attempt?
		sleep(2);
		$data = json_decode(curl_exec($ch));
		if ($data->status == 'OVER_QUERY_LIMIT') {
			return array(
				'status' => 'error', 
				'reason' => 'We are over the rate limit for the Google Geocoding API.'
			);
		}
	} elseif ($data->status == 'ZERO_RESULTS') {
		if (empty($result)) {
			return array(
				'status' => 'error', 
				'reason' => 'Google could not validate the address <code>' . $address . '</code>',
			);
		}
	} else {
		return array(
			'status' => 'error', 
			'reason' => 'Google gave an unexpected response for address <code>' . $address . '</code>. Response was <pre>' . var_export($data, true) . '</pre>',
		);
	}

	//check our overrides array again in case google is wrong
	if (array_key_exists($data->results[0]->formatted_address, $tsml_google_overrides)) {
		$response = $tsml_google_overrides[$data->results[0]->formatted_address];
	} else {
		//start building response
		$response = array(
			'formatted_address' => $data->results[0]->formatted_address,
			'latitude' => $data->results[0]->geometry->location->lat,
			'longitude' => $data->results[0]->geometry->location->lng,
			'city' => null,
		);

		//get city, we might need it for the region, and we are going to cache it
		foreach ($data->results[0]->address_components as $component) {
			if (in_array('locality', $component->types)) {
				$response['city'] = $component->short_name;
			}
		}
	}
	
	//cache result
	$addresses[$address] = $response;
	update_option('tsml_addresses', $addresses);

	//add a status and return it
	$response['status'] = 'geocode';
	return $response;
}

//function: get all locations in the system
//used:		tsml_group_count()
function tsml_get_all_groups($status='any') {
	return get_posts('post_type=tsml_group&post_status=' . $status . '&numberposts=-1&orderby=name&order=asc');
}

//function: get all locations in the system
//used:		tsml_location_count(), tsml_import(), and admin_import.php
function tsml_get_all_locations($status='any') {
	return get_posts('post_type=tsml_location&post_status=' . $status . '&numberposts=-1&orderby=name&order=asc');
}

//function: get all meetings in the system
//used:		tsml_meeting_count(), tsml_import(), and admin_import.php
function tsml_get_all_meetings($status='any') {
	return get_posts('post_type=tsml_meeting&post_status=' . $status . '&numberposts=-1&orderby=name&order=asc');
}

//function: get all regions in the system
//used:		tsml_region_count(), tsml_import() and admin_import.php
function tsml_get_all_regions() {
	return get_terms('tsml_region', array('fields'=>'ids', 'hide_empty'=>false));
}

//function: get meeting ids for a data source
//used:		tsml_ajax_import, import/settings page
function tsml_get_data_source_ids($source) {
	return get_posts(array(
		'post_type'		=> 'tsml_meeting',
		'numberposts'	=> -1,
		'fields'		=> 'ids',
		'meta_query'	=> array(array(
			'key' 		=> 'data_source',
			'value'		=> $source,
			'compare'	=> '=',
		)),
	));
}

//function: get all locations with full location information
//used: tsml_get_meetings()
function tsml_get_groups() {

	$groups = array();
	
	# Get all districts with parents, need for sub_district below
	$districts = $districts_with_parents = array();
	$terms = get_categories(array('taxonomy' => 'tsml_district'));
	foreach ($terms as $term) {
		$districts[$term->term_id] = $term->name;
		if ($term->parent) $districts_with_parents[$term->term_id] = $term->parent;
	}
	
	# Get all locations
	$posts = tsml_get_all_groups('publish');
	
	# Much faster than doing get_post_meta() over and over
	$group_meta = tsml_get_meta('tsml_group');

	# Make an array of all groups
	foreach ($posts as $post) {

		$district_id = !empty($group_meta[$post->ID]['district_id']) ? $group_meta[$post->ID]['district_id'] : null;
		if (array_key_exists($district_id, $districts_with_parents)) {
			$district = $districts[$districts_with_parents[$district_id]];
			$sub_district = $districts[$district_id];
		} else {
			$district = !empty($districts[$district_id]) ? $districts[$district_id] : '';
			$sub_district = null;
		}

		$groups[$post->ID] = array(
			'group_id' => $post->ID, //so as not to conflict with another id when combined
			'group' => $post->post_title,
			'district' => $district,
			'sub_district' => $sub_district,
			'group_notes' => $post->post_content,
			'website' => empty($group_meta[$post->ID]['website']) ? null : $group_meta[$post->ID]['website'],
			'website_2' => empty($group_meta[$post->ID]['website_2']) ? null : $group_meta[$post->ID]['website_2'],
			'email' => empty($group_meta[$post->ID]['email']) ? null : $group_meta[$post->ID]['email'],
			'phone' => empty($group_meta[$post->ID]['phone']) ? null : $group_meta[$post->ID]['phone'],
			'venmo' => empty($group_meta[$post->ID]['venmo']) ? null : $group_meta[$post->ID]['venmo'],
			'last_contact' => empty($group_meta[$post->ID]['last_contact']) ? null : $group_meta[$post->ID]['last_contact'],
		);
		
		if (current_user_can('edit_posts')) {
			$groups[$post->ID] = array_merge($groups[$post->ID], array(
				'contact_1_name' => empty($group_meta[$post->ID]['contact_1_name']) ? null : $group_meta[$post->ID]['contact_1_name'],
				'contact_1_email' => empty($group_meta[$post->ID]['contact_1_email']) ? null : $group_meta[$post->ID]['contact_1_email'],
				'contact_1_phone' => empty($group_meta[$post->ID]['contact_1_phone']) ? null : $group_meta[$post->ID]['contact_1_phone'],
				'contact_2_name' => empty($group_meta[$post->ID]['contact_2_name']) ? null : $group_meta[$post->ID]['contact_2_name'],
				'contact_2_email' => empty($group_meta[$post->ID]['contact_2_email']) ? null : $group_meta[$post->ID]['contact_2_email'],
				'contact_2_phone' => empty($group_meta[$post->ID]['contact_2_phone']) ? null : $group_meta[$post->ID]['contact_2_phone'],
				'contact_3_name' => empty($group_meta[$post->ID]['contact_3_name']) ? null : $group_meta[$post->ID]['contact_3_name'],
				'contact_3_email' => empty($group_meta[$post->ID]['contact_3_email']) ? null : $group_meta[$post->ID]['contact_3_email'],
				'contact_3_phone' => empty($group_meta[$post->ID]['contact_3_phone']) ? null : $group_meta[$post->ID]['contact_3_phone'],
			));
		}
	}
			
	return $groups;
}

//function: template tag to get location, attach custom fields to it
//$location_id can be false if there is a global post, eg on the single-locations template page
//used: single-locations.php
function tsml_get_location($location_id=false) {
	if (!$location = get_post($location_id)) return;
	if ($custom = get_post_meta($location->ID)) {
		foreach ($custom as $key=>$value) {
			$location->{$key} = htmlentities($value[0], ENT_QUOTES);
		}
	}
	$location->post_title	= htmlentities($location->post_title, ENT_QUOTES);
	$location->notes 		= esc_html($location->post_content);
	if ($region = get_the_terms($location, 'tsml_region')) {
		$location->region_id = $region[0]->term_id;
		$location->region = $region[0]->name;
	}

	//directions link (obsolete 4/15/2018, keeping for compatibility)
	$location->directions = 'https://maps.google.com/?' . http_build_query(array(
		'daddr' => $location->latitude . ',' . $location->longitude,
		'saddr' => 'Current Location',
		'q' => $location->post_title,
	));

	return $location;
}

//function: get all locations with full location information
//used: tsml_import(), tsml_get_meetings(), admin_edit
function tsml_get_locations() {
	$locations = array();
	
	# Get all regions with parents, need for sub_region below
	$regions = $regions_with_parents = array();
	$terms = get_categories(array('taxonomy' => 'tsml_region'));
	foreach ($terms as $term) {
		$regions[$term->term_id] = $term->name;
		if ($term->parent) $regions_with_parents[$term->term_id] = $term->parent;
	}
	
	# Get all locations
	$posts = tsml_get_all_locations('publish');
	
	# Much faster than doing get_post_meta() over and over
	$location_meta = tsml_get_meta('tsml_location');

	# Make an array of all locations
	foreach ($posts as $post) {
		$region_id = !empty($location_meta[$post->ID]['region_id']) ? $location_meta[$post->ID]['region_id'] : null;
		if (array_key_exists($region_id, $regions_with_parents)) {
			$region = $regions[$regions_with_parents[$region_id]];
			$sub_region = $regions[$region_id];
		} else {
			$region = !empty($regions[$region_id]) ? $regions[$region_id] : '';
			$sub_region = null;
		}
		
		$locations[$post->ID] = array(
			'location_id' => $post->ID, //so as not to conflict with another id when combined
			'location' => $post->post_title,
			'location_notes' => $post->post_content,
			'location_url' => get_permalink($post->ID),
			'formatted_address' => empty($location_meta[$post->ID]['formatted_address']) ? null : $location_meta[$post->ID]['formatted_address'],
			'latitude' => empty($location_meta[$post->ID]['latitude']) ? null : $location_meta[$post->ID]['latitude'],
			'longitude' => empty($location_meta[$post->ID]['longitude']) ? null : $location_meta[$post->ID]['longitude'],
			'region_id' => $region_id,
			'region' => $region,
			'sub_region' => $sub_region,
		);
	}
	
	return $locations;
}


//function: template tag to get meeting and location, attach custom fields to it
//$meeting_id can be false if there is a global $post object, eg on the single meeting template page
//used: single-meetings.php
function tsml_get_meeting($meeting_id=false) {
	global $tsml_program, $tsml_programs;
	
	$meeting 		= get_post($meeting_id);
	$custom 		= get_post_meta($meeting->ID);

	//add optional location information
	if ($meeting->post_parent) {
		$location = get_post($meeting->post_parent);
		$meeting->location_id = $location->ID;
		$custom = array_merge($custom, get_post_meta($location->ID));
		$meeting->location = htmlentities($location->post_title, ENT_QUOTES);
		$meeting->location_notes = esc_html($location->post_content);
		if ($region = get_the_terms($location, 'tsml_region')) {
			$meeting->region_id = $region[0]->term_id;
			$meeting->region = $region[0]->name;
		}
		
		//get other meetings at this location
		$meeting->location_meetings = tsml_get_meetings(array('location_id' => $location->ID));
	
		//directions link (obsolete 4/15/2018, keeping for compatibility)
		$meeting->directions = 'https://maps.google.com/?' . http_build_query(array(
			'daddr' => $location->latitude . ',' . $location->longitude,
			'saddr' => 'Current Location',
			'q' => $meeting->location,
		));
	}
	
	//escape meeting values
	foreach ($custom as $key=>$value) {
		$meeting->{$key} = ($key == 'types') ? $value[0] : htmlentities($value[0], ENT_QUOTES);
	}
	if (empty($meeting->types)) $meeting->types = array();
	if (!is_array($meeting->types)) $meeting->types = unserialize($meeting->types);
	$meeting->post_title			= htmlentities($meeting->post_title, ENT_QUOTES);
	$meeting->notes 				= esc_html($meeting->post_content);
	
	//type description? (todo support multiple)
	if (!empty($tsml_programs[$tsml_program]['type_descriptions'])) {
		$types_with_descriptions = array_intersect($meeting->types, array_keys($tsml_programs[$tsml_program]['type_descriptions']));
		foreach ($types_with_descriptions as $type) {
			$meeting->type_description = $tsml_programs[$tsml_program]['type_descriptions'][$type];
			break;
		}
	}
	
	//if meeting is part of a group, include group info
	if ($meeting->group_id) {
		$group = get_post($meeting->group_id);
		$meeting->group = htmlentities($group->post_title, ENT_QUOTES);
		$meeting->group_notes = esc_html($group->post_content);
		$group_custom = get_post_meta($meeting->group_id);
		foreach ($group_custom as $key=>$value) {
			$meeting->{$key} = $value[0];
		}
		
		if ($district = get_the_terms($group, 'tsml_district')) {
			$meeting->district_id = $district[0]->term_id;
			$meeting->district = $district[0]->name;
		}
	} else {
		$meeting->group_id = null;
		$meeting->group = null;
	}
	
	//expand and alphabetize types
	array_map('trim', $meeting->types);
	$types = array();
	foreach ($meeting->types as $type) {
		if (!empty($tsml_programs[$tsml_program]['types'][$type])) {
			$types[] = $tsml_programs[$tsml_program]['types'][$type];
		}
	}
	sort($types);
	$meeting->types = $types;
	
	return $meeting;
}

//function: get meetings based on unsanitized $arguments
//$from_cache is only false when calling from tsml_cache_rebuild()
//used:		tsml_ajax_meetings(), single-locations.php, archive-meetings.php 
function tsml_get_meetings($arguments=array(), $from_cache=true) {
	global $tsml_cache;

	//start by grabbing all meetings
	if ($from_cache && file_exists(WP_CONTENT_DIR . $tsml_cache) && $meetings = file_get_contents(WP_CONTENT_DIR . $tsml_cache)) {
		$meetings = json_decode($meetings, true);
	} else {
		//from database
		$meetings = array();

		//can specify post_status (for PR #33)
		if (empty($arguments['post_status'])) {
			$arguments['post_status'] = 'publish';
		} elseif (is_array($arguments['post_status'])) {
			$arguments['post_status'] = array_map('sanitize_title', $arguments['post_status']);
		} else {
			$arguments['post_status'] = sanitize_title($arguments['post_status']);
		}

		$posts = get_posts(array(
			'post_type'			=> 'tsml_meeting',
			'numberposts'		=> -1,
			'post_status'		=> $arguments['post_status'],
		));

		$meeting_meta = tsml_get_meta('tsml_meeting');
		$groups = tsml_get_groups();	
		$locations = tsml_get_locations();
		$users = tsml_get_users();

		//make an array of the meetings
		foreach ($posts as $post) {
			//shouldn't ever happen, but just in case
			if (empty($locations[$post->post_parent])) continue;

			//append to array
			$meeting = array_merge(array(
				'id'				=> $post->ID,
				'name'				=> $post->post_title,
				'slug'				=> $post->post_name,
				'notes'				=> $post->post_content,
				'updated'			=> $post->post_modified_gmt,
				'location_id'		=> $post->post_parent,
				'url'				=> get_permalink($post->ID),
				'day'				=> @$meeting_meta[$post->ID]['day'],
				'time'				=> @$meeting_meta[$post->ID]['time'],
				'end_time'			=> @$meeting_meta[$post->ID]['end_time'],
				'time_formatted'	=> tsml_format_time(@$meeting_meta[$post->ID]['time']),
				'email'				=> @$meeting_meta[$post->ID]['email'],
				'website'			=> @$meeting_meta[$post->ID]['website'],
				'website_2'			=> @$meeting_meta[$post->ID]['website_2'],
				'phone'				=> @$meeting_meta[$post->ID]['phone'],
				'types'				=> empty($meeting_meta[$post->ID]['types']) ? array() : array_values(unserialize($meeting_meta[$post->ID]['types'])),
			), $locations[$post->post_parent]);

			//append group info to meeting
			if (!empty($meeting_meta[$post->ID]['group_id']) && array_key_exists($meeting_meta[$post->ID]['group_id'], $groups)) {
				$meeting = array_merge($meeting, $groups[$meeting_meta[$post->ID]['group_id']]);
			}

			$meetings[] = $meeting;
		}
	}

	//check if we are filtering
	$allowed = array('mode', 'day', 'time', 'region', 'district', 'type', 'query', 'group_id', 'location_id', 'latitude', 'longitude', 'distance_units', 'distance');
	if ($arguments = array_intersect_key($arguments, array_flip($allowed))) {
		$filter = new tsml_filter_meetings($arguments);
		$meetings = $filter->apply($meetings);
	}

	usort($meetings, 'tsml_sort_meetings');

	//dd($meetings);
	return $meetings;

}

//function: get metadata for all meetings very quickly
//called in tsml_get_meetings(), tsml_get_locations()
function tsml_get_meta($type, $id=null) {
	global $wpdb;
	//don't show contact information if user is not logged in
	//contact info still available on an individual meeting basis via tsml_get_meeting()
	$keys = array(
		'tsml_group' => '"website", "website_2", "email", "phone", "venmo", "last_contact"' . (current_user_can('edit_posts') ? ', "contact_1_name", "contact_1_email", "contact_1_phone", "contact_2_name", "contact_2_email", "contact_2_phone", "contact_3_name", "contact_3_email", "contact_3_phone"' : ''),
		'tsml_location' => '"formatted_address", "latitude", "longitude"',
		'tsml_meeting' => '"day", "time", "end_time", "types", "group_id", "website", "website_2", "email", "phone", "last_contact"' . (current_user_can('edit_posts') ? ', "contact_1_name", "contact_1_email", "contact_1_phone", "contact_2_name", "contact_2_email", "contact_2_phone", "contact_3_name", "contact_3_email", "contact_3_phone"' : ''),
	);
	if (!array_key_exists($type, $keys)) return trigger_error('tsml_get_meta for unexpected type ' . $type);
	$meta = array();
	$query = 'SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE 
		meta_key IN (' . $keys[$type] . ') AND 
		post_id ' . ($id ? '= ' . $id : 'IN (SELECT id FROM ' . $wpdb->posts . ' WHERE post_type = "' . $type . '")');
	$values = $wpdb->get_results($query);
	foreach ($values as $value) {
		$meta[$value->post_id][$value->meta_key] = $value->meta_value;
	}
	
	//if location, get region
	if ($type == 'tsml_location') {
		$regions = $wpdb->get_results('SELECT 
				r.`object_id` location_id,
				t.`term_id` region_id,
				t.`name` region
			FROM ' . $wpdb->term_relationships . ' r
			JOIN ' . $wpdb->term_taxonomy . ' x ON r.term_taxonomy_id = x.term_taxonomy_id
			JOIN ' . $wpdb->terms . ' t ON x.term_id = t.term_id
			WHERE x.taxonomy = "tsml_region"');
		foreach ($regions as $region) {
			$meta[$region->location_id]['region'] = $region->region;
			$meta[$region->location_id]['region_id'] = $region->region_id;
		}
	} elseif ($type == 'tsml_group') {
		$districts = $wpdb->get_results('SELECT 
				r.`object_id` group_id,
				t.`term_id` district_id,
				t.`name` district
			FROM ' . $wpdb->term_relationships . ' r
			JOIN ' . $wpdb->term_taxonomy . ' x ON r.term_taxonomy_id = x.term_taxonomy_id
			JOIN ' . $wpdb->terms . ' t ON x.term_id = t.term_id
			WHERE x.taxonomy = "tsml_district"');
		foreach ($districts as $district) {
			$meta[$district->group_id]['district'] = $district->district;
			$meta[$district->group_id]['district_id'] = $district->district_id;
		}
	}
	
	if ($id) return $meta[$id];
	return $meta;
}

//function get author usernames & ids
//used by tsml_get_meetings() and import ajax
function tsml_get_users($keyed_by_id=true) {
	$users = get_users(array(
		'fields' => array('ID', 'user_login'),
	));
	foreach ($users as $user) {
		$return[$user->ID] = $user->user_login;
	}
	return ($keyed_by_id) ? $return : array_flip($return);
}

//return spelled-out meeting types
//called from save.php (updates) and archive-meetings.php (display)
function tsml_meeting_types($types) {
	global $tsml_programs, $tsml_program;
	if (empty($tsml_programs[$tsml_program]['types'])) return;
	$return = array();
	foreach ($types as $type) {
		if (array_key_exists($type, $tsml_programs[$tsml_program]['types'])) {
			$return[] = $tsml_programs[$tsml_program]['types'][$type];
		}
	}
	sort($return);
	return implode(', ', $return);
}

//sanitize and import an array of meetings to an 'import buffer' (an wp_option that's iterated on progressively)
//called from admin_import.php (both CSV and JSON)
function tsml_import_buffer_set($meetings, $data_source=null) {
	global $tsml_programs, $tsml_program, $tsml_days;
	
	//uppercasing for value matching later
	$upper_types = array_map('strtoupper', $tsml_programs[$tsml_program]['types']);
	$upper_days = array_map('strtoupper', $tsml_days);

	$row_counter = 1;

	//get users, keyed by username
	$users = tsml_get_users(false);
	$user_id = get_current_user_id();

	//convert the array to UTF-8
	array_walk_recursive($meetings, 'tsml_format_utf8');

	//trim everything
	array_walk_recursive($meetings, 'tsml_import_sanitize_field');
	
	//prepare array for import buffer
	foreach ($meetings as &$meeting) {
		$row_counter++;
		
		$meeting['data_source'] = $data_source;

		//do wordpress sanitization
		foreach ($meeting as $key => $value) {
			
			//have to compress types down real quick (only happens with json)
			if (is_array($value)) $value = implode(',', $value);
			
			if (in_array($key, array('notes', 'location_notes', 'group_notes'))) {
				$meeting[$key] = sanitize_text_area($value);
			} else {
				$meeting[$key] = sanitize_text_field($value);
			}
		}

		//column aliases
		if (empty($meeting['postal_code']) && !empty($meeting['zip'])) {
			$meeting['postal_code'] = $meeting['zip'];
		}
		if (empty($meeting['name']) && !empty($meeting['meeting'])) {
			$meeting['name'] = $meeting['meeting'];
		}
		if (empty($meeting['location']) && !empty($meeting['location_name'])) {
			$meeting['location'] = $meeting['location_name'];
		}
		if (empty($meeting['time']) && !empty($meeting['start_time'])) {
			$meeting['time'] = $meeting['start_time'];
		}

		//if '@' is in address, remove it and everything after
		if (!empty($meeting['address']) && $pos = strpos($meeting['address'], '@')) $meeting['address'] = trim(substr($meeting['address'], 0, $pos));
		
		//if location name is missing, use address
		if (empty($meeting['location'])) {
			$meeting['location'] = empty($meeting['address']) ? __('Meeting Location', '12-step-meeting-list') : $meeting['address'];
		}
		
		//day can either be 0, 1, 2, 3 or Sunday, Monday, or empty
		if (isset($meeting['day']) && !array_key_exists($meeting['day'], $upper_days)) {
			$meeting['day'] = array_search(strtoupper($meeting['day']), $upper_days);
		}

		//sanitize time & day
		if (empty($meeting['time']) || ($meeting['day'] === false)) {
			$meeting['time'] = $meeting['end_time'] = $meeting['day'] = false; //by appointment

			//if meeting name missing, use location
			if (empty($meeting['name'])) $meeting['name'] = sprintf(__('%s by Appointment', '12-step-meeting-list'), $meeting['location']);
		} else {
			//if meeting name missing, use location, day, and time
			if (empty($meeting['name'])) {
				$meeting['name'] = sprintf(__('%s %ss at %s', '12-step-meeting-list'), $meeting['location'], $tsml_days[$meeting['day']], $meeting['time']);
			}

			$meeting['time'] = tsml_format_time_reverse($meeting['time']);
			if (!empty($meeting['end_time'])) $meeting['end_time'] = tsml_format_time_reverse($meeting['end_time']);
		}

		//google prefers USA for geocoding
		if (!empty($meeting['country']) && $meeting['country'] == 'US') $meeting['country'] = 'USA'; 
		
		//build address
		if (empty($meeting['formatted_address'])) {
			$address = array();
			if (!empty($meeting['address'])) $address[] = $meeting['address'];
			if (!empty($meeting['city'])) $address[] = $meeting['city'];
			if (!empty($meeting['state'])) $address[] = $meeting['state'];
			if (!empty($meeting['postal_code'])) {
				if ((strlen($meeting['postal_code']) < 5) && ($meeting['country'] == 'USA')) $meeting['postal_code'] = str_pad($meeting['postal_code'], 5, '0', STR_PAD_LEFT);
				$address[] = $meeting['postal_code'];	
			}
			if (!empty($meeting['country'])) $address[] = $meeting['country'];
			$meeting['formatted_address'] = implode(', ', $address);
		}

		//notes
		if (empty($meeting['notes'])) $meeting['notes'] = '';
		if (empty($meeting['location_notes'])) $meeting['location_notes'] = '';
		if (empty($meeting['group_notes'])) $meeting['group_notes'] = '';

		//updated
		if (empty($meeting['updated']) || (!$meeting['updated'] = strtotime($meeting['updated']))) $meeting['updated'] = time();
		$meeting['post_modified'] = date('Y-m-d H:i:s', $meeting['updated']);
		$meeting['post_modified_gmt'] = get_gmt_from_date($meeting['post_modified']);
		
		//author
		if (!empty($meeting['author']) && array_key_exists($meeting['author'], $users)) {
			$meeting['post_author'] = $users[$meeting['author']];
		} else {
			$meeting['post_author'] = $user_id;
		}

		//default region to city if not specified
		if (empty($meeting['region']) && !empty($meeting['city'])) $meeting['region'] = $meeting['city'];

		//sanitize types (they can be Closed or C)
		if (empty($meeting['types'])) $meeting['types'] = '';
		$types = explode(',', $meeting['types']);
		$meeting['types'] = $unused_types = array();
		foreach ($types as $type) {
			$upper_type = trim(strtoupper($type));
			if (array_key_exists($upper_type, $upper_types)) {
				$meeting['types'][] = $upper_type;
			} elseif (in_array($upper_type, array_values($upper_types))) {
				$meeting['types'][] = array_search($upper_type, $upper_types);
			} else {
				$unused_types[] = $type;
			}
		}
		
		//if a meeting is both open and closed, make it closed
		if (in_array('C', $meeting['types']) && in_array('O', $meeting['types'])) {
			$meeting['types'] = array_diff($meeting['types'], array('O'));
		}
		
		//append unused types to notes
		if (count($unused_types)) {
			if (!empty($meeting['notes'])) $meeting['notes'] .= str_repeat(PHP_EOL, 2);
			$meeting['notes'] .= implode(', ', $unused_types);
		}

		//clean up
		foreach(array('address', 'city', 'state', 'postal_code', 'country', 'updated') as $key) {
			if (isset($meeting[$key])) unset($meeting[$key]);
		}
		
		//preserve row number for errors later
		$meeting['row'] = $row_counter;
		
	}

	//allow user-defined function to filter the meetings (for gal-aa.org)
	if (function_exists('tsml_import_filter')) {
		$meetings = array_filter($meetings, 'tsml_import_filter');
	}
	
	//dd($meetings);
	
	//prepare import buffer in wp_options
	update_option('tsml_import_buffer', $meetings, false);
}

//function:	filter workaround for setting post_modified dates
//used:		tsml_ajax_import()
function tsml_import_post_modified($data, $postarr) {
	if (!empty($postarr['post_modified'])) {
		$data['post_modified'] = $postarr['post_modified'];
	}
	if (!empty($postarr['post_modified_gmt'])) {
		$data['post_modified_gmt'] = $postarr['post_modified_gmt'];
	}
	return $data;
}

//function: handle FNV (GSO) imports
//used:		tsml_import() - put here for code isolation
function tsml_import_reformat_fnv($rows) {

	$meetings = array();
	
	$header = array_shift($rows);

	//dd($header);
	
	$short_days = array('SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT');
	$days = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
	$all_types = array();
	
	foreach ($rows as $row) {
	
		$row = array_combine($header, $row);
		
		if ($row['Type'] !== 'Regular') continue;
		
		for ($number = 1; $number < 5; $number++) {
			foreach ($short_days as $index => $day) {
				$key = 'Meeting' . $number . $day . 'Times';
				if (!empty($row[$key])) {
					$times = explode('  ', strtolower($row[$key]));
					foreach ($times as $time) {
						$time_parts = explode('(', str_replace(')', '', $time));
						$time = array_shift($time_parts);
						$types = array();
						foreach ($time_parts as $part) {
							if (($part == 'c') || strstr($part, 'closed')) {
								$types[] = 'Closed';
							} elseif (($part == 'o') || strstr($part, 'open')) {
								$types[] = 'Open';
							}
							if (strstr($part, 'women') || strstr($part, 'lady')) {
								$types[] = 'Women';
							} elseif (strstr($part, 'men')) {
								$types[] = 'Men';
							}
							if (strstr($part, 'bb') || strstr($part, 'big book')) $types[] = 'Big Book';
							if (strstr($part, '12') || strstr($part, 'step')) $types[] = 'Step Study';
							if (strstr($part, 'candlelight')) $types[] = 'Candlelight';
							if (strstr($part, 'speaker')) $types[] = 'Speaker';
							if (strstr($part, 'disc')) $types[] = 'Discussion';
							if (strstr($part, 'newcomer') || strstr($part, 'new comer')) $types[] = 'Newcomer';
							if (strstr($part, 'grapevine')) $types[] = 'Grapevine';
							if (strstr($part, 'spanish')) $types[] = 'Spanish';
						}
						$all_types = array_merge($all_types, $types);
						
						//add language as type
						$types[] = $row['Language'];
						
						if ($pos = strpos($row['Meeting' . $number . 'Addr1'], '(')) {
							$row['Meeting' . $number . 'Addr1'] = substr($row['Meeting' . $number . 'Addr1'], 0, $pos);
							$row['Meeting' . $number . 'Comments'] .= PHP_EOL . substr($row['Meeting' . $number . 'Addr1'], $pos);
						}

						$meetings[] = array(
							'Name' => $row['GroupName'],
							'Day' => $days[$index],
							'Time' => $time,
							'Address' => $row['Meeting' . $number . 'Addr1'],
							'City' => $row['Meeting' . $number . 'City'],
							'State' => $row['Meeting' . $number . 'StateCode'],
							'Postal Code' => $row['Meeting' . $number . 'Zip'],
							'Country' => $row['CountryCode'],
							'Types' => implode(', ', $types),
							'Region' => $row['City'],
							'Group' => $row['GroupName'] . ' #' . $row['ServiceNumber'],
							'Website' => $row['Website'],
							'Updated' => $row['DateChanged'],
							'District' => $row['District'] ? sprintf(__('District %s', '12-step-meeting-list'), $row['District']) : null,
							'Last Contact' => $row['DateChanged'],
							'Notes' => $row['Meeting' . $number . 'Comments'],
							'Contact 1 Name' => $row['PrimaryFirstName'] . ' ' . $row['PrimaryLastName'],
							'Contact 1 Phone' => preg_replace('~\D~', '', $row['PrimaryPrimaryPhone']),
							'Contact 1 Email' => substr($row['PrimaryPrimaryEmail'], strpos($row['PrimaryPrimaryEmail'], ' ') + 1),
							'Contact 2 Name' => $row['SecondaryFirstName'] . ' ' . $row['SecondaryLastName'],
							'Contact 2 Phone' => preg_replace('~\D~', '', $row['SecondaryPrimaryPhone']),
							'Contact 2 Email' => substr($row['SecondaryPrimaryEmail'], strpos($row['SecondaryPrimaryEmail'], ' ') + 1),
						);
					}
				}
			}
		}
	}
	
	//debugging types
	$all_types = array_unique($all_types);
	sort($all_types);
	
	$return = array(array_keys($meetings[0]));
	foreach ($meetings as $meeting) {
		$return[] = array_values($meeting);
	}
	return $return;
}

//function: turn "string" into string
//used:		tsml_ajax_import() inside array_map
function tsml_import_sanitize_field($value) {
	//preserve <br>s as line breaks if present, otherwise clean up
	$value = preg_replace('/\<br(\s*)?\/?\>/i', PHP_EOL, $value);
	$value = stripslashes($value);
	$value = trim($value);

	//turn "string" into string (only do if on both ends though)
	if ((substr($value, 0, 1) == '"') && (substr($value, -1) == '"')) {
		$value = trim(trim($value, '"'));
	}
	
	return $value;
}

//function: return an html link with current query string appended -- this is because query string permalink structure is an enormous pain in the ass
//used:		archive-meetings.php, single-locations.php, single-meetings.php
function tsml_link($url, $string, $exclude='', $class=false) {
	$appends = $_GET;
	if (array_key_exists($exclude, $appends)) unset($appends[$exclude]);
	if (!empty($appends)) {
		$url .= strstr($url, '?') ? '&' : '?';
		$url .= http_build_query($appends, '', '&amp;');
	}
	$return = '<a href="' . $url . '"';
	if ($class) $return .= ' class="' . $class . '"';
	$return .= '>' . $string . '</a>';
	return $return;
}

//function: link to meetings page with parameters (added to link dropdown menus for SEO)
//used:		archive-meetings.php
function tmsl_meetings_url($parameters) {
	$url = get_post_type_archive_link('tsml_meeting');
	$url .= (strpos($url, '?') === false) ? '?' : '&';
	$url .= http_build_query($parameters);
	return $url;
}

//function: convert line breaks in plain text to HTML paragraphs
//used:		save.php in lieu of nl2br()
function tsml_paragraphs($string) {
	$paragraphs = '';
	foreach (explode("\n", trim($string)) as $line) {
		if ($line = trim($line)) {
			$paragraphs .= '<p>' . $line . '</p>';
		}
	}
	return $paragraphs;
}

//function: set an option with the currently-used types
//used: 	tsml_import() and save.php
function tsml_update_types_in_use() {
	global $tsml_types_in_use, $wpdb;
	
	//shortcut to getting all meta values without getting all posts first
	$types = $wpdb->get_col('SELECT
			m.meta_value 
		FROM ' . $wpdb->postmeta . ' m
		JOIN ' . $wpdb->posts . ' p ON m.post_id = p.id
		WHERE p.post_type = "tsml_meeting" AND m.meta_key = "types" AND p.post_status = "publish"');
		
	//master array
	$all_types = array();
	
	//loop through results and append to master array
	foreach ($types as $type) {
		$type = unserialize($type);
		if (is_array($type)) $all_types = array_merge($all_types, $type);
	}
	
	//update global variable
	$tsml_types_in_use = array_unique($all_types);
	
	//set option value
	update_option('tsml_types_in_use', $tsml_types_in_use);
}

//function:	helper to debug out of memory errors
//used:		ad-hoc
function tsml_report_memory() {
	$size = memory_get_peak_usage(true);
	$units = array('B', 'KB', 'MB', 'GB');
	die(round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . $units[$i]);
}

//function:	sanitize a time field
//used:		save.php
function tsml_sanitize_time($string) {
	$string = sanitize_text_field($string);
	if ($time = strtotime($string)) return date('H:i', $time);
	return null;
}

//function: sort an array of meetings
//used: as a callback in tsml_get_meetings()
//method: sort by 
//	1) day, following "week starts on" user preference, with appointment meetings last, 
//	2) followed by time, where the day starts at 5am, 
//	3) followed by location name, 
//	4) followed by meeting name
function tsml_sort_meetings($a, $b) {
	global $tsml_days_order, $tsml_sort_by;

	//sub_regions are regions in this scenario
	if (!empty($a['sub_region'])) $a['region'] = $a['sub_region'];
	if (!empty($b['sub_region'])) $b['region'] = $b['sub_region'];

	//custom sort order?
	if ($tsml_sort_by !== 'time') {
		if ($a[$tsml_sort_by] != $b[$tsml_sort_by]) {
			return strcmp($a[$tsml_sort_by], $b[$tsml_sort_by]);
		}
	}

	//get the user-settable order of days
	$a_day_index = isset($a['day']) && strlen($a['day']) ? array_search($a['day'], $tsml_days_order) : false;
	$b_day_index = isset($b['day']) && strlen($b['day']) ? array_search($b['day'], $tsml_days_order) : false;
	if ($a_day_index === false && $b_day_index !== false) {
		return 1;
	} elseif ($a_day_index !== false && $b_day_index === false) {
		return -1;
	} elseif ($a_day_index != $b_day_index) {
		return $a_day_index - $b_day_index;
	} else {
		//days are the same or both null
		if ($a['time'] != $b['time']) {
			$a_time = ($a['time'] == '00:00') ? '23:59' : $a['time'];
			$b_time = ($b['time'] == '00:00') ? '23:59' : $b['time'];
			return strcmp($a_time, $b_time);
		} else {
			if ($a['location'] != $b['location']) {
				return strcmp($a['location'], $b['location']);
			} else {
				return strcmp($a['name'], $b['name']);
			}
		}
	}
}

//function:	tokenize string for the typeaheads
//used:		ajax functions
function tsml_string_tokens($string) {

	//shorten words that have quotes in them instead of splitting them
	$string = html_entity_decode($string);
	$string = str_replace("'", '', $string);
	$string = str_replace('’', '', $string);
	
	//remove everything that's not a letter or a number
	$string = preg_replace("/[^a-zA-Z 0-9]+/", ' ', $string);
	
	//return array
	return array_values(array_unique(array_filter(explode(' ', $string))));
}