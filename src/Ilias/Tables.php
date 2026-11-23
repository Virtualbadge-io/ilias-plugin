<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

/**
 * Database tables of the plugin. ILIAS requires the prefix <component>_<slot>_<plugin id>.
 */
final class Tables
{
    /** Settings of each Virtualbadge.io object. */
    public const DATA = 'rep_robj_xvbd_data';
    /** One row per certificate and user. */
    public const ISSUE = 'rep_robj_xvbd_issue';
    /** Template lists from the API. */
    public const CACHE = 'rep_robj_xvbd_cache';
    /** Calls per API key, for the hourly limit. */
    public const CALLS = 'rep_robj_xvbd_calls';

    public const ALL = [self::DATA, self::ISSUE, self::CACHE, self::CALLS];

    /** Module name of the global settings in ilSetting. */
    public const SETTINGS_MODULE = 'xvbd';
}
