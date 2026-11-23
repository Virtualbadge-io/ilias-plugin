<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Fields\ValueText;

/**
 * Custom metadata fields ("Erweiterte Metadaten") of a course: the only class that uses the AdvancedMetaData
 * component. Shared by ILIAS 10 and 11 (no API difference, see the design of change add-course-metadata-fields).
 *
 * "Active for the course" means the records the course itself shows: ilAdvancedMDRecord::_getSelectedRecordsByObject()
 * with the course's ref id, which checks activation, scope, local records and optional records selected in the course.
 * Results are cached per instance (one per Services instance, i.e. per request).
 */
class CourseMetadata
{
    /** @var array<int, array<int, \ilAdvancedMDRecord>> course ref id => record id => record */
    private array $records = [];

    /** @var array<string, array<string, array{record: string, title: string}>> */
    private array $fields = [];

    /** @var array<string, array<string, string>> */
    private array $values = [];

    /**
     * @param ValueText $text conversion with the plugin's date format and the decimal separator of the default language
     */
    public function __construct(
        private readonly ValueText $text,
        private readonly \ilLogger $logger
    ) {
    }

    /**
     * Fields of the records active for a course, titles in the given language (fallback: default title).
     * Titles are configured by administrators and not escaped.
     *
     * @return array<string, array{record: string, title: string}> field id => record title and field title
     */
    public function fields(int $course_ref_id, string $lang): array
    {
        if ($course_ref_id <= 0) {
            return [];
        }
        $key = $course_ref_id . '|' . $lang;
        if (isset($this->fields[$key])) {
            return $this->fields[$key];
        }
        $fields = [];
        foreach ($this->records($course_ref_id) as $record_id => $record) {
            $record_title = \ilAdvancedMDRecordTranslations::getInstanceByRecordId($record_id)->getTitleForLanguage($lang);
            $translations = \ilAdvancedMDFieldTranslations::getInstanceByRecordId($record_id);
            foreach (\ilAdvancedMDFieldDefinition::getInstancesByRecordId($record_id) as $definition) {
                $field_id = (int) $definition->getFieldId();
                if ($field_id <= 0) {
                    continue;
                }
                $fields[(string) $field_id] = [
                    'record' => $record_title,
                    'title' => $translations->getTitleForLanguage($field_id, $lang),
                ];
            }
        }
        return $this->fields[$key] = $fields;
    }

    /**
     * Current values of all fields active for a course, as text ('' if not set).
     *
     * @return array<string, string> field id => text
     */
    public function values(int $course_obj_id, int $course_ref_id): array
    {
        if ($course_obj_id <= 0 || $course_ref_id <= 0) {
            return [];
        }
        $key = $course_obj_id . '|' . $course_ref_id;
        if (isset($this->values[$key])) {
            return $this->values[$key];
        }
        $result = [];
        try {
            $record_ids = array_keys($this->records($course_ref_id));
        } catch (\Throwable $e) {
            $this->logger->warning('Virtualbadge.io: custom metadata records of course ' . $course_obj_id
                . ' not readable: ' . $e->getMessage());
            $record_ids = [];
        }
        foreach ($record_ids as $record_id) {
            try {
                $values = new \ilAdvancedMDValues($record_id, $course_obj_id);
                $values->read();
                $group = $values->getADTGroup();
                $definitions = $values->getDefinitions();
            } catch (\Throwable $e) {
                // A broken record must not stop issues that do not use it: its fields stay unset (empty).
                $this->logger->warning('Virtualbadge.io: custom metadata record ' . $record_id . ' of course '
                    . $course_obj_id . ' not readable: ' . $e->getMessage());
                continue;
            }
            foreach ($definitions as $definition) {
                $field_id = (int) $definition->getFieldId();
                if ($field_id <= 0) {
                    continue;
                }
                try {
                    $adt = $group->getElement((string) $field_id);
                    $result[(string) $field_id] = $adt === null ? '' : $this->text($adt, $definition);
                } catch (\Throwable $e) {
                    // One unusual field must not stop the others: a mapped field without value fails the issue.
                    $this->logger->warning('Virtualbadge.io: value of custom metadata field ' . $field_id
                        . ' of course ' . $course_obj_id . ' not readable: ' . $e->getMessage());
                    $result[(string) $field_id] = '';
                }
            }
        }
        return $this->values[$key] = $result;
    }

    /**
     * @return array<int, \ilAdvancedMDRecord> record id => record
     */
    private function records(int $course_ref_id): array
    {
        if (!isset($this->records[$course_ref_id])) {
            $records = [];
            foreach (\ilAdvancedMDRecord::_getSelectedRecordsByObject('crs', $course_ref_id) as $record) {
                $records[$record->getRecordId()] = $record;
            }
            $this->records[$course_ref_id] = $records;
        }
        return $this->records[$course_ref_id];
    }

    /**
     * Value of one field as text, see "Value as text per field type" in the design.
     */
    private function text(\ilADT $adt, \ilAdvancedMDFieldDefinition $definition): string
    {
        if ($adt->isNull()) {
            return '';
        }
        if ($adt instanceof \ilADTLocalizedText) {
            // The same language choice as ILIAS for single-language values; falls back to the plain text.
            $adt_definition = $adt->getCopyOfDefinition();
            $lang = $adt_definition instanceof \ilADTLocalizedTextDefinition ? $adt_definition->getDefaultLanguage() : '';
            return trim($adt->getTextForLanguage($lang));
        }
        if ($adt instanceof \ilADTText) {
            return trim((string) $adt->getText());
        }
        if ($adt instanceof \ilADTInteger) {
            $number = $adt->getNumber();
            return $number === null ? '' : (string) $number;
        }
        if ($adt instanceof \ilADTFloat) {
            $adt_definition = $adt->getCopyOfDefinition();
            $decimals = $adt_definition instanceof \ilADTFloatDefinition ? $adt_definition->getDecimals() : 0;
            return $this->text->float($adt->getNumber(), $decimals);
        }
        if ($adt instanceof \ilADTDate) {
            // IL_CAL_DATE, not IL_CAL_UNIX: a date is stored at 00:00 UTC and would shift west of UTC.
            $date = $adt->getDate()?->get(IL_CAL_DATE);
            return $this->text->date(is_string($date) ? $date : null);
        }
        if ($adt instanceof \ilADTDateTime) {
            $time = $adt->getDate()?->get(IL_CAL_UNIX);
            return $this->text->dateTime(is_numeric($time) ? (int) $time : null);
        }
        if ($adt instanceof \ilADTMultiEnum) {
            $labels = $definition instanceof \ilAdvancedMDFieldDefinitionSelect
                ? ($definition->getOptionsInDefaultLanguageAsArray() ?? [])
                : [];
            return $this->text->multiSelect($adt->getSelections() ?? [], $labels);
        }
        if ($adt instanceof \ilADTEnum && $definition instanceof \ilAdvancedMDFieldDefinitionSelect) {
            $selection = $adt->getSelection();
            return $this->text->select(
                is_int($selection) || is_string($selection) ? $selection : null,
                $definition->getOptionsInDefaultLanguageAsArray() ?? []
            );
        }
        if ($adt instanceof \ilADTExternalLink) {
            return $this->text->externalLink($adt->getUrl(), $adt->getTitle());
        }
        if ($adt instanceof \ilADTInternalLink) {
            // Title of the linked object without access check: the cron job has no learner context.
            $ref_id = (int) $adt->getTargetRefId();
            if ($ref_id <= 0 || !\ilObject::_exists($ref_id, true) || \ilObject::_isInTrash($ref_id)) {
                return '';
            }
            return trim(\ilObject::_lookupTitle(\ilObject::_lookupObjId($ref_id)));
        }
        $bridge = \ilADTFactory::getInstance()->getPresentationBridgeForInstance($adt);
        if ($adt instanceof \ilADTLocation) {
            // getHTML() renders a map; the list text is "<lat>&deg;/<lon>&deg;".
            return ValueText::plain($bridge->getList());
        }
        // Address and any other type: presentation text without HTML.
        return ValueText::plain($bridge->getHTML());
    }
}
