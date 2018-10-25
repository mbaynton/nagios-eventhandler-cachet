<?php


namespace MSI\system_status_auto\ServiceAggregator;
use MSI\system_status_auto\NagiosService;

/**
 * Class DegradeAnyFailAll
 *
 * Calls the cachet service OPERATIONAL if all the nagios services are OK,
 * Calls the cachet service ARTIAL_OUTAGE if 1 - n-1 of the nagios services are not OK,
 * Calls the cachet service MAJOR_OUTAGE if all n of the nagios services are not OK
 */
class DegradeAnyFailAll implements ServiceAggregatorInterface
{
  public function aggregate(array $nagios_services): int
  {
    $num_not_ok = 0;
    foreach($nagios_services as $nagios_service) {
      /**
       * @var NagiosService $nagios_service
       */
      if($nagios_service->status !== 'ok') {
        $num_not_ok++;
      }
    }

    if ($num_not_ok == 0) {
      return CACHET_COMPONENT_STATUS_OPERATIONAL;
    } else if ($num_not_ok > 0 && $num_not_ok < count($nagios_services)) {
      return CACHET_COMPONENT_STATUS_PARTIAL_OUTAGE;
    } else {
      return CACHET_COMPONENT_STATUS_MAJOR_OUTAGE;
    }
  }
}