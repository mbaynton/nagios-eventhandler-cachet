<?php


namespace MSI\system_status_auto;


use Concat\Http\Middleware\Logger as LogMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;

/**
 * Class NagiosServiceGetterService
 *
 * Gets nagios service status info for a given host and service.
 *
 * @see https://monitor.msi.umn.edu/nagios/cgi-bin/statusjson.cgi?query=help
 */
class NagiosServiceGetterService
{
  /**
   * @var Client $client
   */
  protected $client;

  /**
   * @var array $appConfig
   */
  protected $appConfig;

  /**
   * @var array $cachetComponentByHostAndServiceLookup
   */
  protected $cachetComponentByHostAndServiceLookup;

  /** @var LogMiddleware $logMiddleware */
  protected $logMiddleware;

  public function __construct(ClientInterface $client, Logger $logger, array $config)
  {
    $this->client = $client;
    $this->appConfig = $config;
    $this->logMiddleware = new LogMiddleware($logger);
    $this->logMiddleware->setLogLevel(LogLevel::DEBUG);
    $this->logMiddleware->setRequestLoggingEnabled(true);

    $this->buildCachetComponentByHostAndServiceLookup();
  }

  protected function getClient() {
    return $this->client;
  }

  public function getCurrentNagiosStatus(array $nagiosHostServices) {
    $guzzle_stack = HandlerStack::create();
    $guzzle_stack->push($this->logMiddleware);

    $nagios_api_info = $this->appConfig['nagios_api'];
    $url_template = "${nagios_api_info['url']}/statusjson.cgi?query=service&hostname=%s&servicedescription=%s&formatoptions=enumerate";
    $request_options = ['handler' => $guzzle_stack, 'connect_timeout' => 5, 'timeout' => 10];
    if (! empty($nagios_api_info['username']) || ! empty($nagios_api_info['password'])) {
      $request_options[RequestOptions::AUTH] = [$nagios_api_info['username'], $nagios_api_info['password']];
    }

    $requests = [];
    $responses = [];

    foreach ($nagiosHostServices as $hostService) {
      $request = new Request(
        'GET',
        sprintf($url_template, $hostService['host'], $hostService['service'])
      );
      $requests[] = $request;
    }

    $pool = new Pool($this->client, $requests, [
      'concurrency' => 4,
      'options'     => $request_options,
      'fulfilled'   => function ($response, $index) use (&$responses) {
        $responses[] = $response;
      },
      'rejected'    => function ($reason, $index) {
        throw new \Exception('Nagios JSON API call failed: ' . $reason);
      }
    ]);

    $pool->promise()->wait();

    $output = [];
    foreach ($responses as $response) {
      /**
       * @var ResponseInterface $response
       */
      $nagios_response_json = $response->getBody()->getContents();
      $nagios_response = \GuzzleHttp\json_decode($nagios_response_json, TRUE);

      if ($nagios_response['result']['type_text'] !== 'Success') {
        throw new \Exception('Nagios JSON API response did not indicate success; type_text is ' . $nagios_response['result']['type_text']);
      }

      $nagios_service = new NagiosService();
      $nagios_service->host = $nagios_response['data']['service']['host_name'];
      $nagios_service->service = $nagios_response['data']['service']['description'];
      $nagios_service->status = $nagios_response['data']['service']['last_hard_state'];

      $output[] = $nagios_service;
    }

    return $output;
  }

  public function getCachetComponentsAffectedByNagiosHostAndService($host, $service) {
    if (! empty($this->cachetComponentByHostAndServiceLookup[$host][$service])) {
      return $this->cachetComponentByHostAndServiceLookup[$host][$service];
    }

    return [];
  }

  protected function buildCachetComponentByHostAndServiceLookup() {
    foreach ($this->appConfig['components'] as $component => $componentConfig) {
      foreach ($componentConfig['nagios_services'] as $nagios_service) {
        $this->cachetComponentByHostAndServiceLookup[$nagios_service['host']][$nagios_service['service']][] = $component;
      }
    }
  }
}
