<?php


namespace MSI\system_status_auto;

/**
 * Class NagiosService
 *
 * Struct / data model class
 */
class NagiosService
{
  /**
   * @var string $host
   */
  public $host;

  /**
   * @var string $service
   */
  public $service;

  /**
   * @var string $status
   * ok | warning | critical | unknown
   */
  public $status;
}