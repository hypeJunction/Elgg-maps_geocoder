<?php

/**
 * Geocoder
 *
 * @author Ismayil Khayredinov <info@hypejunction.com>
 * @copyright Copyright (c) 2015, Ismayil Khayredinov
 */
require_once __DIR__ . '/autoloader.php';

elgg_register_event_handler('init', 'system', 'maps_geocoder_init');
elgg_register_event_handler('upgrade', 'system', 'maps_geocoder_upgrade');

/**
 * Initialize
 * @return void
 */
function maps_geocoder_init() {

	elgg_register_plugin_hook_handler('geocode', 'location', 'maps_geocoder_geocode_hook');

	foreach (array('user', 'object', 'group', 'site') as $type) {
		elgg_register_event_handler('create', $type, 'maps_geocoder_geocode_location_metadata');
		elgg_register_event_handler('update', $type, 'maps_geocoder_geocode_location_metadata');
	}
}

/**
 * Geocodes location
 * @param string $location Location
 * @return array
 */
function maps_geocoder_geocode($location = '') {
	return elgg_trigger_plugin_hook('geocode', 'location', ['location' => $location]);
}

/**
 * Geocode location using Google Maps geocoding API
 * 
 * @param string $hook   "geocode"
 * @param string $type   "location"
 * @param mixed  $return Lat/long
 * @param array  $params Hook params
 * @return mixed
 */
function maps_geocoder_geocode_hook($hook, $type, $return, $params) {

	if (!empty($return)) {
		// location has been geocoded elsewhere
		return;
	}

	$location = elgg_extract('location', $params);

	// Try geocache
	$site = elgg_get_site_entity();
	$location_hash = md5($location);

	$file = new ElggFile();
	$file->owner_guid = $site->guid;
	$file->setFilename("geocache/$location_hash.json");

	if ($file->exists()) {
		$file->open('read');
		$json = $file->grabFile();
		$file->close();
	} else {
		$client = google();
		$http_client = new Google_IO_Curl($client);
		$url = elgg_http_add_url_query_elements('https://maps.googleapis.com/maps/api/geocode/json', array(
			'address' => $location,
		));

		$request = new Google_Http_Request($url);

		$response = $http_client->executeRequest($request);
		$json = $response[0];
		if (!$json) {
			json_encode(array('address' => $location));
		}
		$file->open('write');
		$file->write($json);
		$file->close();
	}

	if (!$json) {
		return;
	}

	$data = json_decode($json, true);

	return array(
		'lat' => $data['results'][0]['geometry']['location']['lat'],
		'long' => $data['results'][0]['geometry']['location']['lng'],
	);
}

/**
 * Check if entity location has changed and geocode if so
 * 
 * @param string     $event  "create"|"update"
 * @param string     $type   "object"|"user"|"group"
 * @param ElggEntity $entity Entity
 */
function maps_geocoder_geocode_location_metadata($event, $type, $entity) {

	if ($entity->geocoded_location == $entity->location) {
		return;
	}

	if (is_array($entity->location)) {
		return;
	}

	if ($entity->getLatitude() && $entity->getLongitude()) {
		$entity->geocoded_location = $entity->location;
		return;
	}

	// Clear previous values
	unset($entity->{"geo:lat"});
	unset($entity->{"geo:long"});
	$entity->geocoded_location = $entity->location;

	if (!$entity->location) {
		return;
	}

	$coordinates = maps_geocoder_geocode($entity->location);
	$lat = elgg_extract('lat', $coordinates) ? : '';
	$long = elgg_extract('long', $coordinates) ? : '';

	$entity->setLatLong($lat, $long);
}

/**
 * Update entity geocoordinates
 * @return void
 */
function maps_geocoder_upgrade() {
	if (!elgg_is_admin_logged_in()) {
		return;
	}

	set_time_limit(0);

	$exclude = array(
		'messages',
		'plugin',
		'widget',
		'site_notification',
	);

	foreach ($exclude as $k => $e) {
		$exclude[$k] = get_subtype_id('object', $e);
	}
	$exclude_ids = implode(',', array_filter($exclude));

	$location_md = elgg_get_metastring_id('location');
	$lat_md = elgg_get_metastring_id('geo:lat');
	$long_md = elgg_get_metastring_id('geo:long');

	$dbprefix = elgg_get_config('dbprefix');
	$entities = new ElggBatch('elgg_get_entities', array(
		'limit' => 0,
		'wheres' => array(
			($exclude_ids) ? "e.subtype NOT IN ($exclude_ids)" : null,
			"EXISTS (SELECT 1 FROM {$dbprefix}metadata WHERE entity_guid = e.guid AND name_id = $location_md)",
			"(NOT EXISTS (SELECT 1 FROM {$dbprefix}metadata WHERE entity_guid = e.guid AND name_id = $lat_md)
				OR NOT EXISTS (SELECT 1 FROM {$dbprefix}metadata WHERE entity_guid = e.guid AND name_id = $long_md))",
		)
	));
	$entities->setIncrementOffset(false);

	$i = 0;
	foreach ($entities as $e) {
		// trigger update
		$e->save();
		$lat = $e->getLatitude();
		$long = $e->getLongitude();
		elgg_log("New coordinates for {$e->getDisplayName()} ({$e->type}:{$e->getSubtype()} $e->guid) [$lat, $long]");
		$i++;
	}

	system_message("Location metadata has been geocoded for $i entities");
}
