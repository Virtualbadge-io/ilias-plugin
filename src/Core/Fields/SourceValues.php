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
 * Raw values of one user, course and issue that template fields can be filled with.
 * Dates are Unix timestamps (0 = not set), all other values plain text.
 */
final class SourceValues
{
    /**
     * @param array<string, string> $user standard user fields, see FieldMapper::USER_FIELDS
     * @param array<string, string> $profile custom user fields: field id => value
     * @param array<string, string|int> $course title, description, category, startdate, enddate
     * @param array<string, string> $course_fields custom metadata of the course: field id => value
     * @param int $completed_at time the condition was met
     * @param float|null $percent result in percent (test result), null if not available
     * @param float|null $points reached points, null if not available
     * @param float|null $max_points maximum points, null if not available
     */
    public function __construct(
        public readonly array $user = [],
        public readonly array $profile = [],
        public readonly array $course = [],
        public readonly array $course_fields = [],
        public readonly int $completed_at = 0,
        public readonly ?float $percent = null,
        public readonly ?float $points = null,
        public readonly ?float $max_points = null
    ) {
    }
}
