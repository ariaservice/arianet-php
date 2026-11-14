<?php

declare(strict_types=1);

namespace Arianet\Tests;

use Arianet\Client;
use Arianet\ErrorCode;
use Arianet\Exception\ApiException;
use Arianet\Exception\ArianetException;
use Arianet\Exception\BadRequestException;
use Arianet\Exception\ConnectionException;
use Arianet\Exception\NotFoundException;
use Arianet\Exception\PaymentRequiredException;
use Arianet\Exception\PermissionDeniedException;
use Arianet\Exception\RateLimitException;
use Arianet\Exception\ServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private static string $dir;
    /** @var resource */
    private static $process;
    private static string $url;

    /** @var list<float> */
    private array $waits = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/arianet-php-mock-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = 'http://127.0.0.1:' . $port;
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/Fixtures/router.php'];
        $env = array_merge(getenv(), ['ARIANET_MOCK_DIR' => self::$dir]);
        self::$process = proc_open($cmd, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, $env);
        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }
        self::fail('mock server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$process);
        proc_close(self::$process);
        array_map('unlink', glob(self::$dir . '/*') ?: []);
        rmdir(self::$dir);
    }

    /**
     * @param list<array{status: int, body?: string, headers?: array<string, string>}> $replies
     * @param array<string, mixed> $options
     */
    private function client(array $replies, array $options = []): Client
    {
        file_put_contents(self::$dir . '/replies.json', json_encode($replies));
        file_put_contents(self::$dir . '/seen.json', '[]');
        $this->waits = [];

        return new Client('tok_123', $options + [
            'base_url' => self::$url,
            'sleep' => function (float $s): void {
                $this->waits[] = $s;
            },
        ]);
    }

    /** @return list<array{method: string, uri: string, headers: array<string, string>, body: string}> */
    private function seen(): array
    {
        return json_decode((string) file_get_contents(self::$dir . '/seen.json'), true);
    }

    public function testAuthHeadersPathAndQuery(): void
    {
        $client = $this->client([['status' => 200, 'body' => '{"data":[{"id":1}],"meta":{}}']]);
        $result = $client->cloud->listServers(['page' => 2, 'label_selector' => 'env=prod']);
        $seen = $this->seen()[0];
        self::assertSame('Bearer tok_123', $seen['headers']['authorization']);
        self::assertStringStartsWith('arianet-php/', $seen['headers']['user-agent']);
        self::assertSame('GET', $seen['method']);
        self::assertSame('/cloud/v1/servers?page=2&label_selector=env%3Dprod', $seen['uri']);
        self::assertSame(1, $result['data'][0]['id']);
    }

    public function testTokenFromEnvironment(): void
    {
        $this->client([['status' => 200, 'body' => '{"success":true,"data":{}}']]);
        putenv('ARIANET_TOKEN=env_tok');
        try {
            $client = new Client(null, ['base_url' => self::$url]);
        } finally {
            putenv('ARIANET_TOKEN');
        }
        $client->core->getMe();
        self::assertSame('Bearer env_tok', $this->seen()[0]['headers']['authorization']);
    }

    public function testPathParamsAreEscaped(): void
    {
        $client = $this->client([['status' => 200]]);
        $client->core->getInvoice('INV/1 2');
        self::assertSame('/api/v1/invoices/INV%2F1%202', $this->seen()[0]['uri']);
    }

    public function testRetryOn429HonorsRetryAfter(): void
    {
        $client = $this->client([
            ['status' => 429, 'body' => '{"code":"rate_limited","message":"slow down"}', 'headers' => ['Retry-After' => '7']],
            ['status' => 200],
        ]);
        $client->sms->sendMessage(['to' => ['0912']]);
        $seen = $this->seen();
        self::assertCount(2, $seen);
        self::assertSame([7.0], $this->waits);
        self::assertSame($seen[0]['body'], $seen[1]['body']);
    }

    public function testRetryOn429UsesRateLimitReset(): void
    {
        $client = $this->client([['status' => 429, 'headers' => ['RateLimit-Reset' => '3']], ['status' => 200]]);
        $client->cloud->listDatacenters();
        self::assertSame([3.0], $this->waits);
    }

    public function testRetryAfterLongerThanMaxThrows(): void
    {
        $client = $this->client([[
            'status' => 429,
            'body' => '{"success":false,"error":{"code":"RATE_LIMIT_EXCEEDED","message":"Too many"}}',
            'headers' => ['Retry-After' => '3600'],
        ]]);
        try {
            $client->core->listRegions();
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(ErrorCode::RATE_LIMIT_EXCEEDED, $e->getErrorCode());
            self::assertSame(3600.0, $e->getRetryAfter());
        }
        self::assertSame([], $this->waits);
        self::assertCount(1, $this->seen());
    }

    public function testRetriesAreBounded(): void
    {
        $client = $this->client([['status' => 503], ['status' => 503], ['status' => 503]], ['max_retries' => 2]);
        try {
            $client->cloud->listVolumes();
            self::fail('expected ServerException');
        } catch (ServerException) {
        }
        self::assertCount(3, $this->seen());
    }

    public function testUnsafeRequestNotRetriedOn503(): void
    {
        $client = $this->client([['status' => 503, 'body' => '{"error":"busy","code":"core_unavailable"}']]);
        try {
            $client->cloud->createVolume(['size' => 10]);
            self::fail('expected ServerException');
        } catch (ServerException $e) {
            self::assertSame(ErrorCode::CORE_UNAVAILABLE, $e->getErrorCode());
        }
        self::assertCount(1, $this->seen());
    }

    public function testIdempotentOperationKeepsKeyAcrossRetries(): void
    {
        $client = $this->client([['status' => 502], ['status' => 202, 'body' => '{"success":true,"data":{"id":9}}']]);
        $client->core->createServer(['plan_id' => 1]);
        $seen = $this->seen();
        self::assertCount(2, $seen);
        self::assertNotEmpty($seen[0]['headers']['idempotency-key']);
        self::assertSame($seen[0]['headers']['idempotency-key'], $seen[1]['headers']['idempotency-key']);
    }

    public function testExplicitIdempotencyKey(): void
    {
        $client = $this->client([['status' => 200]]);
        $client->cloud->createVolume([], ['idempotency_key' => 'k-1']);
        self::assertSame('k-1', $this->seen()[0]['headers']['idempotency-key']);
    }

    public function testConnectionErrorRetriedForReads(): void
    {
        $this->waits = [];
        $client = new Client('t', [
            'base_url' => 'http://127.0.0.1:1',
            'max_retries' => 2,
            'sleep' => function (float $s): void {
                $this->waits[] = $s;
            },
        ]);
        try {
            $client->cloud->listImages();
            self::fail('expected ConnectionException');
        } catch (ConnectionException) {
        }
        self::assertCount(2, $this->waits);
    }

    public function testConnectionErrorNotRetriedForUnsafeWrites(): void
    {
        $this->waits = [];
        $client = new Client('t', ['base_url' => 'http://127.0.0.1:1', 'sleep' => function (float $s): void {
            $this->waits[] = $s;
        }]);
        try {
            $client->cloud->createVolume([]);
            self::fail('expected ConnectionException');
        } catch (ConnectionException) {
        }
        self::assertSame([], $this->waits);
    }

    /**
     * @return iterable<string, array{int, string, class-string, string, string, ?string}>
     */
    public static function errorShapes(): iterable
    {
        yield 'core' => [402, '{"success":false,"error":{"code":"INSUFFICIENT_BALANCE","message":"Not enough balance"}}', PaymentRequiredException::class, ErrorCode::INSUFFICIENT_BALANCE, 'Not enough balance', null];
        yield 'cloud' => [403, '{"error":"Verify your identity","code":"identity_needed","reason":"identity_hold"}', PermissionDeniedException::class, ErrorCode::IDENTITY_NEEDED, 'Verify your identity', 'identity_hold'];
        yield 'product' => [404, '{"code":"not_found","message":"No such server"}', NotFoundException::class, ErrorCode::NOT_FOUND, 'No such server', null];
        yield 'not json' => [400, '<html>bad</html>', BadRequestException::class, 'unexpected_response', 'request failed with HTTP 400', null];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('errorShapes')]
    public function testErrorShapes(int $status, string $body, string $class, string $code, string $message, ?string $reason): void
    {
        $client = $this->client([['status' => $status, 'body' => $body, 'headers' => ['X-Request-Id' => 'req-1']]]);
        try {
            $client->hosting->listAccounts();
            self::fail('expected ' . $class);
        } catch (ApiException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame(
                [$status, $code, $message, $reason, 'req-1'],
                [$e->getStatusCode(), $e->getErrorCode(), $e->getMessage(), $e->getReason(), $e->getRequestId()],
            );
        }
    }

    public function testSuccessFalseWith200IsAnError(): void
    {
        $client = $this->client([['status' => 200, 'body' => '{"success":false,"error":{"code":"NO_DEFAULT_WALLET","message":"No wallet"}}']]);
        try {
            $client->core->getBalance();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::NO_DEFAULT_WALLET, $e->getErrorCode());
        }
    }

    public function testRegionHeader(): void
    {
        $client = $this->client([['status' => 200], ['status' => 200]]);
        $client->regionalVps->withRegion('iran-1')->listServers();
        $client->regionalVps->listServers(['region' => 'iran-2']);
        $seen = $this->seen();
        self::assertSame('iran-1', $seen[0]['headers']['arianet-region']);
        self::assertSame('iran-2', $seen[1]['headers']['arianet-region']);
    }

    public function testRedirectIsNotFollowed(): void
    {
        $client = $this->client([['status' => 302, 'body' => '', 'headers' => ['Location' => self::$url . '/steal']]]);
        try {
            $client->core->getMe();
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(302, $e->getStatusCode());
        }
        self::assertCount(1, $this->seen());
    }

    public function testBaseUrlMustBeHttps(): void
    {
        $this->expectException(ArianetException::class);
        new Client('t', ['base_url' => 'http://api.example.com']);
    }

    public function testRequestForUngeneratedPaths(): void
    {
        $client = $this->client([['status' => 200, 'body' => '{"ok":true}']]);
        self::assertSame(['ok' => true], $client->request('GET', '/cloud/v1/something-new', ['a' => true]));
        self::assertSame('/cloud/v1/something-new?a=true', $this->seen()[0]['uri']);
    }

    public function testBodyIsJson(): void
    {
        $client = $this->client([['status' => 201]]);
        $client->cloud->createVolume(['name' => 'data', 'size' => 10]);
        $seen = $this->seen()[0];
        self::assertSame(['name' => 'data', 'size' => 10], json_decode($seen['body'], true));
        self::assertSame('application/json', $seen['headers']['content-type']);
    }
}
