<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Api;

use Virtualbadge\Core\Clock;
use Virtualbadge\Core\Config\Settings;
use Virtualbadge\Core\EnglishTexts;
use Virtualbadge\Core\Store\Cache;
use Virtualbadge\Core\Store\RateLimitStore;
use Virtualbadge\Core\Texts;

/**
 * Client for the Virtualbadge.io API v2.
 *
 * Each organisation has its own API key. Templates are cached for ten minutes,
 * calls are limited per API key and hour as configured for the organisation.
 */
class ApiClient
{
    /** Seconds templates are kept in the cache. */
    public const TEMPLATE_TTL = 600;

    /** Minimum seconds between two forced template reloads. */
    public const REFRESH_MIN_INTERVAL = 30;

    /** Metadata key of the reference that identifies a recipient created by this ILIAS installation. */
    public const METADATA_KEY = 'ilias_ref';

    public function __construct(
        private readonly Settings $settings,
        private readonly HttpTransport $http,
        private readonly Cache $cache,
        private readonly RateLimitStore $calls,
        private readonly Clock $clock,
        private readonly Texts $texts = new EnglishTexts()
    ) {
    }

    public function isConfigured(string $org): bool
    {
        return $this->settings->apiKey($org) !== '';
    }

    /**
     * Active templates of an organisation.
     *
     * @param bool $refresh load again unless loaded in the last 30 seconds
     * @return list<array{id: string, name: string, type: string, fields: string[], slots: array<string, string>}>
     */
    public function templates(string $org, bool $refresh = false): array
    {
        $key = 'templates:' . $this->keyHash($org);
        $hit = $this->cache->get($key);
        $max_age = $refresh ? self::REFRESH_MIN_INTERVAL : self::TEMPLATE_TTL;
        if ($hit !== null && isset($hit['time'], $hit['templates']) && $this->clock->now() - (int) $hit['time'] < $max_age) {
            return $hit['templates'];
        }

        $templates = [];
        $page = 1;
        do {
            $result = $this->request($org, 'GET', 'certificate/', ['status' => 'active', 'page' => $page]);
            foreach ($result['data'] ?? [] as $certificate) {
                if (is_array($certificate) && isset($certificate['id'])) {
                    $templates[] = self::template($certificate);
                }
            }
            $page++;
        } while (!empty($result['next']));

        $this->cache->set($key, ['time' => $this->clock->now(), 'templates' => $templates]);
        return $templates;
    }

    /**
     * Template record from a certificate of the API. Text fields have string values,
     * image slots have a list of variants of which the first one is used.
     *
     * @param array<string, mixed> $certificate
     * @return array{id: string, name: string, type: string, fields: string[], slots: array<string, string>}
     */
    public static function template(array $certificate): array
    {
        $fields = [];
        $slots = [];
        foreach ($certificate['dynamic_fields'] ?? [] as $name => $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    $slots[(string) $name] = (string) reset($value);
                }
            } else {
                $fields[] = (string) $name;
            }
        }
        return [
            'id' => (string) $certificate['id'],
            'name' => (string) ($certificate['name'] ?? ''),
            'type' => (string) ($certificate['type'] ?? 'certificate'),
            'fields' => $fields,
            'slots' => $slots,
        ];
    }

    /**
     * Create one recipient.
     *
     * @param array<string, mixed> $recipient email, full_name, issue_date, dynamic_fields, metadata
     * @return array<string, mixed> see {@see self::result()}
     */
    public function issue(string $org, string $template_id, array $recipient, bool $send_email): array
    {
        $response = $this->request($org, 'POST', 'recipient/', [], [
            'certificate' => $template_id,
            'send_emails' => $send_email,
            'add_duplicates' => true,
            'recipients' => [$recipient],
        ]);
        if (empty($response['data'][0]) || !is_array($response['data'][0]) || empty($response['data'][0]['id'])) {
            throw new ApiException(502, 'no recipient in response');
        }
        return self::result($response['data'][0]);
    }

    /**
     * Recipient created earlier with the given reference.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $org, string $template_id, string $reference): ?array
    {
        $response = $this->request($org, 'GET', 'recipient/', [
            'certificate' => $template_id,
            'metadata.' . self::METADATA_KEY => $reference,
        ]);
        // Never trust the filter alone: a recipient of another user must not be taken for this one.
        foreach ($response['data'] ?? [] as $recipient) {
            if (is_array($recipient) && !empty($recipient['id'])
                && (($recipient['metadata'] ?? [])[self::METADATA_KEY] ?? null) === $reference) {
                $result = self::result($recipient);
                // A recipient whose generation failed is not taken: the retry creates a new one.
                if ($result['status'] !== 'failed') {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Replace a recipient by a new one with current data. The old recipient becomes void.
     *
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    public function replace(string $org, string $remote_id, array $recipient): array
    {
        unset($recipient['metadata']);
        return self::result($this->request($org, 'POST', 'recipient/' . self::id($remote_id) . '/replace/', [], $recipient));
    }

    /**
     * Recipient that replaced the given one.
     *
     * @return array<string, mixed>|null
     */
    public function findReplacement(string $org, string $template_id, string $email, string $remote_id): ?array
    {
        $page = 1;
        do {
            $response = $this->request($org, 'GET', 'recipient/', [
                'certificate' => $template_id,
                'email' => $email,
                'page' => $page,
            ]);
            foreach ($response['data'] ?? [] as $recipient) {
                if (is_array($recipient) && ($recipient['replacement_for'] ?? null) === $remote_id) {
                    return self::result($recipient);
                }
            }
            $page++;
        } while (!empty($response['next']));
        return null;
    }

    /**
     * Ask Virtualbadge.io to send the email with the certificate link again.
     *
     * @return array<string, mixed>
     */
    public function sendEmail(string $org, string $remote_id): array
    {
        $response = $this->request($org, 'POST', 'recipient/' . self::id($remote_id) . '/send-email/', [], []);
        return isset($response['id']) ? self::result($response) : ['id' => $remote_id];
    }

    /**
     * Current state of a recipient.
     *
     * @return array<string, mixed>
     */
    public function status(string $org, string $remote_id): array
    {
        return self::result($this->request($org, 'GET', 'recipient/' . self::id($remote_id) . '/'));
    }

    /**
     * Issue result from a recipient of the API.
     *
     * @param array<string, mixed> $recipient
     * @return array{id: string, status: string, certificate_url: ?string, validation_url: ?string, email_status: ?string}
     */
    public static function result(array $recipient): array
    {
        $email_status = isset($recipient['email_status']) ? (string) $recipient['email_status'] : null;
        if (in_array($email_status, ['revoked', 'voided'], true)) {
            $status = 'revoked';
        } elseif (($recipient['generation_state'] ?? '') === 'generated') {
            $status = 'issued';
        } elseif (($recipient['generation_state'] ?? '') === 'failed') {
            // Not in the public API documentation; named by the Virtualbadge.io developers.
            $status = 'failed';
        } else {
            $status = 'pending';
        }
        return [
            'id' => (string) ($recipient['id'] ?? ''),
            'status' => $status,
            'certificate_url' => self::httpsUrl($recipient['access_page_url'] ?? null),
            'validation_url' => self::httpsUrl($recipient['validation_page_url'] ?? null),
            'email_status' => $email_status,
        ];
    }

    /**
     * A link from Virtualbadge.io, only if it is a valid https URL.
     */
    public static function httpsUrl(mixed $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        return preg_match('~^https://[^/?#\s]+~i', $url) ? $url : null;
    }

    /**
     * Id of a Virtualbadge.io object for use in a request path.
     *
     * @throws ApiException if the id has unexpected characters
     */
    public static function id(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new ApiException(400, 'invalid id');
        }
        return $id;
    }

    /**
     * Fail if the hourly limit of the organisation's API key is reached, without reserving a call.
     *
     * @throws ApiException 429 when no call is left
     */
    public function checkSlot(string $org): void
    {
        $limit = $this->settings->rateLimit($org);
        if ($limit === 0) {
            return;
        }
        $this->assertBelowLimit($this->recentCalls($this->keyHash($org)), $limit);
    }

    /**
     * Send a request to the API.
     *
     * @param array<string, scalar> $params query parameters
     * @param array<string, mixed>|null $body JSON body
     * @return array<string, mixed>
     */
    protected function request(string $org, string $method, string $path, array $params = [], ?array $body = null): array
    {
        $api_key = $this->settings->apiKey($org);
        if ($api_key === '') {
            throw new ApiException(401, $this->texts->get('msg_no_api_key'));
        }
        $this->takeSlot($org);

        $url = $this->settings->apiUrl() . '/v2/public/' . $path
            . ($params !== [] ? '?' . http_build_query($params, '', '&') : '');
        $json = null;
        if ($body !== null) {
            $json = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $response = $this->http->send($method, $url, [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $json);
        $decoded = json_decode($response->body, true);

        if ($response->status < 200 || $response->status >= 300) {
            if ($response->status === 401 || $response->status === 403) {
                $detail = $this->texts->get('msg_api_key_rejected');
            } else {
                $detail = mb_substr(
                    is_array($decoded) ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE) : $response->body,
                    0,
                    500
                );
            }
            $retry = $response->status === 429 ? ($this->retryAfter($response) ?? 60) : null;
            throw new ApiException($response->status, $detail, $retry);
        }
        if (!is_array($decoded)) {
            throw new ApiException(502, 'invalid JSON');
        }
        return $decoded;
    }

    /**
     * Reserve one call within the hourly limit of the organisation's API key.
     *
     * @throws ApiException 429 when no call is left
     */
    protected function takeSlot(string $org): void
    {
        $limit = $this->settings->rateLimit($org);
        if ($limit === 0) {
            return;
        }
        $key = $this->keyHash($org);
        try {
            $this->calls->locked($key, function () use ($key, $limit): void {
                $this->assertBelowLimit($this->recentCalls($key), $limit);
                $now = $this->clock->now();
                $this->calls->add($key, $now, $now - 3600);
            });
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Includes ilAtomQueryException, which is no RuntimeException.
            throw new ApiException(429, $this->texts->get('msg_lock'), 5, true);
        }
    }

    /**
     * @return int[]
     */
    protected function recentCalls(string $key): array
    {
        return $this->calls->calls($key, $this->clock->now() - 3600);
    }

    /**
     * Throw when the calls of the last hour reach the limit, with the time until the oldest one expires.
     *
     * @param int[] $calls oldest first
     */
    protected function assertBelowLimit(array $calls, int $limit): void
    {
        if (count($calls) >= $limit) {
            $wait = max(1, $calls[count($calls) - $limit] + 3600 - $this->clock->now());
            throw new ApiException(429, $this->texts->get('msg_hourly_limit'), $wait, true);
        }
    }

    /**
     * Same API address and key share cache and limit.
     */
    protected function keyHash(string $org): string
    {
        return sha1($this->settings->apiUrl() . '|' . $this->settings->apiKey($org));
    }

    protected function retryAfter(HttpResponse $response): ?int
    {
        $value = $response->header('Retry-After');
        return $value !== null && is_numeric($value) ? (int) $value : null;
    }
}
