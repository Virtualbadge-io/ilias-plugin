<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\EnglishTexts;
use Virtualbadge\Core\Texts;

/**
 * Texts of the plugin's messages from the plugin language files in the ILIAS default language, independent of
 * the language of the current user (the cron job runs as the cron user). Falls back to the English texts if the
 * entry is missing or does not fit the values.
 */
class IliasTexts implements Texts
{
    /** Language module and prefix of the plugin entries: component "rep", slot "robj", plugin id "xvbd". */
    public const PREFIX = 'rep_robj_xvbd';

    public function __construct(
        private readonly \ilLanguage $lng,
        private readonly Texts $fallback = new EnglishTexts()
    ) {
    }

    public function get(string $key, string ...$args): string
    {
        $identifier = self::PREFIX . '_' . $key;
        // txtlng() reads only loaded modules when the default language is the language of the request.
        $this->lng->loadLanguageModule(self::PREFIX);
        $text = $this->lng->txtlng(self::PREFIX, $identifier, $this->lng->getDefaultLanguage());
        if ($text === '' || $text === '-' . $identifier . '-') {
            return $this->fallback->get($key, ...$args);
        }
        try {
            return sprintf($text, ...$args);
        } catch (\ValueError | \ArgumentCountError) {
            return $this->fallback->get($key, ...$args);
        }
    }
}
