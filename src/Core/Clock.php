<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core;

/**
 * Current time and randomness, replaced in tests.
 */
interface Clock
{
    public function now(): int;

    /**
     * Random integer between $min and $max, both included.
     */
    public function random(int $min, int $max): int;
}
