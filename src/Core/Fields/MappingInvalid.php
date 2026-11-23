<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Fields;

/**
 * The field mapping of an object does not fit the template (unmapped fields or unknown source).
 */
class MappingInvalid extends \RuntimeException
{
    /**
     * @param string[] $fields affected template fields
     */
    public function __construct(string $message, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
