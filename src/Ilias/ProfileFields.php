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
 * Custom user fields that may be used on certificates.
 *
 * VERSION SPECIFIC (ILIAS 10): uses ilUserDefinedFields / ilUserDefinedData. ILIAS 11 replaced
 * them by the new profile service, see branch release_11.
 *
 * Only fields with the ILIAS option "certificate" (allowed in certificates) are offered,
 * the same rule ILIAS applies to its own certificates.
 */
class ProfileFields
{
    /**
     * @return array<string, string> field id => name
     */
    public function available(): array
    {
        $fields = [];
        foreach (\ilUserDefinedFields::_getInstance()->getDefinitions() as $id => $definition) {
            if (!empty($definition['certificate'])) {
                $fields[(string) $id] = (string) $definition['field_name'];
            }
        }
        return $fields;
    }

    /**
     * @return array<string, string> field id => value as plain text
     */
    public function values(int $usr_id): array
    {
        $available = $this->available();
        if ($available === []) {
            return [];
        }
        $data = new \ilUserDefinedData($usr_id);
        $values = [];
        foreach (array_keys($available) as $id) {
            $values[$id] = trim(html_entity_decode(strip_tags($data->get('f_' . $id)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return $values;
    }
}
