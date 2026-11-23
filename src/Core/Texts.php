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
 * Texts of the messages the plugin writes, by language key without the plugin prefix.
 */
interface Texts
{
    /**
     * Text of a language key with the values inserted into its sprintf placeholders (%s).
     */
    public function get(string $key, string ...$args): string;
}
