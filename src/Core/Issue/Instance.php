<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Issue;

/**
 * Settings of one Virtualbadge.io object in a course.
 */
class Instance
{
    /** Issue on course completion (learning progress of the course "completed"). */
    public const TRIGGER_COMPLETION = 'completion';
    /** Course completion, then release by a tutor. */
    public const TRIGGER_APPROVAL = 'approval';
    /** A selected object in the course is completed, optionally with a minimum test result. */
    public const TRIGGER_OBJECT = 'object';
    /** Release by a tutor without condition. */
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGERS = [self::TRIGGER_COMPLETION, self::TRIGGER_APPROVAL, self::TRIGGER_OBJECT, self::TRIGGER_MANUAL];

    public int $obj_id = 0;
    public int $ref_id = 0;
    /** Course the object belongs to, 0 if it is not inside a course. */
    public int $course_ref_id = 0;
    public int $course_obj_id = 0;
    public string $org = '';
    public string $template_id = '';
    public string $template_name = '';
    public string $template_type = 'certificate';
    /** @var array<string, array{source: string, value: string}> template field => mapping */
    public array $field_map = [];
    public string $trigger = self::TRIGGER_COMPLETION;
    /** Object for trigger "object". */
    public int $trigger_ref_id = 0;
    public int $trigger_obj_id = 0;
    /** Minimum test result in percent for trigger "object" with a test, null if the object only has to be completed. */
    public ?float $min_percent = null;
    public bool $send_email = true;
    /** Learning progress of the Virtualbadge.io object is "completed" once the certificate is issued. */
    public bool $completion_issued = false;

    public function isConfigured(): bool
    {
        return $this->template_id !== '' && $this->org !== '';
    }

    /**
     * Source recorded for issues released by a tutor or queued for users who already met the condition.
     */
    public function releaseSource(): string
    {
        return match ($this->trigger) {
            self::TRIGGER_APPROVAL => Issue::SOURCE_APPROVAL,
            self::TRIGGER_MANUAL => Issue::SOURCE_MANUAL,
            default => Issue::SOURCE_RETROACTIVE,
        };
    }
}
