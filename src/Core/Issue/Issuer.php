<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Issue;

use Virtualbadge\Core\Api\ApiClient;
use Virtualbadge\Core\Api\ApiException;
use Virtualbadge\Core\Clock;
use Virtualbadge\Core\Config\Settings;
use Virtualbadge\Core\EnglishTexts;
use Virtualbadge\Core\Fields\FieldMapper;
use Virtualbadge\Core\Fields\FieldValueMissing;
use Virtualbadge\Core\Fields\MappingInvalid;
use Virtualbadge\Core\Texts;

/**
 * Queues, issues and tracks certificates.
 *
 * ILIAS has no adhoc tasks for plugins, so each
 * issue carries the time of its next step (next_run) and the cron job runs all due issues:
 * queued and retrying issues are sent, pending issues are checked.
 */
class Issuer
{
    /** Emails Virtualbadge.io sends at most per certificate. */
    public const MAX_EMAILS = 10;

    /** Attempts before an issue is marked as failed. */
    public const MAX_ATTEMPTS = 10;

    /** Delays in seconds between status checks of pending issues. */
    public const STATUS_DELAYS = [30, 60, 120, 300, 900, 1800, 3600, 3600, 3600, 3600];

    /** Delays in seconds before the next attempt after a temporary error, by number of attempts made. */
    public const RETRY_DELAYS = [60, 120, 300, 600, 1800, 3600, 7200, 14400, 28800];

    /** Seconds after which an issued certificate is checked again. */
    public const VERIFY_INTERVAL = 86400;

    /** Checks per run of the verify job if an organisation has no hourly limit. */
    public const VERIFY_UNLIMITED = 100;

    /** Seconds over which issues postponed by the plugin's own limit are spread. */
    public const LIMIT_SPREAD = 3600;

    /** Issues processed per run of the cron job. */
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly IssueRepository $issues,
        private readonly ApiClient $api,
        private readonly Environment $env,
        private readonly FieldMapper $mapper,
        private readonly Clock $clock,
        private readonly Texts $texts = new EnglishTexts()
    ) {
    }

    /**
     * Queue an issue for a user unless one exists.
     */
    public function queue(Instance $instance, int $usr_id, string $source, int $completed_at): ?Issue
    {
        if (!$instance->isConfigured() || $this->issues->findFor($instance->obj_id, $usr_id) !== null) {
            return null;
        }
        if (!$this->env->isEligible($instance, $usr_id)) {
            $this->skip($instance, $usr_id, 'noteligible');
            return null;
        }
        if (trim((string) $this->env->userEmail($usr_id)) === '') {
            $this->skip($instance, $usr_id, 'noemail');
            return null;
        }
        $now = $this->clock->now();
        $issue = new Issue();
        $issue->obj_id = $instance->obj_id;
        $issue->usr_id = $usr_id;
        $issue->org = $instance->org;
        $issue->template_id = $instance->template_id;
        $issue->reference = $this->reference($instance->obj_id, $usr_id);
        $issue->status = Issue::STATUS_QUEUED;
        $issue->source = $source;
        $issue->completed_at = $completed_at > 0 ? $completed_at : $now;
        $issue->next_run = $now;
        $issue->time_created = $now;
        $issue->time_modified = $now;
        if (!$this->issues->insert($issue)) {
            return null;
        }
        $issue->reference = $this->reference($instance->obj_id, $usr_id, $issue->id);
        $this->issues->update($issue);
        return $issue;
    }

    /**
     * Reference stored with the recipient in Virtualbadge.io, unique per installation, object, user and issue.
     */
    public function reference(int $obj_id, int $usr_id, ?int $issue_id = null): string
    {
        $reference = substr(sha1($this->env->siteId()), 0, 12) . ':' . $obj_id . ':' . $usr_id;
        return $issue_id ? $reference . ':' . $issue_id : $reference;
    }

    /**
     * Process all due issues. Called by the cron job.
     *
     * @return int number of processed issues
     */
    public function runDue(int $limit = self::BATCH_SIZE): int
    {
        $count = 0;
        foreach ($this->issues->due($this->clock->now(), $limit) as $issue) {
            try {
                if ($issue->status === Issue::STATUS_PENDING) {
                    $this->checkStatus($issue);
                } else {
                    $this->process($issue);
                }
            } catch (\Throwable $e) {
                // Never let one issue block the others: try it again later, give up after the maximum attempts.
                $this->env->log('error', 'Virtualbadge.io issue ' . $issue->id . ': ' . $e->getMessage());
                try {
                    $issue->attempts++;
                    if ($issue->attempts >= self::MAX_ATTEMPTS) {
                        $this->fail($issue, $e->getMessage());
                    } else {
                        $issue->last_error = mb_substr($e->getMessage(), 0, 1000);
                        $delays = self::RETRY_DELAYS;
                        $issue->next_run = $this->clock->now() + $delays[min($issue->attempts, count($delays)) - 1];
                        $issue->time_modified = $this->clock->now();
                        $this->issues->update($issue);
                    }
                } catch (\Throwable $ignored) {
                    // The database itself failed; the issue stays due and is tried in the next run.
                }
            }
            $count++;
        }
        return $count;
    }

    /**
     * Send an issue to Virtualbadge.io.
     */
    public function process(Issue $issue): void
    {
        if (!in_array($issue->status, [Issue::STATUS_QUEUED, Issue::STATUS_RETRYING], true)) {
            $issue->next_run = null;
            $this->issues->update($issue);
            return;
        }
        $instance = $this->env->instance($issue->obj_id);
        if ($instance === null || $this->env->userEmail($issue->usr_id) === null) {
            $this->fail($issue, $this->texts->get('msg_gone'));
            return;
        }
        if ($issue->remote_id === null && $issue->replace_remote_id === null) {
            // Not yet in Virtualbadge.io: use the current settings of the object.
            $issue->org = $instance->org;
            $issue->template_id = $instance->template_id;
        }

        try {
            $this->api->checkSlot($issue->org);
            $template = $this->findTemplate($issue->org, $issue->template_id);
            if ($template === null) {
                $issue->attempts++;
                $this->fail($issue, $this->texts->get('msg_template_inactive', $instance->template_name));
                return;
            }
            $recipient = $this->recipient($instance, $template, $issue);
            if ($issue->replace_remote_id !== null) {
                try {
                    $result = $this->replace($issue, $recipient);
                } catch (ApiException $e) {
                    if ($e->isTemporary()) {
                        throw $e;
                    }
                    if ($e->status === 404) {
                        $issue->attempts++;
                        $issue->replace_remote_id = null;
                        $this->markDeleted($issue);
                        return;
                    }
                    $this->replaceRejected($issue, $e);
                    return;
                }
            } else {
                $result = $issue->attempts > 0 ? $this->api->find($issue->org, $issue->template_id, $issue->reference) : null;
                $result ??= $this->api->issue($issue->org, $issue->template_id, $recipient, $instance->send_email);
                if ($issue->remote_id !== null && ($result['id'] ?? '') !== $issue->remote_id) {
                    // The earlier recipient failed in Virtualbadge.io; the new one takes its place.
                    $issue->previous_remote_id = $issue->remote_id;
                    $issue->time_issued = null;
                    $issue->email_sent = 0;
                    $issue->time_email_sent = null;
                }
            }
        } catch (ApiException $e) {
            $this->handleError($issue, $e);
            return;
        } catch (FieldValueMissing | MappingInvalid $e) {
            $issue->attempts++;
            $this->fail($issue, $e->getMessage());
            return;
        }

        $issue->attempts++;
        $replaced = $issue->replace_remote_id;
        if ($replaced !== null) {
            $issue->previous_remote_id = $replaced;
            $issue->replace_remote_id = null;
            $issue->time_issued = $this->clock->now();
            $issue->email_sent = 0;
            $issue->time_email_sent = null;
        }
        $this->applyResult($issue, $result, $instance);
        if ($replaced !== null) {
            $this->env->log('info', 'Virtualbadge.io certificate replaced', [
                'issue' => $issue->id, 'old' => $replaced, 'new' => $issue->remote_id,
            ]);
            if ($instance->send_email && $issue->remote_id !== null) {
                try {
                    $this->api->sendEmail($issue->org, $issue->remote_id);
                } catch (ApiException $e) {
                    $issue->last_error = $e->getMessage();
                    $this->issues->update($issue);
                }
            }
        }
    }

    /**
     * Replace the current certificate. After an interrupted attempt the replacement is looked up first,
     * so that a certificate is never replaced twice.
     *
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    protected function replace(Issue $issue, array $recipient): array
    {
        $old_id = (string) $issue->replace_remote_id;
        if ($issue->attempts > 0) {
            $old = $this->api->status($issue->org, $old_id);
            if (($old['email_status'] ?? '') === 'voided') {
                $found = $this->api->findReplacement($issue->org, $issue->template_id, (string) $recipient['email'], $old_id);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return $this->api->replace($issue->org, $old_id, $recipient);
    }

    /**
     * Virtualbadge.io refused to replace the certificate. The current certificate stays valid.
     */
    protected function replaceRejected(Issue $issue, ApiException $e): void
    {
        $unchanged = $e->status === 400 && str_contains($e->getMessage(), 'different value');
        $issue->attempts++;
        $issue->replace_remote_id = null;
        $issue->status = $issue->time_issued === null ? Issue::STATUS_PENDING : Issue::STATUS_ISSUED;
        $issue->last_error = $unchanged
            ? $this->texts->get('msg_unchanged')
            : $this->texts->get('msg_reissue_failed', $e->getMessage());
        $issue->time_modified = $this->clock->now();
        $issue->next_run = null;
        if ($issue->status === Issue::STATUS_PENDING) {
            $this->scheduleCheck($issue, 0);
        }
        $this->issues->update($issue);
    }

    /**
     * Record an API error. Temporary errors are tried again later.
     * A reached limit is no attempt: the issue is sent again when the limit allows it.
     */
    protected function handleError(Issue $issue, ApiException $e): void
    {
        $now = $this->clock->now();
        if ($e->status === 429) {
            $issue->status = Issue::STATUS_RETRYING;
            $issue->last_error = $e->getMessage();
            $issue->next_run = $now + $this->waitSeconds($e);
            $issue->time_modified = $now;
            $this->issues->update($issue);
            return;
        }
        $issue->attempts++;
        if ($e->isTemporary() && $issue->attempts < self::MAX_ATTEMPTS) {
            $issue->status = Issue::STATUS_RETRYING;
            $issue->last_error = $e->getMessage();
            $delays = self::RETRY_DELAYS;
            $issue->next_run = $now + $delays[min($issue->attempts, count($delays)) - 1];
            $issue->time_modified = $now;
            $this->issues->update($issue);
            return;
        }
        $this->fail($issue, $e->getMessage());
    }

    /**
     * Seconds to wait after a reached limit. Issues that hit the limit together get different times:
     * at the plugin's own limit spread over one more hour, at the API's limit over the wait time.
     */
    public function waitSeconds(ApiException $e): int
    {
        $wait = max(1, $e->retryAfter ?? 60);
        return $wait + $this->clock->random(0, $e->ownLimit ? self::LIMIT_SPREAD : $wait);
    }

    /**
     * A template among the active templates of an organisation.
     *
     * @return array{id: string, name: string, type: string, fields: string[], slots: array<string, string>}|null
     */
    public function findTemplate(string $org, string $template_id): ?array
    {
        foreach ($this->api->templates($org) as $template) {
            if ($template['id'] === $template_id) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Problems of the template of an object: no longer active or fields without mapping.
     * Null if there is no problem or the API cannot be reached.
     *
     * @return array{unavailable?: true, missing?: string[]}|null
     */
    public function templateProblems(Instance $instance): ?array
    {
        if (!$instance->isConfigured()) {
            return null;
        }
        try {
            $template = $this->findTemplate($instance->org, $instance->template_id);
        } catch (ApiException $e) {
            return null;
        }
        if ($template === null) {
            return ['unavailable' => true];
        }
        $missing = FieldMapper::unmapped($template['fields'], $instance->field_map);
        return $missing !== [] ? ['missing' => $missing] : null;
    }

    /**
     * Template fields whose mapping uses a source that is not available for the object, e.g. a custom metadata
     * field whose record is no longer active for the course, directly or as placeholder in fixed text.
     * Needs no API call.
     *
     * @return string[] template field names
     */
    public function mappingProblems(Instance $instance): array
    {
        $valid = array_flip($this->env->validSources($instance));
        $problems = [];
        foreach ($instance->field_map as $field => $entry) {
            $entry = FieldMapper::normalize($entry);
            if ($entry['source'] !== FieldMapper::TEXT) {
                $available = isset($valid[$entry['source']]);
            } else {
                $available = true;
                foreach (FieldMapper::courseFieldPlaceholders($entry['value']) as $id) {
                    $available = $available && isset($valid['coursefield:' . $id]);
                }
            }
            if (!$available) {
                $problems[] = (string) $field;
            }
        }
        return $problems;
    }

    /**
     * Refresh a pending issue.
     */
    public function checkStatus(Issue $issue): void
    {
        if ($issue->status !== Issue::STATUS_PENDING || $issue->remote_id === null) {
            $issue->next_run = null;
            $this->issues->update($issue);
            return;
        }
        $check = $issue->check_no;
        try {
            $result = $this->api->status($issue->org, $issue->remote_id);
        } catch (ApiException $e) {
            if ($e->status === 404) {
                $this->markDeleted($issue);
                return;
            }
            $issue->last_error = $e->getMessage();
            $issue->time_modified = $this->clock->now();
            if ($e->status === 429) {
                $this->scheduleCheck($issue, $check, $this->waitSeconds($e));
            } else {
                $this->scheduleCheck($issue, $check + 1);
            }
            $this->issues->update($issue);
            return;
        }
        $instance = $this->env->instance($issue->obj_id);
        $this->applyResult($issue, $result, $instance);
        if ($issue->status === Issue::STATUS_PENDING) {
            $this->scheduleCheck($issue, $check + 1);
            $this->issues->update($issue);
        }
    }

    /**
     * Schedule a status check of a pending issue. After the last check the issue stays pending and the verify job
     * checks it once per interval.
     */
    protected function scheduleCheck(Issue $issue, int $check, ?int $delay = null): void
    {
        $issue->check_no = $check;
        $issue->next_run = $check < count(self::STATUS_DELAYS)
            ? $this->clock->now() + ($delay ?? self::STATUS_DELAYS[$check])
            : null;
        if ($issue->next_run === null) {
            // The verify job takes over, the first time one interval after this check.
            $issue->time_checked = $this->clock->now();
        }
    }

    /**
     * Check issued certificates and pending issues after their last status check against Virtualbadge.io,
     * the longest unchecked first.
     * Each organisation gets at most a tenth of its hourly limit per run.
     *
     * @param Settings $settings for the hourly limit of each organisation
     * @return int number of checked issues
     */
    public function verifyIssued(Settings $settings): int
    {
        $checked = 0;
        foreach ($this->issues->issuedOrgs() as $org) {
            if (!$this->api->isConfigured($org)) {
                continue;
            }
            $limit = $settings->rateLimit($org);
            $batch = $limit > 0 ? max(1, intdiv($limit, 10)) : self::VERIFY_UNLIMITED;
            foreach ($this->issues->toVerify($org, $this->clock->now() - self::VERIFY_INTERVAL, $batch) as $issue) {
                try {
                    $this->verify($issue);
                } catch (ApiException $e) {
                    if ($e->isTemporary()) {
                        break;
                    }
                    $issue->time_checked = $this->clock->now();
                    $this->issues->update($issue);
                } catch (\Throwable $e) {
                    // Never let one issue block the verification of the others: check it again in the next interval.
                    $this->env->log('error', 'Virtualbadge.io verify issue ' . $issue->id . ': ' . $e->getMessage());
                    try {
                        $issue->time_checked = $this->clock->now();
                        $this->issues->update($issue);
                    } catch (\Throwable $ignored) {
                        // The database itself failed; tried again in the next run.
                    }
                }
                $checked++;
            }
        }
        return $checked;
    }

    /**
     * Check one issued certificate. A certificate replaced in Virtualbadge.io is followed to its replacement.
     */
    protected function verify(Issue $issue): void
    {
        try {
            $result = $this->api->status($issue->org, (string) $issue->remote_id);
        } catch (ApiException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
            $this->markDeleted($issue);
            return;
        }
        $issue->time_checked = $this->clock->now();
        $instance = $this->env->instance($issue->obj_id);
        if ($instance === null || ($issue->status === Issue::STATUS_PENDING && $result['status'] === 'pending')) {
            // A stalled pending issue that is still pending: checked again after the next interval.
            $this->issues->update($issue);
            return;
        }
        if (($result['email_status'] ?? '') === 'voided') {
            $email = $this->env->userEmail($issue->usr_id);
            $found = $email ? $this->api->findReplacement($issue->org, $issue->template_id, $email, (string) $issue->remote_id) : null;
            if ($found !== null) {
                $issue->previous_remote_id = $issue->remote_id;
                $result = $found;
            }
        }
        $this->applyResult($issue, $result, $instance);
        if ($issue->status === Issue::STATUS_PENDING) {
            $this->scheduleCheck($issue, 0);
            $this->issues->update($issue);
        }
    }

    protected function markDeleted(Issue $issue): void
    {
        $issue->status = Issue::STATUS_DELETED;
        $issue->last_error = null;
        $issue->next_run = null;
        $issue->time_checked = $this->clock->now();
        $issue->time_modified = $this->clock->now();
        $this->issues->update($issue);
        $instance = $this->env->instance($issue->obj_id);
        if ($instance !== null) {
            $this->env->issueChanged($instance, $issue);
        }
    }

    /**
     * Store an API result.
     *
     * @param array<string, mixed> $result
     */
    public function applyResult(Issue $issue, array $result, ?Instance $instance): void
    {
        $map = [
            'issued' => Issue::STATUS_ISSUED,
            'pending' => Issue::STATUS_PENDING,
            'revoked' => Issue::STATUS_REVOKED,
            'failed' => Issue::STATUS_FAILED,
        ];
        $now = $this->clock->now();
        $issue->status = $map[$result['status'] ?? ''] ?? Issue::STATUS_PENDING;
        if (!empty($result['id'])) {
            $issue->remote_id = (string) $result['id'];
        }
        $issue->certificate_url = $result['certificate_url'] ?? $issue->certificate_url;
        $issue->validation_url = $result['validation_url'] ?? $issue->validation_url;
        $issue->last_error = null;
        $issue->next_run = null;
        $issue->check_no = 0;
        if ($issue->status === Issue::STATUS_PENDING) {
            $this->scheduleCheck($issue, 0);
        } elseif ($issue->status === Issue::STATUS_FAILED) {
            $issue->last_error = $this->texts->get('msg_generation_failed');
            $this->env->log('warning', 'Virtualbadge.io generation failed', ['issue' => $issue->id, 'remote' => $issue->remote_id]);
        }
        $first_time = $issue->time_issued === null && $issue->remote_id !== null;
        if ($first_time) {
            $issue->time_issued = $now;
        }
        $issue->time_modified = $now;
        $this->issues->update($issue);
        if ($first_time) {
            $this->env->log('info', 'Virtualbadge.io certificate issued', ['issue' => $issue->id, 'remote' => $issue->remote_id]);
        }
        if ($instance !== null) {
            $this->env->issueChanged($instance, $issue);
        }
    }

    protected function fail(Issue $issue, string $error): void
    {
        $issue->status = Issue::STATUS_FAILED;
        $issue->last_error = mb_substr($error, 0, 1000);
        $issue->next_run = null;
        $issue->time_modified = $this->clock->now();
        $this->issues->update($issue);
        $this->env->log('warning', 'Virtualbadge.io issue failed', ['issue' => $issue->id, 'error' => $issue->last_error]);
    }

    protected function skip(Instance $instance, int $usr_id, string $reason): void
    {
        $this->env->log('info', 'Virtualbadge.io certificate not issued', [
            'obj_id' => $instance->obj_id, 'usr_id' => $usr_id, 'reason' => $reason,
        ]);
    }

    /**
     * Recipient for the API request.
     *
     * @param array{id: string, name: string, type: string, fields: string[], slots: array<string, string>} $template
     * @return array<string, mixed>
     * @throws MappingInvalid if the template has fields without mapping
     * @throws FieldValueMissing if a mapped value is empty
     */
    public function recipient(Instance $instance, array $template, Issue $issue): array
    {
        $missing = FieldMapper::unmapped($template['fields'], $instance->field_map);
        if ($missing !== []) {
            throw new MappingInvalid(
                $this->texts->get('msg_fields_unmapped', $template['name'], implode(', ', $missing)),
                $missing
            );
        }
        $map = array_intersect_key($instance->field_map, array_flip($template['fields']));
        $values = $this->mapper->resolve(
            $map,
            $this->env->sourceValues($instance, $issue->usr_id, $issue),
            $this->env->validSources($instance),
            fn(string $source): string => $this->env->sourceLabel($instance, $source)
        );
        $issue_date = (new \DateTimeImmutable('@' . $issue->completed_at))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
        return [
            'email' => (string) $this->env->userEmail($issue->usr_id),
            'full_name' => FieldMapper::collapse($this->env->userFullName($issue->usr_id)),
            'issue_date' => $issue_date,
            'dynamic_fields' => (object) ($values + $template['slots']),
            'metadata' => [ApiClient::METADATA_KEY => $issue->reference],
        ];
    }

    /**
     * Queue issues for all users who already meet the condition.
     *
     * @return int number of queued issues
     */
    public function issueRetroactively(Instance $instance): int
    {
        $count = 0;
        foreach ($this->pendingUsers($instance) as $usr_id => $time) {
            if ($this->queue($instance, (int) $usr_id, $instance->releaseSource(), (int) $time) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Users who meet the condition but have no issue yet.
     *
     * @return array<int, int> user id => time the condition was met
     */
    public function pendingUsers(Instance $instance): array
    {
        if ($instance->trigger === Instance::TRIGGER_MANUAL) {
            return [];
        }
        $with_issue = array_flip($this->issues->usersWithIssue($instance->obj_id));
        return array_diff_key($this->env->conditionMet($instance), $with_issue);
    }

    /**
     * Issue to selected users: with trigger manual to anyone, otherwise to those who meet the condition.
     *
     * @param int[] $usr_ids
     * @return int number of queued issues
     */
    public function releaseSelected(Instance $instance, array $usr_ids): int
    {
        $manual = $instance->trigger === Instance::TRIGGER_MANUAL;
        $pending = $manual ? [] : $this->pendingUsers($instance);
        $count = 0;
        foreach (array_unique(array_map('intval', $usr_ids)) as $usr_id) {
            if ($manual) {
                $issue = $this->queue($instance, $usr_id, Issue::SOURCE_MANUAL, $this->clock->now());
            } else {
                $issue = isset($pending[$usr_id])
                    ? $this->queue($instance, $usr_id, $instance->releaseSource(), (int) $pending[$usr_id])
                    : null;
            }
            if ($issue !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Try a failed issue again, or issue a new certificate for one deleted in Virtualbadge.io.
     */
    public function retry(Issue $issue): bool
    {
        if (!in_array($issue->status, [Issue::STATUS_FAILED, Issue::STATUS_DELETED], true)) {
            return false;
        }
        if ($issue->status === Issue::STATUS_DELETED) {
            $issue->remote_id = null;
            $issue->previous_remote_id = null;
            $issue->certificate_url = null;
            $issue->validation_url = null;
            $issue->time_issued = null;
            $issue->time_email_sent = null;
            $issue->time_checked = null;
            $issue->email_sent = 0;
        }
        // A failed issue may have been created in Virtualbadge.io by a request whose answer was lost.
        // One counted attempt makes the next run look it up before issuing. A recipient whose generation failed
        // is not taken (ApiClient::find()), so a new one is created.
        $issue->attempts = $issue->status === Issue::STATUS_FAILED ? 1 : 0;
        $issue->status = Issue::STATUS_QUEUED;
        $issue->last_error = null;
        $issue->next_run = $this->clock->now();
        $issue->time_modified = $this->clock->now();
        $this->issues->update($issue);
        return true;
    }

    /**
     * Queue a new certificate that replaces the current one. The current one becomes void in Virtualbadge.io.
     */
    public function reissue(Issue $issue): bool
    {
        if (!$issue->hasCertificate()) {
            return false;
        }
        $issue->replace_remote_id = $issue->remote_id;
        $issue->status = Issue::STATUS_QUEUED;
        $issue->attempts = 0;
        $issue->last_error = null;
        $issue->next_run = $this->clock->now();
        $issue->time_modified = $this->clock->now();
        $this->issues->update($issue);
        return true;
    }

    /**
     * Ask Virtualbadge.io to send the email with the certificate link again.
     *
     * @return bool false if the certificate cannot be emailed
     * @throws ApiException on other errors than "not found"
     */
    public function resendEmail(Issue $issue): bool
    {
        if (!$issue->hasCertificate() || $issue->email_sent >= self::MAX_EMAILS) {
            return false;
        }
        try {
            $this->api->sendEmail($issue->org, (string) $issue->remote_id);
        } catch (ApiException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
            $this->markDeleted($issue);
            return false;
        }
        $issue->email_sent++;
        $issue->time_email_sent = $this->clock->now();
        $issue->time_modified = $this->clock->now();
        $this->issues->update($issue);
        return true;
    }
}
