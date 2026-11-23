<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Issue;

use Virtualbadge\Core\Fields\SourceValues;

/**
 * What the issuer needs from the learning platform. Implemented for ILIAS in
 * Virtualbadge\Ilias\IliasEnvironment and by a fake in the tests.
 */
interface Environment
{
    /**
     * Settings of a Virtualbadge.io object, null if it no longer exists.
     */
    public function instance(int $obj_id): ?Instance;

    /**
     * Whether the user may receive the certificate: member of the course and the object is accessible to them.
     */
    public function isEligible(Instance $instance, int $usr_id): bool;

    /**
     * Email address of an active user, null if the user does not exist.
     */
    public function userEmail(int $usr_id): ?string;

    /**
     * First and last name of the user.
     */
    public function userFullName(int $usr_id): string;

    /**
     * Values the template fields can be filled with, for one user.
     */
    public function sourceValues(Instance $instance, int $usr_id, Issue $issue): SourceValues;

    /**
     * Sources that may be used in the field mapping of an object: those of this installation and the custom
     * metadata fields of the object's course (none if the object is not inside a course).
     *
     * @return string[]
     */
    public function validSources(Instance $instance): array;

    /**
     * Human readable name of a source of an object, for messages and the settings page.
     */
    public function sourceLabel(Instance $instance, string $source): string;

    /**
     * Users who meet the condition of the object and when they met it.
     *
     * @return array<int, int> user id => time
     */
    public function conditionMet(Instance $instance): array;

    /**
     * Identifier of this ILIAS installation and client, part of the reference stored in Virtualbadge.io.
     */
    public function siteId(): string;

    /**
     * Called after an issue got its certificate or changed status, e.g. to update the learning progress.
     */
    public function issueChanged(Instance $instance, Issue $issue): void;

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void;
}
