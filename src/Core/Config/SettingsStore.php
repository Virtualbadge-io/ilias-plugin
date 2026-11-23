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
 * Global plugin settings (ilSetting in ILIAS, an array in tests).
 */
interface SettingsStore
{
    public function get(string $name): ?string;

    public function set(string $name, string $value): void;

    public function delete(string $name): void;
}
