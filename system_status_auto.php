#!/usr/bin/php
<?php

use \MSI\system_status_auto\Container;

require 'vendor/autoload.php';

if ($argc != 5) {
  echo 'Usage: ' . basename(__FILE__) . ' host_name service_name service_state service_state_type' . "\n";
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

// This helps avoid piling up lots of these processes if there is a connectivity issue and many nagios events.
function curl_apply_timeout($ch) {
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
}

function cachet_query($api_part, $action = 'GET', $data = null)
{
  global $api_key, $cachet_url;

  $ch = curl_init();
  $log_context = [];
  curl_setopt($ch, CURLOPT_URL, $cachet_url . $api_part);
  $log_context['url'] = $cachet_url . $api_part;
  $log_context['method'] = $action;

  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_apply_timeout($ch);

  if (in_array($action, array('GET', 'POST', 'PUT'))) {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $action);
  }

  if ($data !== null && is_array($data)) {
    $ch_data = http_build_query($data);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ch_data);
    $log_context['body'] = $ch_data;
  }

  $ch_headers = array(
    'X-Cachet-Token: ' . $api_key
  );
  curl_setopt($ch, CURLOPT_HTTPHEADER, $ch_headers);

  curl_setopt($ch, CURLOPT_HEADER, false); // Don't return headers
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return body
  $C = Container::getDefaultContainer();
  $C['logger']->debug('Query to cachet API', $log_context);
  $http_body = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $C['logger']->debug('Response from cachet API', ['code' => $http_code, 'body' => $http_body]);
  return array('code' => $http_code, 'body' => json_decode($http_body));
}

// Quick/cheap static cache
function get_cachet_components() {
  static $components = null;

  if ($components === null) {
    $C = Container::getDefaultContainer();
    $components = cachet_query('components');
    if ($components['code'] != 200) {
      $C['logger']->critical('Can\'t query cachet components.');
      exit(1);
    }
  }

  return $components;
}

function nagios_services_exclude_matching($services_array, $host, $service)
{
  $non_matching_services = array();
  foreach ($services_array as $item) {
    if ($item['host'] != $host || $item['service'] != $service) {
      array_push($non_matching_services, $item);
    }
  }
  return $non_matching_services;
}

$C = Container::getDefaultContainer();
/**
 * @var \MSI\system_status_auto\NagiosServiceGetterService $NagiosStatusGetter
 */
$NagiosStatusGetter = $C['NagiosStatusGetter'];

$config = $C['config'];
$cachet_url = $config['cachet_api']['url'];
$api_key = $config['cachet_api']['api_key'];

/** @var \Monolog\Logger $logger */
$logger = $C['logger'];
$logger->debug('Incoming raw event: ' . implode(' ', array_splice($argv, 1)), array_splice($argv, 1));

$cachet_components = $NagiosStatusGetter->getCachetComponentsAffectedByNagiosHostAndService($host_name, $service_name);
foreach ($cachet_components as $cachet_component) {
  /* Find Cachet component ID */

  $cachet_component_id = false;
  $cache_component_lookup = get_cachet_components();
  foreach ($cache_component_lookup['body']->data as $component) {
    if ($cachet_component == $component->name) { // We nailed it
      $cachet_component_id = $component->id;
      break; // Yes, bad.
    }
  }
  if ($cachet_component_id === false) {
    echo 'Can\'t find component "' . $cachet_component . '"' . "\n";
    $logger->critical("Cachet component \"${cachet_component}\" referenced in configuration does not exist on your cachet installation.");
    exit(1);
  }

  /*Find existing Incidents for the component */
  $incidents_lookup = cachet_query("incidents?component_id=${cachet_component_id}&sort=id&order=desc&per_page=10");
  if ($incidents_lookup['code'] != 200) {
    $logger->critical("Failure checking for existing Incidents for component ${cachet_component}.");
    continue;
  }
  $stickied_cachet_incident_id = false;
  $open_incidents = [];
  foreach ($incidents_lookup['body']->data as $incident) {
    if ($incident->stickied) {
      $stickied_cachet_incident_id = $incident->id;
      $logger->notice("Component \"${cachet_component}\" has stickied Incidents: will not automatically change.");
      break;
    }

    if ($incident->status != CACHET_STATUS_FIXED) {
      $open_incidents[] = $incident;
    }
  }

  //Update component and open incidents only if there is no stickied incident
  if ($stickied_cachet_incident_id == false) {
    $related_services = $config['components'][$cachet_component]['nagios_services'];
    $related_services = nagios_services_exclude_matching($related_services, $host_name, $service_name);
    $logger->info("Status change of ${service_name} on ${host_name} may affect status of component \"${cachet_component}.");
    $related_services_count = count($related_services);
    if ($related_services_count > 0) {
      $logger->info("Querying nagios for status of ${related_services_count} dependent services.", $related_services);
    }
    /**
     * @var NagiosService[] $related_system_statuses
     */
    $related_system_statuses = $NagiosStatusGetter->getCurrentNagiosStatus($related_services);
    $current_nagios_service = new \MSI\system_status_auto\NagiosService();
    $current_nagios_service->host = $host_name;
    $current_nagios_service->service = $service_name;
    $current_nagios_service->status = strtolower($service_status);

    array_push($related_system_statuses, $current_nagios_service);
    $service_aggregator_id = $config['components'][$cachet_component]['service_aggregator'];
    /**
     * @var \MSI\system_status_auto\ServiceAggregator\ServiceAggregatorInterface $aggregator
     */
    $aggregator = $C['aggregator.' . $service_aggregator_id];
    $cachet_status = $aggregator->aggregate($related_system_statuses);

    $query = array(
      //'component' => $cachet_component_id,
      'status' => $cachet_status,
    );

    $result = cachet_query('components/' . $cachet_component_id, 'PUT', $query);
    if ($result['code'] != 200) {
      $logger->critical("Failure updating status of component \"${cachet_component}\" (#${cachet_component_id})");
    } else {
      $logger->notice("Component ${cachet_component} (${cachet_component_id}) status set to ${cachet_status}.");
    }

    if ($cachet_status == CACHET_COMPONENT_STATUS_OPERATIONAL) {
      // Also mark any open, non-stickied incidents related to the component as fixed.
      $query = [
        'component_status' => $cachet_status,
        'status' => CACHET_STATUS_FIXED
      ];
      foreach ($open_incidents as $incident) {
        $result = cachet_query('incidents/' . $incident->id, 'PUT', $query);
        if ($result['code'] != 200) {
          $logger->critical("Failure updating status of incident \"{$incident->name}\" (#{$incident->id})");
        } else {
          $logger->notice("Incident \"{$incident->name}\" (#{$incident->id}) status set to fixed.");
        }
      }
    }
  }
}


