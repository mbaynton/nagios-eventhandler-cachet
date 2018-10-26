<?php


namespace MSI\system_status_auto\ServiceAggregator;


use Pimple\Container;
use Pimple\ServiceProviderInterface;

class ServiceAggregatorProvider implements ServiceProviderInterface {
  public function register(Container $pimple) {
    $pimple['aggregator.degrade_if_any_fail_if_all'] = function($c) {
      return new DegradeIfAnyFailIfAll();
    };
  }
}
