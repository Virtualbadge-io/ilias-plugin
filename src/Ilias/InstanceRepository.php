<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Fields\FieldMapper;
use Virtualbadge\Core\Issue\Instance;

/**
 * Settings of the Virtualbadge.io objects (table rep_robj_xvbd_data).
 *
 * The course of an object is stored with it, so that learning progress events can find the
 * objects of a course with one query. It is refreshed whenever an object is read.
 */
class InstanceRepository
{
    /** @var array<int, Instance|null> */
    private array $cache = [];

    public function __construct(
        private readonly \ilDBInterface $db,
        private readonly \ilTree $tree
    ) {
    }

    /**
     * Settings of an object that is in the repository (not deleted, not in the trash).
     */
    public function find(int $obj_id): ?Instance
    {
        if (array_key_exists($obj_id, $this->cache)) {
            return $this->cache[$obj_id];
        }
        $instance = $this->load($obj_id);
        if ($instance !== null && !$this->refreshPlacement($instance)) {
            $instance = null;
        }
        return $this->cache[$obj_id] = $instance;
    }

    /**
     * Settings of an object wherever it is, e.g. in the trash. Null only if there is no row.
     */
    public function load(int $obj_id): ?Instance
    {
        $result = $this->db->queryF('SELECT * FROM ' . Tables::DATA . ' WHERE obj_id = %s', [\ilDBConstants::T_INTEGER], [$obj_id]);
        $row = $this->db->fetchAssoc($result);
        return $row !== null ? $this->instance($row) : null;
    }

    public function create(int $obj_id): Instance
    {
        $instance = new Instance();
        $instance->obj_id = $obj_id;
        $this->db->insert(Tables::DATA, $this->values($instance));
        unset($this->cache[$obj_id]);
        return $instance;
    }

    public function save(Instance $instance): void
    {
        $this->refreshPlacement($instance);
        $values = $this->values($instance);
        unset($values['obj_id']);
        $this->db->update(Tables::DATA, $values, ['obj_id' => [\ilDBConstants::T_INTEGER, $instance->obj_id]]);
        $this->cache[$instance->obj_id] = $instance;
    }

    public function delete(int $obj_id): void
    {
        $this->db->manipulateF('DELETE FROM ' . Tables::DATA . ' WHERE obj_id = %s', [\ilDBConstants::T_INTEGER], [$obj_id]);
        unset($this->cache[$obj_id]);
    }

    /**
     * Objects that may react to a learning progress change of $obj_id: objects in that course
     * and objects whose trigger is that object.
     *
     * @return Instance[]
     */
    public function affectedBy(int $obj_id): array
    {
        $result = $this->db->queryF(
            'SELECT obj_id FROM ' . Tables::DATA . ' WHERE course_obj_id = %s OR trigger_obj_id = %s',
            [\ilDBConstants::T_INTEGER, \ilDBConstants::T_INTEGER],
            [$obj_id, $obj_id]
        );
        $instances = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $instance = $this->find((int) $row['obj_id']);
            if ($instance !== null) {
                $instances[] = $instance;
            }
        }
        return $instances;
    }

    /**
     * Objects of a course whose own learning progress is part of the course's collection.
     *
     * @return Instance[]
     */
    public function inCourse(int $course_obj_id): array
    {
        $result = $this->db->queryF(
            'SELECT obj_id FROM ' . Tables::DATA . ' WHERE course_obj_id = %s',
            [\ilDBConstants::T_INTEGER],
            [$course_obj_id]
        );
        $instances = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $instance = $this->find((int) $row['obj_id']);
            if ($instance !== null) {
                $instances[] = $instance;
            }
        }
        return $instances;
    }

    /**
     * Update ref id and course of an object from the repository tree.
     *
     * @return bool false if the object is no longer in the repository (deleted or in the trash)
     */
    private function refreshPlacement(Instance $instance): bool
    {
        $ref_id = 0;
        foreach (\ilObject::_getAllReferences($instance->obj_id) as $ref) {
            if (!\ilObject::_isInTrash((int) $ref)) {
                $ref_id = (int) $ref;
                break;
            }
        }
        if ($ref_id === 0) {
            return false;
        }
        $course_ref = (int) $this->tree->checkForParentType($ref_id, 'crs');
        $course_obj = $course_ref > 0 ? \ilObject::_lookupObjId($course_ref) : 0;
        $trigger_obj = $instance->trigger_ref_id > 0 ? \ilObject::_lookupObjId($instance->trigger_ref_id) : 0;
        if ($ref_id !== $instance->ref_id || $course_ref !== $instance->course_ref_id
            || $course_obj !== $instance->course_obj_id || $trigger_obj !== $instance->trigger_obj_id) {
            $instance->ref_id = $ref_id;
            $instance->course_ref_id = $course_ref;
            $instance->course_obj_id = $course_obj;
            $instance->trigger_obj_id = $trigger_obj;
            $this->db->update(Tables::DATA, [
                'course_ref_id' => [\ilDBConstants::T_INTEGER, $course_ref],
                'course_obj_id' => [\ilDBConstants::T_INTEGER, $course_obj],
                'trigger_obj_id' => [\ilDBConstants::T_INTEGER, $trigger_obj],
            ], ['obj_id' => [\ilDBConstants::T_INTEGER, $instance->obj_id]]);
        }
        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function instance(array $row): Instance
    {
        $instance = new Instance();
        $instance->obj_id = (int) $row['obj_id'];
        $instance->course_ref_id = (int) $row['course_ref_id'];
        $instance->course_obj_id = (int) $row['course_obj_id'];
        $instance->org = (string) $row['org'];
        $instance->template_id = (string) $row['template_id'];
        $instance->template_name = (string) $row['template_name'];
        $instance->template_type = (string) ($row['template_type'] ?: 'certificate');
        $map = json_decode((string) $row['field_map'], true);
        $instance->field_map = [];
        foreach (is_array($map) ? $map : [] as $field => $entry) {
            $instance->field_map[(string) $field] = FieldMapper::normalize($entry);
        }
        $trigger = (string) $row['trigger_type'];
        $instance->trigger = in_array($trigger, Instance::TRIGGERS, true) ? $trigger : Instance::TRIGGER_COMPLETION;
        $instance->trigger_ref_id = (int) $row['trigger_ref_id'];
        $instance->trigger_obj_id = (int) $row['trigger_obj_id'];
        $instance->min_percent = $row['min_percent'] === null ? null : (float) $row['min_percent'];
        $instance->send_email = (bool) $row['send_email'];
        $instance->completion_issued = (bool) $row['completion_issued'];
        return $instance;
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    private function values(Instance $instance): array
    {
        return [
            'obj_id' => [\ilDBConstants::T_INTEGER, $instance->obj_id],
            'course_ref_id' => [\ilDBConstants::T_INTEGER, $instance->course_ref_id],
            'course_obj_id' => [\ilDBConstants::T_INTEGER, $instance->course_obj_id],
            'org' => [\ilDBConstants::T_TEXT, $instance->org],
            'template_id' => [\ilDBConstants::T_TEXT, $instance->template_id],
            'template_name' => [\ilDBConstants::T_TEXT, mb_substr($instance->template_name, 0, 255)],
            'template_type' => [\ilDBConstants::T_TEXT, $instance->template_type],
            'field_map' => [\ilDBConstants::T_CLOB, json_encode($instance->field_map, JSON_UNESCAPED_UNICODE)],
            'trigger_type' => [\ilDBConstants::T_TEXT, $instance->trigger],
            'trigger_ref_id' => [\ilDBConstants::T_INTEGER, $instance->trigger_ref_id],
            'trigger_obj_id' => [\ilDBConstants::T_INTEGER, $instance->trigger_obj_id],
            'min_percent' => [\ilDBConstants::T_FLOAT, $instance->min_percent],
            'send_email' => [\ilDBConstants::T_INTEGER, (int) $instance->send_email],
            'completion_issued' => [\ilDBConstants::T_INTEGER, (int) $instance->completion_issued],
        ];
    }
}
