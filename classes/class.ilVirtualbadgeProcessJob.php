<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use ILIAS\Cron\CronJob;
use ILIAS\Cron\Job\JobResult;
use ILIAS\Cron\Job\Schedule\JobScheduleType;
use Virtualbadge\Ilias\Services;

/**
 * Sends queued certificates to Virtualbadge.io, retries temporary errors and checks pending certificates.
 *
 * VERSION SPECIFIC (ILIAS 11 cron API). ILIAS 10: ilCronJob, see branch release_10.
 */
class ilVirtualbadgeProcessJob extends CronJob
{
    public const ID = 'xvbd_process';

    public function __construct(private readonly ilVirtualbadgePlugin $plugin)
    {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getTitle(): string
    {
        return $this->plugin->txt('cron_process');
    }

    public function getDescription(): string
    {
        return $this->plugin->txt('cron_process_info');
    }

    public function hasAutoActivation(): bool
    {
        return true;
    }

    public function hasFlexibleSchedule(): bool
    {
        return true;
    }

    public function getDefaultScheduleType(): JobScheduleType
    {
        return JobScheduleType::IN_MINUTES;
    }

    public function getDefaultScheduleValue(): ?int
    {
        return 1;
    }

    public function run(): JobResult
    {
        $result = new JobResult();
        $count = Services::get($this->plugin)->issuer()->runDue();
        $result->setStatus($count > 0 ? JobResult::STATUS_OK : JobResult::STATUS_NO_ACTION);
        $result->setMessage(sprintf('%d processed', $count));
        return $result;
    }
}
