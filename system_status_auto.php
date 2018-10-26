#!/usr/bin/php
<?php

use \MSI\system_status_auto\Container;

if ($argc != 6) {
	echo 'Usage: ' . basename(__FILE__) . ' host_name service_name service_state service_state_type host_name' . "\n";
	exit(1);
}

$cachet_notify_subscribers = true; // Enable subscribers notifcation for incidents creation and updates
$cachet_incident_visible = true;

$host_name = $argv[1];
$service_name = $argv[2];
$service_status = $argv[3];
$service_status_type = $argv[4];

define('CACHET_STATUS_INVESTIGATING', 1);
define('CACHET_STATUS_IDENTIFIED', 2);
define('CACHET_STATUS_WATCHING', 3);
define('CACHET_STATUS_FIXED', 4);

define('CACHET_COMPONENT_STATUS_OPERATIONAL', 1);
define('CACHET_COMPONENT_STATUS_PERFORMANCE_ISSUES', 2);
define('CACHET_COMPONENT_STATUS_PARTIAL_OUTAGE', 3);
define('CACHET_COMPONENT_STATUS_MAJOR_OUTAGE', 4);

function cachet_query($api_part, $action = 'GET', $data = null) {
	global $api_key, $cachet_url;

	print_r($data);

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $cachet_url . $api_part);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

	if (in_array($action, array('GET', 'POST', 'PUT'))) {
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $action);
	}

	if ($data !== null && is_array($data)) {
		$ch_data = http_build_query($data);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $ch_data);
	}

	$ch_headers = array(
		'X-Cachet-Token: ' . $api_key
	);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $ch_headers);

	curl_setopt($ch, CURLOPT_HEADER, false); // Don't return headers
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return body
	$http_body = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	return array('code' => $http_code, 'body' => json_decode($http_body));
}

function nagios_services_exclude_matching($services_array, $host, $service){
	$non_matching_services = array();
	foreach ($services_array as $item) {
		if ($item->host != $host && $item->service != $service){
			array_push($non_matching_services, $item);
		}
	}
	return $non_matching_services;
}

$C = Container::getDefaultContainer();
$NagiosStatusGetter = $C['NagiosStatusGetter'];

$config = $C['config'];
$cachet_url = $config['cachet_api']['url'];
$api_key = $config['cachet_api']['api_key'];

$cache_component_lookup = cachet_query('components');
if ($result['code'] != 200) {
	echo 'Can\'t query components' . "\n";
	exit(1);
}

$cache_components = get_affected_cachet_components($host_name);
foreach ($cache_components as $cachet_component) {
	/* Find Cachet component ID */

	$cachet_component_id = false;
	foreach ($cache_component_lookup['body']->data as $component) {
		if ($cachet_component == $component->name) { // We nailed it
			$cachet_component_id = $component->id;
			break; // Yes, bad.
		}
	}
	if ($cachet_component_id === false) {
		echo 'Can\'t find component "' . $cachet_component . '"' . "\n";
		exit(1);
	}

	/*Find existing Incidents for the component */
	$incidents_lookup = cachet_query("incidents?component_id=${cachet_component_id}&sort=id&order=desc&per_page=10");
	if ($result['code'] != 200) {
		echo 'Can\'t get incidents' . "\n";
		exit(1);
	}
	$cachet_incident_id = false;
	foreach ($incidents_lookup['body']->data as $incident) {
		if ($incident->status != CACHET_STATUS_FIXED) {
			$cachet_incident_id = $incident->id;
			break; // Yes, bad.
		}
	}

	//Update component only if there is no existing incidents
	if ($cachet_incident_id == false) {
		$related_services = $config[$cachet_component]['nagios_services'];
		$related_services = nagios_services_exclude_matching($related_services, $host_name, $service_name);
		/**
		* @var NagiosService[] $related_system_statuses
		*/
		$related_system_statuses = $NagiosStatusGetter->get($related_services);
		$current_nagios_service = new NagiosService();
		$current_nagios_service->host = $host_name;
		$current_nagios_service->service = $service_name;
		$current_nagios_service->status = $service_status;

		array_push($related_system_statuses, [$current_nagios_service]);
		$service_aggregator_id = $config[$cachet_component]['service_aggregator'];
		/**
		* @var ServiceAggregatorInterface $aggregator
		*/
		$aggregator = $C[$service_aggregator_id];
		$cachet_status = $aggregator->aggregate($related_system_statuses);

		$query = array(
			//'component' => $cachet_component_id,
			'status' => $cachet_status ,
		);
		$result = cachet_query('components/' . $cachet_component_id, 'PUT', $query);
		if ($result['code'] != 200) {
			echo 'Can\'t update component' . "\n";
			exit(1);
		}

	exit(0);
}


