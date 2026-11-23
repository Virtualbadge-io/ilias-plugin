<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Config\SettingsStore;

/**
 * Global plugin settings in the ILIAS settings table (module "xvbd").
 */
class IliasSettingsStore implements SettingsStore
{
    public function __construct(private readonly \ilSetting $setting)
    {
    }

    public function get(string $name): ?string
    {
        $value = $this->setting->get($name, null);
        return $value === null ? null : (string) $value;
    }

    public function set(string $name, string $value): void
    {
        $this->setting->set($name, $value);
    }

    public function delete(string $name): void
    {
        $this->setting->delete($name);
    }

    /**
     * Remove all settings of the plugin, used on uninstall.
     */
    public function deleteAll(): void
    {
        $this->setting->deleteAll();
    }
}
