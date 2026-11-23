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
 * Default permissions for the Virtualbadge.io object type, set on the first activation of the plugin.
 *
 * ILIAS creates the object type and its operations when a repository plugin is activated, but adds
 * no permissions to roles: without this class course members would not see the object and tutors
 * could not open the status list until an administrator grants them.
 *
 * Follows the ILIAS "Initial Permission Guideline" (https://docu.ilias.de/go/wiki/wpage_2273_1357),
 * the same definition ILIAS applies to new core object types:
 * - role templates il_crs_admin, il_crs_tutor, il_crs_member, il_grp_admin, il_grp_member, Author,
 *   Local Administrator and the global role User;
 * - local roles of existing courses and groups that were created from these templates, so the plugin
 *   works in existing courses without "change existing objects" (roles with own settings for the type
 *   are left alone).
 *
 * Works with the reduced environment of the setup CLI: only the database is used.
 */
final class DefaultPermissions
{
    /** Global role folder of ILIAS (role templates and global roles). */
    private const ROLE_FOLDER_ID = 8;
    /** Global role "User". */
    private const USER_ROLE_ID = 4;

    private const ALL = ['visible', 'read', 'write', 'delete', 'copy', 'edit_permission'];

    /**
     * Role template title => object operations, learning progress operations, containers to create the object in.
     * Containers are limited to the parent types of the plugin (crs, grp, fold).
     *
     * @var array<string, array{ops: list<string>, lp: bool, create: list<string>}>
     */
    private const TEMPLATES = [
        'il_crs_admin' => ['ops' => self::ALL, 'lp' => true, 'create' => ['crs', 'grp', 'fold']],
        'il_crs_tutor' => ['ops' => ['visible', 'read', 'write', 'copy'], 'lp' => false, 'create' => ['crs', 'fold']],
        'il_crs_member' => ['ops' => ['visible', 'read'], 'lp' => false, 'create' => []],
        'il_grp_admin' => ['ops' => self::ALL, 'lp' => true, 'create' => ['grp', 'fold']],
        'il_grp_member' => ['ops' => ['visible', 'read'], 'lp' => false, 'create' => []],
        'Author' => ['ops' => self::ALL, 'lp' => true, 'create' => ['crs', 'grp', 'fold']],
        'Local Administrator' => ['ops' => ['visible', 'delete', 'edit_permission'], 'lp' => false, 'create' => []],
    ];

    private const LP_OPS = ['read_learning_progress', 'edit_learning_progress'];

    /** Setting that marks the permissions as applied; removed on uninstall with all plugin settings. */
    public const APPLIED_SETTING = 'default_permissions';

    /** @var array<string, int> operation name => id */
    private array $op_ids = [];

    public function __construct(private readonly \ilDBInterface $db, private readonly string $type)
    {
    }

    /**
     * @return int number of roles that got permissions
     */
    public function apply(): int
    {
        $this->loadOperations();
        if (!isset($this->op_ids['create_' . $this->type])) {
            return 0;
        }
        $this->addLearningProgressOperationsToType();

        $count = 0;
        foreach (self::TEMPLATES as $title => $definition) {
            foreach ($this->roleIds('rolt', $title) as $role_id) {
                $this->grantTemplate($role_id, $definition, self::ROLE_FOLDER_ID);
                $count++;
            }
        }
        $this->grantTemplate(self::USER_ROLE_ID, ['ops' => ['visible', 'read'], 'lp' => false, 'create' => []], self::ROLE_FOLDER_ID);
        $count++;

        foreach ($this->localCourseAndGroupRoles() as [$role_id, $template, $parent_ref_id, $parent_type]) {
            if ($this->hasTemplateFor($role_id, $parent_ref_id)) {
                continue;
            }
            $definition = self::TEMPLATES[$template];
            $this->grantTemplate($role_id, $definition, $parent_ref_id);
            if (in_array($parent_type, $definition['create'], true)) {
                $this->addObjectPermission($role_id, $parent_ref_id, $this->op_ids['create_' . $this->type]);
            }
            $count++;
        }
        return $count;
    }

    private function loadOperations(): void
    {
        $names = array_merge(self::ALL, self::LP_OPS, ['create_' . $this->type]);
        $res = $this->db->query(
            'SELECT ops_id, operation FROM rbac_operations WHERE ' . $this->db->in('operation', $names, false, 'text')
        );
        while ($row = $this->db->fetchAssoc($res)) {
            $this->op_ids[(string) $row['operation']] = (int) $row['ops_id'];
        }
    }

    /**
     * ILIAS adds only the standard operations to a plugin type; learning progress needs its own two.
     */
    private function addLearningProgressOperationsToType(): void
    {
        $res = $this->db->queryF(
            'SELECT obj_id FROM object_data WHERE type = %s AND title = %s',
            ['text', 'text'],
            ['typ', $this->type]
        );
        $row = $this->db->fetchAssoc($res);
        if (!$row) {
            return;
        }
        foreach (self::LP_OPS as $name) {
            if (isset($this->op_ids[$name])) {
                $this->db->replace(
                    'rbac_ta',
                    ['typ_id' => ['integer', (int) $row['obj_id']], 'ops_id' => ['integer', $this->op_ids[$name]]],
                    []
                );
            }
        }
    }

    /**
     * @return int[]
     */
    private function roleIds(string $object_type, string $title): array
    {
        $res = $this->db->queryF(
            'SELECT obj_id FROM object_data WHERE type = %s AND title = %s',
            ['text', 'text'],
            [$object_type, $title]
        );
        $ids = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $ids[] = (int) $row['obj_id'];
        }
        return $ids;
    }

    /**
     * Local roles of courses and groups that follow one of the templates, e.g. "il_crs_member_123".
     *
     * @return list<array{int, string, int, string}> role id, template title, ref id of the course/group, its type
     */
    private function localCourseAndGroupRoles(): array
    {
        $res = $this->db->query(
            'SELECT o.obj_id, o.title, fa.parent, p.type'
            . ' FROM object_data o'
            . ' JOIN rbac_fa fa ON fa.rol_id = o.obj_id AND fa.assign = ' . $this->db->quote('y', 'text')
            . ' JOIN object_reference r ON r.ref_id = fa.parent'
            . ' JOIN object_data p ON p.obj_id = r.obj_id'
            . " WHERE o.type = 'role' AND p.type IN ('crs', 'grp') AND r.deleted IS NULL"
            . " AND (o.title LIKE 'il\\_crs\\_%' OR o.title LIKE 'il\\_grp\\_%')"
        );
        $roles = [];
        while ($row = $this->db->fetchAssoc($res)) {
            $template = preg_replace('/_\d+$/', '', (string) $row['title']);
            if (isset(self::TEMPLATES[$template])) {
                $roles[] = [(int) $row['obj_id'], $template, (int) $row['parent'], (string) $row['type']];
            }
        }
        return $roles;
    }

    private function hasTemplateFor(int $role_id, int $parent): bool
    {
        $res = $this->db->queryF(
            'SELECT 1 FROM rbac_templates WHERE rol_id = %s AND type = %s AND parent = %s',
            ['integer', 'text', 'integer'],
            [$role_id, $this->type, $parent]
        );
        return (bool) $this->db->fetchAssoc($res);
    }

    /**
     * @param array{ops: list<string>, lp: bool, create: list<string>} $definition
     */
    private function grantTemplate(int $role_id, array $definition, int $parent): void
    {
        $names = $definition['ops'];
        if ($definition['lp']) {
            $names = array_merge($names, self::LP_OPS);
        }
        foreach ($names as $name) {
            if (isset($this->op_ids[$name])) {
                $this->setTemplate($role_id, $this->type, $this->op_ids[$name], $parent);
            }
        }
        foreach ($definition['create'] as $container) {
            $this->setTemplate($role_id, $container, $this->op_ids['create_' . $this->type], $parent);
        }
    }

    private function setTemplate(int $role_id, string $type, int $ops_id, int $parent): void
    {
        $this->db->replace(
            'rbac_templates',
            [
                'rol_id' => ['integer', $role_id],
                'type' => ['text', $type],
                'ops_id' => ['integer', $ops_id],
                'parent' => ['integer', $parent],
            ],
            []
        );
    }

    /**
     * Add one operation to the permissions of a role on an existing object (rbac_pa keeps a serialized list).
     */
    private function addObjectPermission(int $role_id, int $ref_id, int $ops_id): void
    {
        $res = $this->db->queryF(
            'SELECT ops_id FROM rbac_pa WHERE rol_id = %s AND ref_id = %s',
            ['integer', 'integer'],
            [$role_id, $ref_id]
        );
        $row = $this->db->fetchAssoc($res);
        if (!$row) {
            // The role has no permissions on the course at all: nothing to extend.
            return;
        }
        $ops = @unserialize((string) $row['ops_id'], ['allowed_classes' => false]);
        if (!is_array($ops) || in_array($ops_id, array_map('intval', $ops), true)) {
            return;
        }
        $ops[] = $ops_id;
        $this->db->manipulateF(
            'UPDATE rbac_pa SET ops_id = %s WHERE rol_id = %s AND ref_id = %s',
            ['text', 'integer', 'integer'],
            [serialize(array_map('intval', $ops)), $role_id, $ref_id]
        );
    }
}
