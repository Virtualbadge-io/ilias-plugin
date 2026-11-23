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
 * Small persistent key-value cache, e.g. for the template list of an organisation.
 */
interface Cache
{
    /**
     * @return array<mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<mixed> $value
     */
    public function set(string $key, array $value): void;

    public function delete(string $key): void;
}
