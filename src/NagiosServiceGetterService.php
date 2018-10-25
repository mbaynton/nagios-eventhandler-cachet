<?php


namespace MSI\system_status_auto;


use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

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

  public function __construct(ClientInterface $client, array $config)
  {
    $this->client = $client;
  }

  protected function getClient() {
    return $this->client;
  }

  public function get(array $nagiosHostServices) {
    $url_template = 'https://monitor.msi.umn.edu/nagios/cgi-bin/statusjson.cgi?query=service&hostname=%s&servicedescription=%s&formatoptions=enumerate';
    $requests = [];
    $responses = [];

    foreach ($nagiosHostServices as $hostService) {
      $requests[] = new Request('GET', sprintf($url_template, $hostService['host'], $hostService['service']));
    }

    $pool = new Pool($this->client, $requests, [
      'concurrency' => 4,
      'fulfilled' => function ($response, $index) use ($responses) {
        $responses[] = $response;
      },
      'rejected' => function ($reason, $index) {
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
}
