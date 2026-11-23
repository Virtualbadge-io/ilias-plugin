<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Config;

/**
 * Plugin settings: API address, Virtualbadge.io organisations with API key and hourly limit,
 * date format and the mapping of categories to organisations.
 *
 * The main organisation has the key "main". Further organisations are configured as lines
 * "key|name"; each has its own API key and limit.
 */
class Settings
{
    public const MAIN_ORG = 'main';
    public const DEFAULT_API_URL = 'https://api.virtualbadge.io';
    public const DEFAULT_RATE_LIMIT = 200;

    /** Date formats for template fields, as PHP date() formats. */
    public const DATE_FORMATS = ['dmy' => 'd.m.Y', 'ymd' => 'Y-m-d', 'mdy' => 'm/d/Y'];
    public const DEFAULT_DATE_FORMAT = 'dmy';

    public function __construct(
        private readonly SettingsStore $store,
        private readonly SecretBox $secrets
    ) {
    }

    public function apiUrl(): string
    {
        $url = trim((string) $this->store->get('api_url'));
        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    public function setApiUrl(string $url): void
    {
        $this->store->set('api_url', trim($url));
    }

    /**
     * Decrypted API key of an organisation, empty if none is configured or it cannot be decrypted.
     */
    public function apiKey(string $org): string
    {
        $value = trim((string) $this->store->get(self::apiKeyName($org)));
        if ($value === '') {
            return '';
        }
        try {
            return trim($this->secrets->decrypt($value));
        } catch (\RuntimeException $e) {
            return '';
        }
    }

    public function hasApiKey(string $org): bool
    {
        return trim((string) $this->store->get(self::apiKeyName($org))) !== '';
    }

    /**
     * Store a new API key encrypted. An empty value removes the key.
     */
    public function setApiKey(string $org, string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            $this->store->delete(self::apiKeyName($org));
            return;
        }
        $this->store->set(self::apiKeyName($org), $this->secrets->encrypt($key));
    }

    public static function apiKeyName(string $org): string
    {
        return $org === self::MAIN_ORG ? 'api_key' : 'api_key_' . $org;
    }

    public static function rateLimitName(string $org): string
    {
        return $org === self::MAIN_ORG ? 'rate_limit' : 'rate_limit_' . $org;
    }

    /**
     * Calls per hour for the organisation's API key, 0 means no limit.
     */
    public function rateLimit(string $org): int
    {
        $value = trim((string) $this->store->get(self::rateLimitName($org)));
        return $value === '' ? self::DEFAULT_RATE_LIMIT : max(0, (int) $value);
    }

    public function setRateLimit(string $org, int $limit): void
    {
        $this->store->set(self::rateLimitName($org), (string) max(0, $limit));
    }

    public function dateFormat(): string
    {
        $value = (string) $this->store->get('date_format');
        return array_key_exists($value, self::DATE_FORMATS) ? $value : self::DEFAULT_DATE_FORMAT;
    }

    public function setDateFormat(string $format): void
    {
        $this->store->set('date_format', array_key_exists($format, self::DATE_FORMATS) ? $format : self::DEFAULT_DATE_FORMAT);
    }

    /**
     * Raw text of the further organisations.
     */
    public function orgsText(): string
    {
        return (string) $this->store->get('orgs');
    }

    public function setOrgsText(string $text): void
    {
        $this->store->set('orgs', trim($text));
    }

    /**
     * All organisations, the main one first.
     *
     * @param string $main_name display name of the main organisation
     * @return array<string, string> key => name
     */
    public function orgs(string $main_name = 'Main organisation'): array
    {
        $orgs = [self::MAIN_ORG => $main_name];
        foreach (self::parseOrgs($this->orgsText()) as $key => $name) {
            if ($key !== self::MAIN_ORG) {
                $orgs[$key] = $name;
            }
        }
        return $orgs;
    }

    public function categoryMapText(): string
    {
        return (string) $this->store->get('category_map');
    }

    public function setCategoryMapText(string $text): void
    {
        $this->store->set('category_map', trim($text));
    }

    /**
     * @return array<string, string> category reference (ref id or import id) => organisation key
     */
    public function categoryMap(): array
    {
        return self::parseCategoryMap($this->categoryMapText());
    }

    /**
     * Organisation for a course from the nearest category in the map.
     *
     * @param list<list<string>> $categories references of each category from the course upwards,
     *        e.g. [["123", "import_abc"], ["45"]] (ref id, import id)
     */
    public function orgForCategories(array $categories): string
    {
        $map = $this->categoryMap();
        $orgs = $this->orgs();
        foreach ($categories as $refs) {
            foreach ($refs as $ref) {
                $ref = trim((string) $ref);
                if ($ref !== '' && isset($map[$ref]) && isset($orgs[$map[$ref]])) {
                    return $map[$ref];
                }
            }
        }
        return self::MAIN_ORG;
    }

    /**
     * @return array<string, string> key => name, lines with an invalid key are skipped
     */
    public static function parseOrgs(string $value): array
    {
        $orgs = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $name] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if (!self::isValidOrgKey($key)) {
                continue;
            }
            $orgs[$key] = $name !== '' ? $name : $key;
        }
        return $orgs;
    }

    public static function isValidOrgKey(string $key): bool
    {
        // Must start with a letter: numeric keys would become int array keys in PHP.
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,49}$/', $key);
    }

    /**
     * Lines of the organisation text that are not valid.
     *
     * @return string[]
     */
    public static function invalidOrgLines(string $value): array
    {
        $invalid = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $key = trim(explode('|', $line, 2)[0]);
            if (!self::isValidOrgKey($key)) {
                $invalid[] = $line;
            }
        }
        return $invalid;
    }

    /**
     * @return array<string, string> category reference => organisation key
     */
    public static function parseCategoryMap(string $value): array
    {
        $map = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$category, $org] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            if ($category !== '' && $org !== '') {
                $map[$category] = $org;
            }
        }
        return $map;
    }

    /**
     * Problems of a category map text: invalid lines and unknown organisations.
     *
     * @param array<string, string> $orgs known organisations
     * @return array{invalid: string[], unknown_orgs: string[]}
     */
    public static function categoryMapProblems(string $value, array $orgs): array
    {
        $invalid = [];
        $unknown = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$category, $org] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');
            if ($category === '' || $org === '') {
                $invalid[] = $line;
            } elseif (!isset($orgs[$org])) {
                $unknown[] = $org;
            }
        }
        return ['invalid' => $invalid, 'unknown_orgs' => array_values(array_unique($unknown))];
    }
}
