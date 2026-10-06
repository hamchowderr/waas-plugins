# WaaS Host & Client Plugins

Two plugins used to create a communication between a host and multiple tenants in a multi-tenant WordPress setup.

## Features

- Tenant Creation
- Subscription Management
- Role-based Plugins Activation
- Adding new Add-ons
- Changing Domains
- Root-Domain for created tenants
- Tenants domains
- Single-Sign On

## Local Development

### Required tools

- Docker and Docker-Compose
- Composer
- `fswarch` & `rsync`

### Steps

- `git clone` the project
- Run `composer install` inside `src` directory
- Run to create dist directories  `mkdir dist && mkdir dist/host && mkdir dist/client`
- Run `docker-compose up`
- Run the following command to sync files between src and dist

## API base URL

The Host plugin talks to the provisioning API at the URL in **Settings → API Base URL**, or the `WPCS_API_BASE_URL` constant in `wp-config.php` (the constant wins). Without either, it falls back to the old WPCS host for the selected region. The `wpcs_settings_api_base_url` filter can change it.

```php
define('WPCS_API_BASE_URL', 'https://2b5454f2-760b-4b86-8589-344ffae396d4.mock.pstmn.io'); // Postman mock
```

## Tests

```shell
cd packages/waas-host
composer install
vendor/bin/phpunit                      # all tests
vendor/bin/phpunit --exclude-group mock # offline only
```

Tests in the `mock` group run the plugin's real API client against the provisioning API, by default the public Postman mock. Set `WAAS_TEST_API_BASE_URL` to run them against another server, such as the local API.

## Building

Run the following command to build the plugins

```shell
bash scripts/build.sh
```
