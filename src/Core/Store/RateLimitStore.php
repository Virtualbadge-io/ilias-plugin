<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Store;

/**
 * Times of the calls sent with one API key, for the plugin's own hourly limit.
 */
interface RateLimitStore
{
    /**
     * Times of calls after $since, oldest first.
     *
     * @return int[]
     */
    public function calls(string $key, int $since): array;

    /**
     * Record a call. Older calls than $prune_before may be removed at the same time.
     */
    public function add(string $key, int $time, int $prune_before): void;

    /**
     * Run $fn while no other process changes the calls of $key.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     * @throws \RuntimeException if the lock cannot be acquired
     */
    public function locked(string $key, callable $fn): mixed;
}
