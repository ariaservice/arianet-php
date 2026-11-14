<?php

declare(strict_types=1);

namespace Arianet\Http;

use Arianet\Exception\ConnectionException;

final class CurlTransport implements TransportInterface
{
    private const MAX_RESPONSE_BYTES = 32 * 1024 * 1024;

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $handle = curl_init();
        $responseHeaders = [];
        $buffer = '';
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 30) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer): int {
                if (strlen($buffer) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $buffer .= $chunk;

                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        if ($ok === false || $status === 0) {
            throw new ConnectionException('request failed: ' . ($error !== '' ? $error : 'no response'));
        }

        return new HttpResponse($status, $responseHeaders, $buffer);
    }
}
