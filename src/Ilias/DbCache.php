<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Store\Cache;

/**
 * Cache in a plugin table, shared by web requests and the cron job.
 */
class DbCache implements Cache
{
    public function __construct(private readonly \ilDBInterface $db)
    {
    }

    public function get(string $key): ?array
    {
        $result = $this->db->queryF(
            'SELECT cache_value FROM ' . Tables::CACHE . ' WHERE cache_key = %s',
            [\ilDBConstants::T_TEXT],
            [self::key($key)]
        );
        $row = $this->db->fetchAssoc($result);
        if ($row === null) {
            return null;
        }
        $value = json_decode((string) $row['cache_value'], true);
        return is_array($value) ? $value : null;
    }

    public function set(string $key, array $value): void
    {
        $this->db->replace(
            Tables::CACHE,
            ['cache_key' => [\ilDBConstants::T_TEXT, self::key($key)]],
            [
                'cache_value' => [\ilDBConstants::T_CLOB, json_encode($value, JSON_UNESCAPED_UNICODE)],
                'time_modified' => [\ilDBConstants::T_INTEGER, time()],
            ]
        );
    }

    public function delete(string $key): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . Tables::CACHE . ' WHERE cache_key = %s',
            [\ilDBConstants::T_TEXT],
            [self::key($key)]
        );
    }

    public function clear(): void
    {
        $this->db->manipulate('DELETE FROM ' . Tables::CACHE);
    }

    private static function key(string $key): string
    {
        return strlen($key) <= 64 ? $key : sha1($key);
    }
}
