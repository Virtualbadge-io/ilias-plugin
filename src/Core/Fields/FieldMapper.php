<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Fields;

use Virtualbadge\Core\Config\Settings;
use Virtualbadge\Core\EnglishTexts;
use Virtualbadge\Core\Texts;

/**
 * Sources for the values of template fields.
 *
 * A mapping entry is ['source' => string, 'value' => string]. An empty value of a mapped source (not fixed text)
 * stops the certificate with FieldValueMissing. Sources:
 * - text: fixed text with placeholders, same for every user
 * - user:<field>: standard user field
 * - profile:<id>: custom user field
 * - course:<field>: course title, description, category, start and end of the course period
 * - coursefield:<id>: custom metadata field of the course
 * - issue:<field>: completion date, result in percent, reached and maximum points (test result)
 */
class FieldMapper
{
    public const TEXT = 'text';

    /** "title" is the academic title of the profile. */
    public const USER_FIELDS = ['firstname', 'lastname', 'fullname', 'title', 'email', 'login', 'matriculation',
        'institution', 'department', 'city', 'country'];

    /** Placeholder of a custom metadata field in fixed text, group 1 is the field id. */
    public const COURSE_FIELD_PLACEHOLDER = '/\{coursefield:([^{}]*)\}/';

    public const COURSE_FIELDS = ['title', 'description', 'category', 'startdate', 'enddate'];

    public const ISSUE_FIELDS = ['completiondate', 'grade', 'points', 'maxpoints'];

    /** Field names that suggest a source, in lower case. */
    protected const SUGGESTIONS = [
        'vorname' => 'user:firstname',
        'firstname' => 'user:firstname',
        'first name' => 'user:firstname',
        'nachname' => 'user:lastname',
        'lastname' => 'user:lastname',
        'last name' => 'user:lastname',
        'name' => 'user:fullname',
        'fullname' => 'user:fullname',
        'full name' => 'user:fullname',
        'titel' => 'user:title',
        'akademischer titel' => 'user:title',
        'academic title' => 'user:title',
        'e-mail' => 'user:email',
        'email' => 'user:email',
        'benutzername' => 'user:login',
        'login' => 'user:login',
        'username' => 'user:login',
        'matrikelnummer' => 'user:matriculation',
        'matriculation' => 'user:matriculation',
        'matriculation number' => 'user:matriculation',
        'firma' => 'user:institution',
        'institution' => 'user:institution',
        'abteilung' => 'user:department',
        'department' => 'user:department',
        'ort' => 'user:city',
        'stadt' => 'user:city',
        'city' => 'user:city',
        'land' => 'user:country',
        'country' => 'user:country',
        'start' => 'course:startdate',
        'beginn' => 'course:startdate',
        'kursbeginn' => 'course:startdate',
        'startdatum' => 'course:startdate',
        'start date' => 'course:startdate',
        'ende' => 'course:enddate',
        'kursende' => 'course:enddate',
        'enddatum' => 'course:enddate',
        'end' => 'course:enddate',
        'end date' => 'course:enddate',
        'kurs' => 'course:title',
        'kursname' => 'course:title',
        'kurstitel' => 'course:title',
        'course' => 'course:title',
        'course name' => 'course:title',
        'beschreibung' => 'course:description',
        'description' => 'course:description',
        'kategorie' => 'course:category',
        'category' => 'course:category',
        'note' => 'issue:grade',
        'ergebnis' => 'issue:grade',
        'grade' => 'issue:grade',
        'result' => 'issue:grade',
        'punkte' => 'issue:points',
        'points' => 'issue:points',
        'maximale punkte' => 'issue:maxpoints',
        'max punkte' => 'issue:maxpoints',
        'punkte maximal' => 'issue:maxpoints',
        'maximum points' => 'issue:maxpoints',
        'max points' => 'issue:maxpoints',
        'datum' => 'issue:completiondate',
        'abschlussdatum' => 'issue:completiondate',
        'date' => 'issue:completiondate',
        'completion date' => 'issue:completiondate',
    ];

    /**
     * @param string $date_format key of Settings::DATE_FORMATS
     * @param string $decimal_separator used for points and percent
     * @param Texts $texts texts of the error messages
     */
    public function __construct(
        private readonly string $date_format = Settings::DEFAULT_DATE_FORMAT,
        private readonly string $decimal_separator = ',',
        private readonly Texts $texts = new EnglishTexts()
    ) {
    }

    /**
     * All selectable sources.
     *
     * @param string[] $profile_ids ids of custom user fields that may be used
     * @param string[] $course_field_ids ids of custom course metadata fields
     * @return string[]
     */
    public static function sources(array $profile_ids = [], array $course_field_ids = []): array
    {
        $sources = [self::TEXT];
        foreach (self::USER_FIELDS as $field) {
            $sources[] = 'user:' . $field;
        }
        foreach ($profile_ids as $id) {
            $sources[] = 'profile:' . $id;
        }
        foreach (self::COURSE_FIELDS as $field) {
            $sources[] = 'course:' . $field;
        }
        foreach ($course_field_ids as $id) {
            $sources[] = 'coursefield:' . $id;
        }
        foreach (self::ISSUE_FIELDS as $field) {
            $sources[] = 'issue:' . $field;
        }
        return $sources;
    }

    /**
     * Source preselected for a template field, based on its name.
     *
     * @param array<string, string> $profile_names custom user field id => name
     * @param array<string, string> $course_field_names custom course field id => name
     */
    public static function suggest(string $field, array $profile_names = [], array $course_field_names = []): string
    {
        $needle = mb_strtolower(trim($field));
        foreach ($course_field_names as $id => $name) {
            if ($needle === mb_strtolower(trim(strip_tags($name)))) {
                return 'coursefield:' . $id;
            }
        }
        foreach ($profile_names as $id => $name) {
            if ($needle === mb_strtolower(trim($name))) {
                return 'profile:' . $id;
            }
        }
        return self::SUGGESTIONS[$needle] ?? self::TEXT;
    }

    /**
     * Mapping entry in its current form. Plain strings are fixed text; other keys of a stored entry (such as the
     * removed "optional" flag) are ignored.
     *
     * @return array{source: string, value: string}
     */
    public static function normalize(mixed $entry): array
    {
        if (is_array($entry) && isset($entry['source'])) {
            return ['source' => (string) $entry['source'], 'value' => (string) ($entry['value'] ?? '')];
        }
        return ['source' => self::TEXT, 'value' => is_scalar($entry) ? (string) $entry : ''];
    }

    /**
     * Ids of the custom metadata fields used as placeholders in a fixed text.
     *
     * @return string[]
     */
    public static function courseFieldPlaceholders(string $text): array
    {
        preg_match_all(self::COURSE_FIELD_PLACEHOLDER, $text, $matches);
        return array_values(array_unique(array_map('strval', $matches[1])));
    }

    public static function isCourseSource(string $source): bool
    {
        return str_starts_with($source, 'course:') || str_starts_with($source, 'coursefield:');
    }

    /**
     * Template fields that have no mapping.
     *
     * @param string[] $template_fields
     * @param array<string, mixed> $map
     * @return string[]
     */
    public static function unmapped(array $template_fields, array $map): array
    {
        return array_values(array_diff($template_fields, array_map('strval', array_keys($map))));
    }

    /**
     * Values of the template fields for one user.
     *
     * @param array<string, mixed> $map field => mapping entry, only the fields to send
     * @param string[] $valid_sources sources that may be used, see self::sources()
     * @param callable(string): string|null $label human readable name of a source, for error messages
     * @return array<string, string> field => value
     * @throws FieldValueMissing if the value of a mapped source (not fixed text) is empty
     * @throws MappingInvalid if a field uses a source that is not available
     */
    public function resolve(array $map, SourceValues $values, array $valid_sources, ?callable $label = null): array
    {
        $placeholders = $this->placeholders($values);
        $result = [];
        foreach ($map as $field => $entry) {
            $field = (string) $field;
            $entry = self::normalize($entry);
            if ($entry['source'] === self::TEXT) {
                // Placeholders of metadata fields not available in the course are never sent literally.
                $replace = $placeholders;
                foreach (self::courseFieldPlaceholders($entry['value']) as $id) {
                    $replace['{coursefield:' . $id . '}'] ??= '';
                }
                $result[$field] = trim(strtr($entry['value'], $replace));
                continue;
            }
            if (!in_array($entry['source'], $valid_sources, true)) {
                throw new MappingInvalid($this->texts->get('msg_source_unavailable', $field), [$field]);
            }
            $value = trim($this->value($entry['source'], $values));
            if ($value === '') {
                $name = $label !== null ? $label($entry['source']) : $entry['source'];
                $course_value = self::isCourseSource($entry['source']);
                throw new FieldValueMissing(
                    $field,
                    $name,
                    $course_value,
                    $this->texts->get($course_value ? 'msg_field_empty_course' : 'msg_field_empty_user', $field, $name)
                );
            }
            $result[$field] = $value;
        }
        return $result;
    }

    /**
     * Value of one source. "user:fullname" is the full name the environment supplies (ILIAS builds it with the
     * academic title); only if it is missing, first and last name are joined.
     */
    public function value(string $source, SourceValues $values): string
    {
        [$type, $name] = array_pad(explode(':', $source, 2), 2, '');
        switch ($type) {
            case 'user':
                if ($name === 'fullname' && !isset($values->user['fullname'])) {
                    return self::collapse(($values->user['firstname'] ?? '') . ' ' . ($values->user['lastname'] ?? ''));
                }
                return (string) ($values->user[$name] ?? '');
            case 'profile':
                return (string) ($values->profile[$name] ?? '');
            case 'course':
                if ($name === 'startdate' || $name === 'enddate') {
                    return $this->date((int) ($values->course[$name] ?? 0));
                }
                return (string) ($values->course[$name] ?? '');
            case 'coursefield':
                return (string) ($values->course_fields[$name] ?? '');
            case 'issue':
                return match ($name) {
                    'completiondate' => $this->date($values->completed_at),
                    'grade' => $values->percent === null ? '' : $this->number($values->percent, 0) . ' %',
                    'points' => $values->points === null ? '' : $this->number($values->points, 2),
                    'maxpoints' => $values->max_points === null ? '' : $this->number($values->max_points, 2),
                    default => '',
                };
        }
        return '';
    }

    /**
     * Placeholders available in fixed text.
     *
     * @return array<string, string>
     */
    public function placeholders(SourceValues $values): array
    {
        $result = [
            '{firstname}' => $this->value('user:firstname', $values),
            '{lastname}' => $this->value('user:lastname', $values),
            '{fullname}' => $this->value('user:fullname', $values),
            '{email}' => $this->value('user:email', $values),
            '{login}' => $this->value('user:login', $values),
            '{course}' => $this->value('course:title', $values),
            '{coursestartdate}' => $this->value('course:startdate', $values),
            '{courseenddate}' => $this->value('course:enddate', $values),
            '{completiondate}' => $this->value('issue:completiondate', $values),
            '{grade}' => $this->value('issue:grade', $values),
            '{points}' => $this->value('issue:points', $values),
            '{maxpoints}' => $this->value('issue:maxpoints', $values),
        ];
        foreach ($values->profile as $id => $value) {
            $result['{profile:' . $id . '}'] = (string) $value;
        }
        foreach ($values->course_fields as $id => $value) {
            $result['{coursefield:' . $id . '}'] = (string) $value;
        }
        return $result;
    }

    /**
     * Date in the configured format and the server timezone, empty for 0.
     */
    public function date(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $format = Settings::DATE_FORMATS[$this->date_format] ?? Settings::DATE_FORMATS[Settings::DEFAULT_DATE_FORMAT];
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format($format);
    }

    /**
     * Number with at most $decimals decimals, trailing zeros removed.
     */
    public function number(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), $decimals, $this->decimal_separator, '');
        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), $this->decimal_separator);
        }
        return $text;
    }

    public static function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
