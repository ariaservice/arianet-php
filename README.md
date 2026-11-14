# arianet-php

The official PHP client for the [Arianet](https://ariaservice.net) public API: Cloud, classic and
regional VPS, hosting, dedicated servers (bare metal), SMS, and your account (API tokens, SSH keys,
firewalls, balance, invoices).

- One method per API operation, generated from the published OpenAPI specs.
- Personal API token auth, retries on `429` that honor `Retry-After`, safe retries of reads and
  idempotent writes after network errors and `502`/`503`/`504`.
- Typed exceptions carrying the API's stable error codes.
- PHP 8.1 or newer with `ext-curl` and `ext-json`; no other dependencies.

Documentation: <https://docs.ariaservice.net/sdk/php>

## Install

```bash
composer require ariaservice/arianet
```

Until the package is listed on Packagist, add the GitHub repository first:

```bash
composer config repositories.arianet vcs https://github.com/ariaservice/arianet-php
composer require ariaservice/arianet:^0.1
```

## Quickstart

Create a personal API token at <https://cloud.ariaservice.net/users/api-tokens> and export it:

```bash
export ARIANET_TOKEN="paste-your-token-here"
```

```php
<?php

require __DIR__ . '/vendor/autoload.php';

$client = new Arianet\Client(); // reads ARIANET_TOKEN

$page = $client->cloud->listServers(['per_page' => 50, 'label_selector' => 'env=prod']);
foreach ($page['data'] as $server) {
    echo $server['id'], ' ', $server['name'], ' ', $server['status'], PHP_EOL;
}
```

## API groups

| Property               | API                                                               | Path prefix     |
|------------------------|-------------------------------------------------------------------|-----------------|
| `$client->core`        | Account, API tokens, classic VPS, SSH keys, firewalls, billing    | `/api/v1`       |
| `$client->cloud`       | Cloud servers, volumes, networks, firewalls, IPs, load balancers  | `/cloud/v1`     |
| `$client->regionalVps` | Regional VPS                                                      | `/vps/v1`       |
| `$client->hosting`     | Hosting accounts, DNS, mailboxes, databases                       | `/hosting/v1`   |
| `$client->baremetal`   | Dedicated servers                                                 | `/baremetal/v1` |
| `$client->sms`         | SMS messages, templates, lines, webhooks                          | `/sms/v1`       |

Method names are the camelCase `operationId` of each operation in the API reference
(<https://docs.ariaservice.net/api-reference>). Arguments come in this order: path parameters,
the JSON request body (an array), the query parameters (an array, documented as an array shape on
each method), then `$options` (`idempotency_key`, `headers`, `region`). Every method returns the
decoded JSON response as arrays.

```php
$server = $client->cloud->createServer([
    'name' => 'web-1',
    'plan_id' => 101,           // from $client->cloud->listPlans()
    'image' => 'ubuntu-24.04',  // an image slug from $client->cloud->listImages()
    'datacenter' => 'ovh',      // a datacenter code from $client->cloud->listDatacenters()
]);
```

## Configuration

```php
$client = new Arianet\Client('token', [        // default token: ARIANET_TOKEN
    'base_url' => 'https://api.ariaservice.net', // default, or ARIANET_API_URL
    'timeout' => 60.0,                           // seconds per HTTP attempt
    'max_retries' => 3,
    'max_retry_wait' => 60.0,                    // longest wait before one retry
    'user_agent' => 'my-app/1.2',
    // 'transport' => new MyTransport(),         // any Arianet\Http\TransportInterface
]);
```

The base URL must use `https` (plain `http` is accepted for `localhost` only). The client never
follows redirects, so the token is only ever sent to the API host.

## Retries and rate limits

- `429 Too Many Requests` is retried for every method, after the `Retry-After` header (or
  `RateLimit-Reset`). If the server asks for a longer wait than `max_retry_wait`,
  `Arianet\Exception\RateLimitException` is thrown at once with `getRetryAfter()` set.
- Network errors and `502`/`503`/`504` are retried only when repeating cannot do the work twice:
  `GET`/`HEAD` requests and requests with an `Idempotency-Key`.
- Operations that accept an `Idempotency-Key` (creating a server, an order, a hosting account...)
  get a generated key automatically, so they are retried safely. Pass
  `['idempotency_key' => '...']` in `$options` to make retries across process restarts safe too.

## Errors

```php
use Arianet\ErrorCode;
use Arianet\Exception\ApiException;
use Arianet\Exception\ConnectionException;

try {
    $client->core->createServer($order);
} catch (ApiException $e) {
    if ($e->getErrorCode() === ErrorCode::INSUFFICIENT_BALANCE) {
        // top up the wallet
    }
    error_log(sprintf('HTTP %d %s: %s (request %s)', $e->getStatusCode(), $e->getErrorCode(), $e->getMessage(), $e->getRequestId()));
} catch (ConnectionException $e) {
    // no answer: DNS, TLS, timeout
}
```

`getErrorCode()` is the API's stable machine code, always lower-cased (the core API's
`INSUFFICIENT_BALANCE` becomes `insufficient_balance`), so it can be compared with the generated
`Arianet\ErrorCode` constants. Branch on the code, not on the message. `getReason()` narrows the
code on some refusals (for example `identity_hold`).

Exception classes by status (namespace `Arianet\Exception`): `BadRequestException` (400),
`AuthenticationException` (401), `PaymentRequiredException` (402), `PermissionDeniedException`
(403), `NotFoundException` (404), `ConflictException` (409), `UnprocessableEntityException` (422),
`RateLimitException` (429), `ServerException` (5xx); all extend `ApiException`, which extends
`ArianetException`.

## Regions

Product APIs with regional installs take the `Arianet-Region` header:

```php
$iran = $client->regionalVps->withRegion('iran-1');
$servers = $iran->listServers();
// or per request:
$servers = $client->regionalVps->listServers(['region' => 'iran-1']);
```

## Endpoints not in this version

`$client->request($method, $path, $query, $body, $options)` calls any path with the same auth,
retries and error handling:

```php
$client->request('GET', '/cloud/v1/new-endpoint', ['page' => 2]);
```

## How the code is generated

`specs/` holds the published OpenAPI documents and `codegen/services.json` lists where they come
from. `src/Service/*Service.php` (except `AbstractService.php`), `src/ErrorCode.php` and
`src/Services.php` are produced by `codegen/generate.py` (Python standard library only):

```bash
python3 codegen/fetch_specs.py   # refresh specs/ (needs: pip install pyyaml)
python3 codegen/generate.py
python3 codegen/generate.py --check
```

The *Regenerate from specs* workflow does this every day and opens a pull request when the
published specs change. The rest of `src/` is hand-written and never touched by the generator.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT, see [LICENSE](LICENSE). Report security problems privately, see [SECURITY.md](SECURITY.md).
