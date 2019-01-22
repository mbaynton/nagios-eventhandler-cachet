<?php


namespace MSI\system_status_auto;

use Cascade\Cascade;
use GuzzleHttp\Client;
use MSI\system_status_auto\ServiceAggregator\ServiceAggregatorProvider;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Container
 * The DI container.
 */
class Container extends \Pimple\Container {

  /**
   * @var Container $default
   * The instantiated default container, used by all legacy api functions
   * that now simply wrap DI-aware counterparts.
   */
  protected static $default = null;

  public function __construct(array $values = array()) {
    parent::__construct($values);

    // Register services, primarily through delegation to service providers.
    $this['config'] = $this->loadTheConfig();
    $this->register(new ServiceAggregatorProvider());
    $this->registerServices();
  }

  protected function registerServices() {
    $this['NagiosStatusGetter'] = function($c) {
      return new NagiosServiceGetterService($c['HttpClient'], $c['logger'], $c['config']);
    };

    $this['HttpClient'] = function () {
      return new Client();
    };

    $this['logger'] = function($c) {
      Cascade::loadConfigFromArray($c['config']);
      return Cascade::getLogger('general');
    };
  }

  protected function loadTheConfig() {
    $locations = ['nagios_eventhandler_cachet.yaml', '/etc/nagios_eventhandler_cachet.yaml'];
    foreach($locations as $filename) {
      if (is_file($filename)) {
        $parsed_array = Yaml::parseFile($filename);

        return $parsed_array;
      }
    }

    throw new \RuntimeException('No configuration file found. Create ' . implode(' or ', $locations));
  }

  /**
   * Gets the default container.
   *
   * @return \MSI\system_status_auto\Container
   */
  public static function getDefaultContainer() {
    if (self::$default === null) {
      self::$default = new self();
    }
    return self::$default;
  }
}
