<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Api\ApiClient;
use Virtualbadge\Core\Api\CurlTransport;
use Virtualbadge\Core\Clock;
use Virtualbadge\Core\Config\SecretBox;
use Virtualbadge\Core\Config\Settings;
use Virtualbadge\Core\Fields\FieldMapper;
use Virtualbadge\Core\Fields\ValueText;
use Virtualbadge\Core\Issue\Issuer;
use Virtualbadge\Core\SystemClock;
use Virtualbadge\Core\Texts;

/**
 * Wires the plugin's services from the ILIAS DIC. One instance per request.
 */
class Services
{
    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?ApiClient $api = null;
    private ?Issuer $issuer = null;
    private ?DbIssueRepository $issues = null;
    private ?InstanceRepository $instances = null;
    private ?IliasEnvironment $environment = null;
    private ?ProfileFields $profile = null;
    private ?CourseMetadata $metadata = null;
    private ?DbCache $cache = null;
    private ?Texts $texts = null;
    private readonly Clock $clock;

    private function __construct(
        private readonly \ILIAS\DI\Container $dic,
        private readonly \ilPlugin $plugin
    ) {
        $this->clock = new SystemClock();
    }

    public static function get(\ilPlugin $plugin): self
    {
        global $DIC;
        return self::$instance ??= new self($DIC, $plugin);
    }

    /**
     * New instance without cached data, e.g. after custom metadata records were changed in the same process.
     * Does not replace the instance returned by get().
     */
    public static function fresh(\ilPlugin $plugin): self
    {
        global $DIC;
        return new self($DIC, $plugin);
    }

    public function plugin(): \ilPlugin
    {
        return $this->plugin;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings(
            new IliasSettingsStore(new \ilSetting(Tables::SETTINGS_MODULE)),
            new SecretBox(SecretBox::fileKey(self::keyFile()))
        );
    }

    /**
     * File with the key for the API keys, in the client data directory outside the web root.
     */
    public static function keyFile(): string
    {
        return rtrim((string) CLIENT_DATA_DIR, '/') . '/xvbd/secret.key';
    }

    public function cache(): DbCache
    {
        return $this->cache ??= new DbCache($this->dic->database());
    }

    public function api(): ApiClient
    {
        return $this->api ??= new ApiClient(
            $this->settings(),
            new CurlTransport(self::proxy()),
            $this->cache(),
            new DbRateLimitStore($this->dic->database()),
            $this->clock,
            $this->texts()
        );
    }

    public function issues(): DbIssueRepository
    {
        return $this->issues ??= new DbIssueRepository($this->dic->database());
    }

    public function instances(): InstanceRepository
    {
        return $this->instances ??= new InstanceRepository($this->dic->database(), $this->dic->repositoryTree());
    }

    public function profileFields(): ProfileFields
    {
        return $this->profile ??= new ProfileFields();
    }

    /**
     * Custom metadata of courses. Values in the plugin's date format and with the decimal separator of the
     * ILIAS default language: the cron job runs in the cron user's language.
     */
    public function courseMetadata(): CourseMetadata
    {
        $lng = $this->dic->language();
        return $this->metadata ??= new CourseMetadata(
            new ValueText(
                $this->settings()->dateFormat(),
                self::decimalSeparator($lng->txtlng('common', 'lang_sep_decimal', $lng->getDefaultLanguage()))
            ),
            $this->dic->logger()->root()
        );
    }

    public function environment(): IliasEnvironment
    {
        return $this->environment ??= new IliasEnvironment(
            $this->instances(),
            new LearningProgress($this->dic->database()),
            $this->profileFields(),
            new TestResults($this->dic->database()),
            $this->courseMetadata(),
            $this->dic->repositoryTree(),
            $this->dic->access(),
            $this->dic->language(),
            $this->dic->logger()->root(),
            fn(string $key): string => $this->plugin->txt($key)
        );
    }

    public function mapper(): FieldMapper
    {
        return new FieldMapper(
            $this->settings()->dateFormat(),
            self::decimalSeparator($this->dic->language()->txt('lang_sep_decimal')),
            $this->texts()
        );
    }

    /**
     * Texts of the messages the plugin writes into the latest error, in the ILIAS default language.
     */
    public function texts(): Texts
    {
        return $this->texts ??= new IliasTexts($this->dic->language());
    }

    /**
     * Decimal separator from the language entry "lang_sep_decimal", "," if the entry is missing.
     */
    private static function decimalSeparator(string $text): string
    {
        if ($text === '' || str_contains($text, 'lang_sep_decimal') || mb_strlen($text) > 1) {
            return ',';
        }
        return $text;
    }

    public function issuer(): Issuer
    {
        return $this->issuer ??= new Issuer(
            $this->issues(),
            $this->api(),
            $this->environment(),
            $this->mapper(),
            $this->clock,
            $this->texts()
        );
    }

    /**
     * Organisation of a course from the category map.
     */
    public function courseOrg(int $course_ref_id): string
    {
        if ($course_ref_id <= 0) {
            return Settings::MAIN_ORG;
        }
        return $this->settings()->orgForCategories($this->environment()->categoryPath($course_ref_id));
    }

    /**
     * Organisations a user may choose for an object in a course: the course's organisation,
     * the current one, and all organisations for ILIAS administrators.
     *
     * @return array<string, string> key => name
     */
    public function allowedOrgs(int $course_ref_id, ?string $current, int $usr_id): array
    {
        $orgs = $this->settings()->orgs($this->plugin->txt('main_org'));
        if ($this->dic->rbac()->system()->checkAccessOfUser($usr_id, 'visible,read', SYSTEM_FOLDER_ID)) {
            return $orgs;
        }
        $allowed = array_intersect_key($orgs, [$this->courseOrg($course_ref_id) => true]);
        if ($current !== null && isset($orgs[$current])) {
            $allowed[$current] = $orgs[$current];
        }
        return $allowed;
    }

    private static function proxy(): ?string
    {
        $proxy = \ilProxySettings::_getInstance();
        if (!$proxy->isActive() || $proxy->getHost() === '') {
            return null;
        }
        return $proxy->getHost() . ($proxy->getPort() > 0 ? ':' . $proxy->getPort() : '');
    }
}
