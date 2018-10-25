<?php


namespace MSI\system_status_auto\ServiceAggregator;


interface ServiceAggregatorInterface
{
  function aggregate(array $nagios_services) : int;
}