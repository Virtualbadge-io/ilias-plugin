<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Issue\Instance;
use Virtualbadge\Core\Issue\Issue;

/**
 * Reacts to ILIAS events (registered in plugin.xml). Only queues issues; the requests to
 * Virtualbadge.io are sent by the cron job, so events never wait for the API.
 */
class EventHandler
{
    public function __construct(private readonly Services $services)
    {
    }

    /**
     * @param array<string, mixed> $parameter
     */
    public function handle(string $component, string $event, array $parameter): void
    {
        if ($component === 'components/ILIAS/Tracking' && $event === 'updateStatus') {
            $this->updateStatus((int) ($parameter['obj_id'] ?? 0), (int) ($parameter['usr_id'] ?? 0), (int) ($parameter['status'] ?? 0));
        } elseif (in_array($component, ['Services/User', 'components/ILIAS/User'], true) && $event === 'deleteUser') {
            $usr_id = (int) ($parameter['usr_id'] ?? 0);
            if ($usr_id > 0) {
                // Local records only. Certificates in Virtualbadge.io stay valid.
                $this->services->issues()->deleteForUser($usr_id);
            }
        }
    }

    /**
     * Learning progress of an object changed for a user.
     */
    public function updateStatus(int $obj_id, int $usr_id, int $status): void
    {
        if ($obj_id <= 0 || $usr_id <= 0) {
            return;
        }
        $completed = $status === \ilLPStatus::LP_STATUS_COMPLETED_NUM;
        $issuer = $this->services->issuer();
        $lp = new LearningProgress($this->db());

        foreach ($this->services->instances()->affectedBy($obj_id) as $instance) {
            if (!$instance->isConfigured() || $instance->obj_id === $obj_id) {
                continue;
            }
            if ($instance->trigger === Instance::TRIGGER_COMPLETION && $instance->course_obj_id === $obj_id && $completed) {
                $issuer->queue($instance, $usr_id, Issue::SOURCE_COMPLETION, $lp->completedAt($obj_id, $usr_id) ?? time());
            }
            if ($instance->trigger === Instance::TRIGGER_OBJECT && $instance->trigger_obj_id === $obj_id) {
                $this->objectChanged($instance, $usr_id, $completed);
            }
        }

        // The Virtualbadge.io object may itself be part of the course's learning progress collection:
        // then the course is completed only after the certificate, so issue once all other items are done.
        if ($completed) {
            $this->collectionItemCompleted($obj_id, $usr_id, $lp);
        }
    }

    private function objectChanged(Instance $instance, int $usr_id, bool $completed): void
    {
        $issuer = $this->services->issuer();
        if ($instance->min_percent !== null && TestResults::isTest($instance->trigger_obj_id)) {
            $result = (new TestResults($this->db()))->result($instance->trigger_obj_id, $usr_id);
            if ($result !== null && $result['percent'] >= $instance->min_percent) {
                $issuer->queue($instance, $usr_id, Issue::SOURCE_OBJECT, time());
            }
            return;
        }
        if ($completed) {
            $issuer->queue($instance, $usr_id, Issue::SOURCE_OBJECT, time());
        }
    }

    private function collectionItemCompleted(int $obj_id, int $usr_id, LearningProgress $lp): void
    {
        foreach (\ilObject::_getAllReferences($obj_id) as $ref_id) {
            $course_ref = (int) $this->tree()->checkForParentType((int) $ref_id, 'crs');
            if ($course_ref <= 0) {
                continue;
            }
            foreach ($this->services->instances()->inCourse(\ilObject::_lookupObjId($course_ref)) as $instance) {
                if ($instance->trigger !== Instance::TRIGGER_COMPLETION || !$instance->isConfigured()) {
                    continue;
                }
                $others = $lp->otherCollectionItems($instance);
                if ($others === null || !in_array((int) $ref_id, $others, true)) {
                    continue;
                }
                if ($lp->completedAll($others, [$usr_id]) === [$usr_id]) {
                    $this->services->issuer()->queue($instance, $usr_id, Issue::SOURCE_COMPLETION, time());
                }
            }
        }
    }

    private function db(): \ilDBInterface
    {
        global $DIC;
        return $DIC->database();
    }

    private function tree(): \ilTree
    {
        global $DIC;
        return $DIC->repositoryTree();
    }
}
