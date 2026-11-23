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
 * English texts of the plugin's messages, used when no language is available (tests, CLI) and as fallback.
 * Same texts as the msg_* entries in lang/ilias_en.lang.
 */
final class EnglishTexts implements Texts
{
    /** Language key => English text with sprintf placeholders. */
    public const TEXTS = [
        'msg_field_empty_user' => 'Field "%s": %s is empty for this user.',
        'msg_field_empty_course' => 'Field "%s": %s is empty in this course.',
        'msg_source_unavailable' => 'Field "%s" uses a source that is not available.',
        'msg_gone' => 'The object or the user no longer exists.',
        'msg_template_inactive' => 'The template "%s" is no longer active in Virtualbadge.io.',
        'msg_fields_unmapped' => 'The template "%s" has fields that are not mapped in this object: %s.',
        'msg_unchanged' => 'Not issued again: the data has not changed since the last issue, the current certificate stays valid.',
        'msg_reissue_failed' => 'Issuing again failed, the current certificate stays valid: %s',
        'msg_no_api_key' => 'No API key is configured for this organisation.',
        'msg_api_key_rejected' => 'Virtualbadge.io rejected the API key.',
        'msg_hourly_limit' => 'Hourly limit of calls to Virtualbadge.io reached.',
        'msg_lock' => 'Lock on the call counter not available.',
        'msg_generation_failed' => 'Virtualbadge.io could not create the certificate. Check the template and the credits of the organisation in Virtualbadge.io, then retry.',
    ];

    /**
     * Unknown keys give the key itself; a text that does not fit the values is returned with the values appended.
     */
    public function get(string $key, string ...$args): string
    {
        $text = self::TEXTS[$key] ?? $key;
        try {
            return sprintf($text, ...$args);
        } catch (\ValueError | \ArgumentCountError) {
            return implode(' ', [$text, ...$args]);
        }
    }
}
