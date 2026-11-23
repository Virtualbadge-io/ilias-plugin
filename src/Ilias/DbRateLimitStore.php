<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Store\RateLimitStore;

/**
 * Times of the API calls per key in a plugin table. Changes run under a table lock (ilAtomQuery),
 * so web requests and the cron job never exceed the limit together.
 */
class DbRateLimitStore implements RateLimitStore
{
    public function __construct(private readonly \ilDBInterface $db)
    {
    }

    public function calls(string $key, int $since): array
    {
        $result = $this->db->queryF(
            'SELECT call_time FROM ' . Tables::CALLS . ' WHERE api_key = %s AND call_time > %s ORDER BY call_time, id',
            [\ilDBConstants::T_TEXT, \ilDBConstants::T_INTEGER],
            [$key, $since]
        );
        $times = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $times[] = (int) $row['call_time'];
        }
        return $times;
    }

    public function add(string $key, int $time, int $prune_before): void
    {
        $this->db->manipulateF(
            'DELETE FROM ' . Tables::CALLS . ' WHERE call_time <= %s',
            [\ilDBConstants::T_INTEGER],
            [$prune_before]
        );
        $this->db->insert(Tables::CALLS, [
            'id' => [\ilDBConstants::T_INTEGER, $this->db->nextId(Tables::CALLS)],
            'api_key' => [\ilDBConstants::T_TEXT, $key],
            'call_time' => [\ilDBConstants::T_INTEGER, $time],
        ]);
    }

    public function locked(string $key, callable $fn): mixed
    {
        $result = null;
        $error = null;
        $atom = $this->db->buildAtomQuery();
        $atom->addTableLock(Tables::CALLS)->lockSequence(true);
        $atom->addQueryCallable(static function (\ilDBInterface $db) use ($fn, &$result, &$error): void {
            try {
                $result = $fn();
            } catch (\Throwable $e) {
                // Thrown after the lock is released.
                $error = $e;
            }
        });
        $atom->run();
        if ($error !== null) {
            throw $error;
        }
        return $result;
    }
}
