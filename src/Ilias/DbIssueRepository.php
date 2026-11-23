<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Ilias;

use Virtualbadge\Core\Issue\Issue;
use Virtualbadge\Core\Issue\IssueRepository;

class DbIssueRepository implements IssueRepository
{
    private const TYPES = [
        'id' => \ilDBConstants::T_INTEGER,
        'obj_id' => \ilDBConstants::T_INTEGER,
        'usr_id' => \ilDBConstants::T_INTEGER,
        'org' => \ilDBConstants::T_TEXT,
        'template_id' => \ilDBConstants::T_TEXT,
        'reference' => \ilDBConstants::T_TEXT,
        'status' => \ilDBConstants::T_TEXT,
        'source' => \ilDBConstants::T_TEXT,
        'remote_id' => \ilDBConstants::T_TEXT,
        'replace_remote_id' => \ilDBConstants::T_TEXT,
        'previous_remote_id' => \ilDBConstants::T_TEXT,
        'certificate_url' => \ilDBConstants::T_TEXT,
        'validation_url' => \ilDBConstants::T_TEXT,
        'completed_at' => \ilDBConstants::T_INTEGER,
        'attempts' => \ilDBConstants::T_INTEGER,
        'check_no' => \ilDBConstants::T_INTEGER,
        'next_run' => \ilDBConstants::T_INTEGER,
        'email_sent' => \ilDBConstants::T_INTEGER,
        'time_email_sent' => \ilDBConstants::T_INTEGER,
        'last_error' => \ilDBConstants::T_TEXT,
        'time_created' => \ilDBConstants::T_INTEGER,
        'time_modified' => \ilDBConstants::T_INTEGER,
        'time_issued' => \ilDBConstants::T_INTEGER,
        'time_checked' => \ilDBConstants::T_INTEGER,
    ];

    private const INT_FIELDS = ['id', 'obj_id', 'usr_id', 'completed_at', 'attempts', 'check_no', 'email_sent',
        'time_created', 'time_modified'];

    public function __construct(private readonly \ilDBInterface $db)
    {
    }

    public function find(int $id): ?Issue
    {
        return $this->first('id = ' . $this->db->quote($id, \ilDBConstants::T_INTEGER));
    }

    public function findFor(int $obj_id, int $usr_id): ?Issue
    {
        return $this->first(
            'obj_id = ' . $this->db->quote($obj_id, \ilDBConstants::T_INTEGER)
            . ' AND usr_id = ' . $this->db->quote($usr_id, \ilDBConstants::T_INTEGER)
        );
    }

    public function insert(Issue $issue): bool
    {
        if ($this->findFor($issue->obj_id, $issue->usr_id) !== null) {
            return false;
        }
        $issue->id = $this->db->nextId(Tables::ISSUE);
        try {
            $this->db->insert(Tables::ISSUE, $this->values($issue));
        } catch (\Exception $e) {
            // Unique index on (obj_id, usr_id): another process was faster.
            $issue->id = 0;
            return false;
        }
        $issue->stored_status = $issue->status;
        return true;
    }

    /**
     * Writes only if the stored status did not change since the issue was read (see Issue::$stored_status):
     * otherwise another process changed it in the meantime and its change wins.
     */
    public function update(Issue $issue): void
    {
        $values = $this->values($issue);
        unset($values['id']);
        $where = ['id' => [\ilDBConstants::T_INTEGER, $issue->id]];
        if ($issue->stored_status !== null) {
            $where['status'] = [\ilDBConstants::T_TEXT, $issue->stored_status];
        }
        $this->db->update(Tables::ISSUE, $values, $where);
        $issue->stored_status = $issue->status;
    }

    public function due(int $now, int $limit): array
    {
        $this->db->setLimit($limit, 0);
        return $this->select(
            $this->db->in('status', [Issue::STATUS_QUEUED, Issue::STATUS_RETRYING, Issue::STATUS_PENDING], false, \ilDBConstants::T_TEXT)
            . ' AND next_run IS NOT NULL AND next_run <= ' . $this->db->quote($now, \ilDBConstants::T_INTEGER),
            'next_run, id'
        );
    }

    public function issuedOrgs(): array
    {
        $result = $this->db->query(
            'SELECT DISTINCT org FROM ' . Tables::ISSUE
            . ' WHERE ' . $this->toVerifyCondition()
        );
        $orgs = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $orgs[] = (string) $row['org'];
        }
        return $orgs;
    }

    public function toVerify(string $org, int $before, int $limit): array
    {
        $this->db->setLimit($limit, 0);
        return $this->select(
            'org = ' . $this->db->quote($org, \ilDBConstants::T_TEXT)
            . ' AND ' . $this->toVerifyCondition()
            . ' AND COALESCE(time_checked, 0) < ' . $this->db->quote($before, \ilDBConstants::T_INTEGER),
            'COALESCE(time_checked, 0), id'
        );
    }

    /**
     * Issued certificates and pending issues after their last status check.
     */
    private function toVerifyCondition(): string
    {
        return 'remote_id IS NOT NULL AND (status = ' . $this->db->quote(Issue::STATUS_ISSUED, \ilDBConstants::T_TEXT)
            . ' OR (status = ' . $this->db->quote(Issue::STATUS_PENDING, \ilDBConstants::T_TEXT) . ' AND next_run IS NULL))';
    }

    public function forObject(int $obj_id): array
    {
        return $this->select('obj_id = ' . $this->db->quote($obj_id, \ilDBConstants::T_INTEGER), 'id');
    }

    public function usersWithIssue(int $obj_id): array
    {
        $result = $this->db->queryF(
            'SELECT usr_id FROM ' . Tables::ISSUE . ' WHERE obj_id = %s',
            [\ilDBConstants::T_INTEGER],
            [$obj_id]
        );
        $users = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $users[] = (int) $row['usr_id'];
        }
        return $users;
    }

    public function deleteForUser(int $usr_id): void
    {
        $this->db->manipulateF('DELETE FROM ' . Tables::ISSUE . ' WHERE usr_id = %s', [\ilDBConstants::T_INTEGER], [$usr_id]);
    }

    public function deleteForObject(int $obj_id): void
    {
        $this->db->manipulateF('DELETE FROM ' . Tables::ISSUE . ' WHERE obj_id = %s', [\ilDBConstants::T_INTEGER], [$obj_id]);
    }

    public function countByStatus(?int $since = null): array
    {
        $where = $since !== null ? ' WHERE time_modified >= ' . $this->db->quote($since, \ilDBConstants::T_INTEGER) : '';
        $result = $this->db->query('SELECT status, COUNT(*) cnt FROM ' . Tables::ISSUE . $where . ' GROUP BY status');
        $counts = array_fill_keys(Issue::STATUSES, 0);
        while ($row = $this->db->fetchAssoc($result)) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Latest errors, for the status overview of the administration.
     *
     * @return Issue[]
     */
    public function latestErrors(int $limit): array
    {
        $this->db->setLimit($limit, 0);
        return $this->select(
            $this->db->in('status', [Issue::STATUS_FAILED, Issue::STATUS_RETRYING], false, \ilDBConstants::T_TEXT),
            'time_modified DESC'
        );
    }

    private function first(string $where): ?Issue
    {
        $this->db->setLimit(1, 0);
        return $this->select($where, 'id')[0] ?? null;
    }

    /**
     * @return Issue[]
     */
    private function select(string $where, string $order): array
    {
        $result = $this->db->query('SELECT * FROM ' . Tables::ISSUE . ' WHERE ' . $where . ' ORDER BY ' . $order);
        $issues = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $issues[] = $this->issue($row);
        }
        return $issues;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function issue(array $row): Issue
    {
        $issue = new Issue();
        foreach (array_keys(self::TYPES) as $field) {
            $value = $row[$field] ?? null;
            if (in_array($field, self::INT_FIELDS, true)) {
                $issue->$field = (int) $value;
            } elseif (self::TYPES[$field] === \ilDBConstants::T_INTEGER) {
                $issue->$field = $value === null ? null : (int) $value;
            } elseif (in_array($field, ['org', 'template_id', 'reference', 'status', 'source'], true)) {
                $issue->$field = (string) $value;
            } else {
                $issue->$field = $value === null || $value === '' ? null : (string) $value;
            }
        }
        $issue->stored_status = $issue->status;
        return $issue;
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    private function values(Issue $issue): array
    {
        $values = [];
        foreach (self::TYPES as $field => $type) {
            $values[$field] = [$type, $issue->$field];
        }
        if ($issue->last_error !== null) {
            $values['last_error'] = [\ilDBConstants::T_TEXT, mb_substr($issue->last_error, 0, 1000)];
        }
        return $values;
    }
}
