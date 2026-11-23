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

/**
 * Converts plain values of custom metadata fields to the text sent to Virtualbadge.io.
 *
 * Free of ILIAS: the adapter (Virtualbadge\Ilias\CourseMetadata) reads the values from the ILIAS data types
 * and passes them in. Every method returns '' for a missing value.
 */
class ValueText
{
    /**
     * @param string $date_format key of Settings::DATE_FORMATS
     * @param string $decimal_separator of the ILIAS default language
     */
    public function __construct(
        private readonly string $date_format = Settings::DEFAULT_DATE_FORMAT,
        private readonly string $decimal_separator = ','
    ) {
    }

    /**
     * Date without time ("Y-m-d") in the plugin's date format. Not converted between timezones, so the day
     * never shifts.
     */
    public function date(?string $date): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return '';
        }
        return $parsed->format($this->format());
    }

    /**
     * Date and time as Unix timestamp: the date only, in the server timezone.
     */
    public function dateTime(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '';
        }
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format($this->format());
    }

    /**
     * Label of the selected option.
     *
     * @param array<int|string, string> $labels option id => label
     */
    public function select(int|string|null $id, array $labels): string
    {
        if ($id === null || $id === '') {
            return '';
        }
        return trim((string) ($labels[$id] ?? ''));
    }

    /**
     * Labels of the selected options joined with ", ". Unknown options are left out.
     *
     * @param array<int|string> $ids
     * @param array<int|string, string> $labels option id => label
     */
    public function multiSelect(array $ids, array $labels): string
    {
        $texts = [];
        foreach ($ids as $id) {
            $text = $this->select($id, $labels);
            if ($text !== '') {
                $texts[] = $text;
            }
        }
        return implode(', ', $texts);
    }

    /**
     * Number with exactly $decimals decimals (trailing zeros kept) and no thousands separator.
     */
    public function float(?float $value, int $decimals): string
    {
        if ($value === null) {
            return '';
        }
        return number_format($value, max(0, $decimals), $this->decimal_separator, '');
    }

    /**
     * The URL, "title (URL)" if a title is set.
     */
    public function externalLink(?string $url, ?string $title): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $title = trim((string) $title);
        return $title !== '' ? $title . ' (' . $url . ')' : $url;
    }

    /**
     * Presentation text of ILIAS as plain text: tags removed, entities decoded, whitespace collapsed.
     */
    public static function plain(string $html): string
    {
        $text = (string) preg_replace('~<br\s*/?>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return FieldMapper::collapse($text);
    }

    private function format(): string
    {
        return Settings::DATE_FORMATS[$this->date_format] ?? Settings::DATE_FORMATS[Settings::DEFAULT_DATE_FORMAT];
    }
}
