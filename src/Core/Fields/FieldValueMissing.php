<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Fields;

use Virtualbadge\Core\EnglishTexts;

/**
 * A mapped template field has no value for this user or course. Nothing is sent to Virtualbadge.io.
 */
class FieldValueMissing extends \RuntimeException
{
    /**
     * @param string $source label of the source
     * @param string|null $message translated message, the English text if null
     */
    public function __construct(
        public readonly string $field,
        public readonly string $source,
        public readonly bool $course_value,
        ?string $message = null
    ) {
        parent::__construct($message ?? (new EnglishTexts())->get(
            $course_value ? 'msg_field_empty_course' : 'msg_field_empty_user',
            $field,
            $source
        ));
    }
}
