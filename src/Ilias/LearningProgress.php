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

/**
 * Learning progress lookups: who completed a course or object, and when.
 */
class LearningProgress
{
    public function __construct(private readonly \ilDBInterface $db)
    {
    }

    /**
     * Users with status "completed" for an object and the time of the status change.
     *
     * @param int[]|null $usr_ids restrict to these users
     * @return array<int, int> user id => time
     */
    public function completed(int $obj_id, ?array $usr_ids = null): array
    {
        if ($usr_ids === []) {
            return [];
        }
        $sql = 'SELECT usr_id, status_changed FROM ut_lp_marks WHERE obj_id = '
            . $this->db->quote($obj_id, \ilDBConstants::T_INTEGER)
            . ' AND status = ' . $this->db->quote(\ilLPStatus::LP_STATUS_COMPLETED_NUM, \ilDBConstants::T_INTEGER);
        if ($usr_ids !== null) {
            $sql .= ' AND ' . $this->db->in('usr_id', $usr_ids, false, \ilDBConstants::T_INTEGER);
        }
        $result = $this->db->query($sql);
        $users = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $time = $row['status_changed'] ? strtotime((string) $row['status_changed']) : false;
            $users[(int) $row['usr_id']] = $time !== false ? $time : time();
        }
        return $users;
    }

    public function completedAt(int $obj_id, int $usr_id): ?int
    {
        return $this->completed($obj_id, [$usr_id])[$usr_id] ?? null;
    }

    /**
     * Ref ids of the other objects in the course's learning progress collection, if the
     * Virtualbadge.io object itself is part of it. Null if it is not.
     *
     * In that case the course can only be completed after the certificate is issued, so the
     * certificate is issued as soon as all other objects of the collection are completed.
     * Groupings of the collection are not evaluated, every other item counts as required.
     *
     * @return int[]|null
     */
    public function otherCollectionItems(Instance $instance): ?array
    {
        if ($instance->course_obj_id <= 0 || $instance->ref_id <= 0) {
            return null;
        }
        $olp = \ilObjectLP::getInstance($instance->course_obj_id);
        if ($olp->getCurrentMode() !== \ilLPObjSettings::LP_MODE_COLLECTION) {
            return null;
        }
        $collection = $olp->getCollectionInstance();
        if ($collection === null) {
            return null;
        }
        $items = array_map('intval', $collection->getItems());
        if (!in_array($instance->ref_id, $items, true)) {
            return null;
        }
        return array_values(array_diff($items, [$instance->ref_id]));
    }

    /**
     * Users who completed all other items of the collection, see otherCollectionItems().
     *
     * @param int[] $item_refs
     * @param int[] $usr_ids candidates
     * @return int[]
     */
    public function completedAll(array $item_refs, array $usr_ids): array
    {
        if ($item_refs === [] || $usr_ids === []) {
            return [];
        }
        $done = array_flip($usr_ids);
        foreach ($item_refs as $ref) {
            $completed = $this->completed(\ilObject::_lookupObjId($ref), array_keys($done));
            $done = array_intersect_key($done, $completed);
            if ($done === []) {
                break;
            }
        }
        return array_keys($done);
    }
}
