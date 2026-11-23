<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use ILIAS\User\Context;
use ILIAS\User\Profile\Profile;

/**
 * Custom user fields that may be used on certificates.
 *
 * VERSION SPECIFIC (ILIAS 11): uses the profile service (\ILIAS\User\Profile\Profile).
 * ILIAS 10: ilUserDefinedFields / ilUserDefinedData, see branch release_10.
 *
 * Only fields with the ILIAS option "available in certificates" are offered,
 * the same rule ILIAS applies to its own certificates.
 */
class ProfileFields
{
    private function profile(): Profile
    {
        global $DIC;
        return $DIC['user']->getProfile();
    }

    /**
     * @return array<string, \ILIAS\User\Profile\Fields\Field>
     */
    private function fields(): array
    {
        return $this->profile()->getVisibleUserDefinedFields(Context::Certificate);
    }

    /**
     * @return array<string, string> field id => name
     */
    public function available(): array
    {
        global $DIC;
        $fields = [];
        foreach ($this->fields() as $id => $field) {
            $fields[(string) $id] = $field->getLabel($DIC->language());
        }
        return $fields;
    }

    /**
     * @return array<string, string> field id => value as plain text
     */
    public function values(int $usr_id): array
    {
        $fields = $this->fields();
        if ($fields === [] || !\ilObjUser::_exists($usr_id, false, 'usr')) {
            return [];
        }
        $user = new \ilObjUser($usr_id);
        $values = [];
        foreach ($fields as $id => $field) {
            $value = $field->retrieveValueFromUser($user);
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            $values[(string) $id] = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return $values;
    }
}
