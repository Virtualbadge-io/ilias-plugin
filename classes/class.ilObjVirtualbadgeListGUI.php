<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

class ilObjVirtualbadgeListGUI extends ilObjectPluginListGUI
{
    public function initType(): void
    {
        $this->setType(ilVirtualbadgePlugin::ID);
    }

    public function getGuiClass(): string
    {
        return ilObjVirtualbadgeGUI::class;
    }

    public function initCommands(): array
    {
        return [
            ['permission' => 'read', 'cmd' => ilObjVirtualbadgeGUI::CMD_VIEW, 'default' => true],
            ['permission' => 'write', 'cmd' => ilObjVirtualbadgeGUI::CMD_SETTINGS, 'txt' => $this->txt('settings'), 'default' => false],
        ];
    }
}
