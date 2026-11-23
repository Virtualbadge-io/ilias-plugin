<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use ILIAS\Cron\Schedule\CronJobScheduleType;
use Virtualbadge\Ilias\Services;

/**
 * Checks issued certificates in Virtualbadge.io: revoked, replaced or deleted.
 *
 * VERSION SPECIFIC (ILIAS 10 cron API). ILIAS 11: \ILIAS\Cron\CronJob, see branch release_11.
 */
class ilVirtualbadgeVerifyJob extends ilCronJob
{
    public const ID = 'xvbd_verify';

    public function __construct(private readonly ilVirtualbadgePlugin $plugin)
    {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('cron_verify');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('cron_verify_info');
    }

    public function hasAutoActivation(): bool
    {
        return true;
    }

    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    public function getDefaultScheduleType(): CronJobScheduleType
    {
        return CronJobScheduleType::SCHEDULE_TYPE_IN_HOURS;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 1;
    }

    public function run(): ilCronJobResult
    {
        $services = Services::get($this->plugin);
        $count = $services->issuer()->verifyIssued($services->settings());
        $result = new ilCronJobResult();
        $result->setStatus($count > 0 ? ilCronJobResult::STATUS_OK : ilCronJobResult::STATUS_NO_ACTION);
        $result->setMessage(sprintf('%d checked', $count));
        return $result;
    }
}
