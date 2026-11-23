<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Issue;

/**
 * One certificate or badge for one user from one Virtualbadge.io object.
 */
class Issue
{
    /** Waiting to be sent to Virtualbadge.io. */
    public const STATUS_QUEUED = 'queued';
    /** Accepted by Virtualbadge.io, document not yet generated. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_ISSUED = 'issued';
    /** Revoked in Virtualbadge.io. */
    public const STATUS_REVOKED = 'revoked';
    /** Deleted in Virtualbadge.io. */
    public const STATUS_DELETED = 'deleted';
    /** Temporary error, tried again later. */
    public const STATUS_RETRYING = 'retrying';
    /** Permanent error or no attempts left. */
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PENDING,
        self::STATUS_ISSUED,
        self::STATUS_REVOKED,
        self::STATUS_DELETED,
        self::STATUS_RETRYING,
        self::STATUS_FAILED,
    ];

    /** Sources: which trigger created the issue. */
    public const SOURCE_COMPLETION = 'completion';
    public const SOURCE_APPROVAL = 'approval';
    public const SOURCE_OBJECT = 'object';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_RETROACTIVE = 'retroactive';

    public int $id = 0;
    public int $obj_id = 0;
    public int $usr_id = 0;
    public string $org = '';
    public string $template_id = '';
    public string $reference = '';
    public string $status = self::STATUS_QUEUED;
    public string $source = self::SOURCE_COMPLETION;
    public ?string $remote_id = null;
    public ?string $replace_remote_id = null;
    public ?string $previous_remote_id = null;
    public ?string $certificate_url = null;
    public ?string $validation_url = null;
    public int $completed_at = 0;
    public int $attempts = 0;
    /** Number of the next status check of a pending issue. */
    public int $check_no = 0;
    /** Time the issue is processed next, null if nothing is scheduled. */
    public ?int $next_run = null;
    public int $email_sent = 0;
    public ?int $time_email_sent = null;
    public ?string $last_error = null;
    public int $time_created = 0;
    public int $time_modified = 0;
    public ?int $time_issued = null;
    public ?int $time_checked = null;
    /**
     * Status as last read from or written to the store. The store writes only if the stored status is still this one,
     * so a cron run cannot overwrite a tutor action (or the other way round) that happened while it was working.
     */
    public ?string $stored_status = null;

    /**
     * Whether a certificate exists in Virtualbadge.io that the learner can open.
     */
    public function hasCertificate(): bool
    {
        return $this->remote_id !== null && in_array($this->status, [self::STATUS_ISSUED, self::STATUS_PENDING], true);
    }
}
