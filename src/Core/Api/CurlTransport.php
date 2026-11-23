<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Api;

/**
 * HTTP transport based on ext-curl. ILIAS 11 no longer ships Guzzle, so the plugin
 * uses curl directly on all versions.
 */
class CurlTransport implements HttpTransport
{
    public const TIMEOUT = 30;
    public const CONNECT_TIMEOUT = 10;

    /**
     * @param string|null $proxy proxy as "host:port", null for a direct connection
     */
    public function __construct(private readonly ?string $proxy = null)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new ApiException(0, 'curl could not be initialised');
        }
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        if ($this->proxy !== null && $this->proxy !== '') {
            curl_setopt($curl, CURLOPT_PROXY, $this->proxy);
        }
        $response = curl_exec($curl);
        if ($response === false) {
            $error = curl_error($curl) ?: 'curl error ' . curl_errno($curl);
            throw new ApiException(0, $error);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return new HttpResponse($status, (string) $response, $responseHeaders);
    }
}
