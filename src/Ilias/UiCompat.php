<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use ILIAS\UI\Factory;

/**
 * UI framework calls whose signature differs between ILIAS versions.
 *
 * VERSION SPECIFIC (ILIAS 10). ILIAS 11: $f->legacy()->content($html).
 */
final class UiCompat
{
    public static function legacy(Factory $f, string $html): \ILIAS\UI\Component\Legacy\Legacy
    {
        return $f->legacy($html);
    }
}
