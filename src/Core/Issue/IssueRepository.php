<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Issue;

interface IssueRepository
{
    public function find(int $id): ?Issue;

    public function findFor(int $obj_id, int $usr_id): ?Issue;

    /**
     * Insert a new issue and set its id.
     *
     * @return bool false if an issue for the same object and user exists already
     */
    public function insert(Issue $issue): bool;

    public function update(Issue $issue): void;

    /**
     * Issues with something to do (queued, retrying or pending) whose time has come, the earliest first.
     *
     * @return Issue[]
     */
    public function due(int $now, int $limit): array;

    /**
     * Organisations with issues to verify: issued certificates or pending issues after their last status check.
     *
     * @return string[]
     */
    public function issuedOrgs(): array;

    /**
     * Issues to verify of an organisation (issued, or pending without next status check) not checked since
     * $before, the longest unchecked first.
     *
     * @return Issue[]
     */
    public function toVerify(string $org, int $before, int $limit): array;

    /**
     * @return Issue[] all issues of an object
     */
    public function forObject(int $obj_id): array;

    /**
     * @return int[] users who have an issue of the object, whatever its status
     */
    public function usersWithIssue(int $obj_id): array;

    public function deleteForUser(int $usr_id): void;

    public function deleteForObject(int $obj_id): void;

    /**
     * Number of issues per status in the given period (by last modification), for the status overview.
     *
     * @return array<string, int>
     */
    public function countByStatus(?int $since = null): array;
}
