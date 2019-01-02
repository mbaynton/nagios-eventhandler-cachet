# Cachet system status updates from Nagios

This project is a Nagios [event handler](https://assets.nagios.com/downloads/nagioscore/docs/nagioscore/3/en/eventhandlers.html)
that can update a public systems status page powered by [Cachet](https://cachethq.io/) when Nagios detects
changes to services on your infrastructure.

It is a derivative of [mpellegrin/nagios-eventhandler-cachet](https://github.com/mpellegrin/nagios-eventhandler-cachet),
but is differentiated by:
  * A configuration file based ability to consider the status of more than one
    service, or services across multiple hosts when deciding how to update a component
    of the system status page. This could be useful when you have more than one host
    behind a load balancer that performs the same task, for example.
  * A mechanism to allow operators to manually pin a component on your status page to a
    given status, in cases where your nagios checks are incorrect. To do this, simply
    open an incident for a component. As long as a component has an open (not "fixed") incident,
    this script will not change the status of the component.

## Installation
  * Clone this repository into a new directory  
  * Run the command `composer install` inside the cloned directory.
      * [Install Composer](https://getcomposer.org/download/) if you don't have it.
      
## Configuration
  * Copy `config.sample.yaml` to `nagios_eventhandler_cachet.yaml` or `/etc/nagios_eventhandler_cachet.yaml`.
  * Get a Cachet API key:
      * Create a new user in Cachet dashboard
      * login with this user
      * get the API key in his profile.
  * Update `config.yaml` to contain the url to your cachet server's API and the API key from above.
      * These go in `cachet_api/url` and `cachet_api/api_key`.
  * Update `config.yaml` to contain the url to your nagios server's json cgi endpoints, and optionally an http username
    and password if your nagios instance is protected by some form of HTTP authentication. 
      * These go in `nagios_api/url` and optionally `nagios_api/username` and `nagios_api/password`.
      
### Cachet Component to Nagios Service maping configuration
The most interesting part of your config file maps Cachet Components (these are the individual systems
you can set the status of) to Nagios service(s) on particular host(s).

From the `config.sample.yaml` sample configuration, we have:
```yaml
components:
  Login Nodes:
    service_aggregator: degrade_if_any_fail_if_all
    nagios_services:
      - host: sfec1
        service: SSH
      - host: sfec2
        service: SSH
      - host: sfec3
        service: SSH
``` 
In this sample,
  * "Login Nodes" is the name of a Cachet Component on your status page
  * We are saying that this component depends on the "SSH" nagios service on three different
    hosts ("sfec1", "sfec2", and "sfec3".)
  * In order to combine the three statuses of SSH from these hosts into a single status
    shown by Cachet for the "Login Nodes" component, we use a `service_aggregator` called
    `degrade_if_any_fail_if_all` that sets the status to Operational if all of the nagios
     services are ok, Major Outage if all of the nagios services are not ok, and Partial
     Outage if some of the nagios services are not ok.
     * Other logic can be added / used instead. The currently available values for
     `service_aggregator` are defined in [this source file](https://github.umn.edu/mbaynton/nagios-eventhandler-cachet/blob/master/src/ServiceAggregator/ServiceAggregatorProvider.php).
     
## Try it out
Set up at least one Cachet `component` and its underlying `nagios_services` in your config file.
Then, run  
```
./system_status_auto.php '[hostname]' '[service name]' CRITICAL HARD
```
where `[hostname]` matches a `host:` line in your config file and `service:` matches a service.