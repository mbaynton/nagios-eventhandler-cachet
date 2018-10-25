<?php


namespace MSI\system_status_auto;

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
    $this->registerServices();
  }

  protected function registerServices() {
    $this['NagiosStatusGetter'] = function($c) {
      return new NagiosServiceGetterService($c['HttpClient'], $c['config']);
    };
  }

  protected function loadTheConfig() {

  }

  /**
   * Gets the default container.
   *
   * @return \MSI\system_status_auto\Container
   */
  public static function getDefault() {
    if (self::$default === null) {
      self::$default = new self();
    }
    return self::$default;
  }
}
