<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use Virtualbadge\Core\Issue\Instance;
use Virtualbadge\Core\Issue\Issue;
use Virtualbadge\Ilias\Services;

/**
 * Virtualbadge.io object in a course: issues one certificate or badge template.
 *
 * Learning progress (mode "plugin"): completed once the certificate is issued, if enabled in the settings.
 */
class ilObjVirtualbadge extends ilObjectPlugin implements ilLPStatusPluginInterface
{
    private ?Instance $instance = null;

    final protected function initType(): void
    {
        $this->setType(ilVirtualbadgePlugin::ID);
    }

    private function services(): Services
    {
        return Services::get($this->getPlugin());
    }

    protected function doCreate(bool $clone_mode = false): void
    {
        $this->instance = $this->services()->instances()->create($this->getId());
    }

    protected function doRead(): void
    {
        $instances = $this->services()->instances();
        $this->instance = $instances->find($this->getId()) ?? $instances->load($this->getId());
    }

    protected function doUpdate(): void
    {
        if ($this->instance !== null) {
            $this->services()->instances()->save($this->instance);
        }
    }

    protected function doDelete(): void
    {
        // Local records only. Certificates already issued in Virtualbadge.io stay valid.
        $this->services()->issues()->deleteForObject($this->getId());
        $this->services()->instances()->delete($this->getId());
    }

    /**
     * Copy the settings, not the issued certificates.
     */
    protected function doCloneObject(ilObject2 $new_obj, int $a_target_id, ?int $a_copy_id = null): void
    {
        if (!$new_obj instanceof self || $this->instance === null) {
            return;
        }
        $copy = clone $this->instance;
        $copy->obj_id = $new_obj->getId();
        $copy->ref_id = 0;
        // The trigger object belongs to the old course; it has to be chosen again.
        if ($copy->trigger === Instance::TRIGGER_OBJECT) {
            $copy->trigger_ref_id = 0;
            $copy->trigger_obj_id = 0;
        }
        $new_obj->setInstance($copy);
        $new_obj->update();
    }

    public function getInstance(): Instance
    {
        if ($this->instance === null) {
            $instances = $this->services()->instances();
            // create() only if there is no row at all; an object in the trash keeps its row.
            $this->instance = $instances->find($this->getId())
                ?? $instances->load($this->getId())
                ?? $instances->create($this->getId());
        }
        return $this->instance;
    }

    public function setInstance(Instance $instance): void
    {
        $this->instance = $instance;
    }

    /**
     * @return int[]
     */
    private function usersWithStatus(array $statuses): array
    {
        if (!$this->getInstance()->completion_issued) {
            return [];
        }
        $users = [];
        foreach ($this->services()->issues()->forObject($this->getId()) as $issue) {
            if (in_array($issue->status, $statuses, true)) {
                $users[] = $issue->usr_id;
            }
        }
        return $users;
    }

    public function getLPCompleted(): array
    {
        return $this->usersWithStatus([Issue::STATUS_ISSUED]);
    }

    public function getLPNotAttempted(): array
    {
        return [];
    }

    public function getLPFailed(): array
    {
        return [];
    }

    public function getLPInProgress(): array
    {
        return $this->usersWithStatus([Issue::STATUS_QUEUED, Issue::STATUS_PENDING, Issue::STATUS_RETRYING]);
    }

    public function getLPStatusForUser(int $a_user_id): int
    {
        if (!$this->getInstance()->completion_issued) {
            return ilLPStatus::LP_STATUS_NOT_ATTEMPTED_NUM;
        }
        $issue = $this->services()->issues()->findFor($this->getId(), $a_user_id);
        return match ($issue?->status) {
            Issue::STATUS_ISSUED => ilLPStatus::LP_STATUS_COMPLETED_NUM,
            Issue::STATUS_QUEUED, Issue::STATUS_PENDING, Issue::STATUS_RETRYING => ilLPStatus::LP_STATUS_IN_PROGRESS_NUM,
            default => ilLPStatus::LP_STATUS_NOT_ATTEMPTED_NUM,
        };
    }
}
