<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use Virtualbadge\Ilias\DefaultPermissions;
use Virtualbadge\Ilias\EventHandler;
use Virtualbadge\Ilias\Services;
use Virtualbadge\Ilias\Tables;

/**
 * Virtualbadge.io repository object plugin.
 *
 * Besides the repository object, the plugin
 * - listens to learning progress and user events (plugin.xml, handleEvent()),
 * - gives the course and group roles default permissions on its first activation (DefaultPermissions),
 * - provides its cron jobs itself (ilCronJobProvider): ILIAS asks every active plugin for them.
 */
class ilVirtualbadgePlugin extends ilRepositoryObjectPlugin implements ilCronJobProvider
{
    public const ID = 'xvbd';
    public const NAME = 'Virtualbadge';

    public function getPluginName(): string
    {
        return self::NAME;
    }

    /**
     * The object is placed in a course or inside a course (group, folder).
     *
     * @return string[]
     */
    public function getParentTypes(): array
    {
        return ['crs', 'grp', 'fold'];
    }

    public function allowCopy(): bool
    {
        return true;
    }

    /**
     * Activation from the administration or the setup CLI. ILIAS creates the object type in
     * parent::activate(); the default permissions are added once afterwards.
     */
    public function activate(): bool
    {
        if (!parent::activate()) {
            return false;
        }
        $this->applyDefaultPermissionsOnce();
        return true;
    }

    private function applyDefaultPermissionsOnce(): void
    {
        // Database only: the setup CLI activates plugins without the full ILIAS environment.
        $key = [
            'module' => ['text', Tables::SETTINGS_MODULE],
            'keyword' => ['text', DefaultPermissions::APPLIED_SETTING],
        ];
        $res = $this->db->queryF(
            'SELECT value FROM settings WHERE module = %s AND keyword = %s',
            ['text', 'text'],
            [Tables::SETTINGS_MODULE, DefaultPermissions::APPLIED_SETTING]
        );
        if ($this->db->fetchAssoc($res)) {
            return;
        }
        (new DefaultPermissions($this->db, self::ID))->apply();
        $this->db->replace('settings', $key, ['value' => ['clob', '1']]);
    }

    /**
     * Called by ilAppEventHandler for the events listed in plugin.xml.
     *
     * @param array<string, mixed> $a_parameter
     */
    public function handleEvent(string $a_component, string $a_event, array $a_parameter): void
    {
        if (!$this->isActive()) {
            return;
        }
        try {
            (new EventHandler(Services::get($this)))->handle($a_component, $a_event, $a_parameter);
        } catch (Throwable $e) {
            // An error here must never break the learning progress update of ILIAS.
            global $DIC;
            $DIC->logger()->root()->error('Virtualbadge.io event handling failed: ' . $e->getMessage());
        }
    }

    /**
     * @return ilCronJob[]
     */
    public function getCronJobInstances(): array
    {
        return [new ilVirtualbadgeProcessJob($this), new ilVirtualbadgeVerifyJob($this)];
    }

    public function getCronJobInstance(string $jobId): ilCronJob
    {
        foreach ($this->getCronJobInstances() as $job) {
            if ($job->getId() === $jobId) {
                return $job;
            }
        }
        throw new OutOfBoundsException('Unknown cron job ' . $jobId);
    }

    protected function uninstallCustom(): void
    {
        foreach (Tables::ALL as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->dropTable($table, false);
            }
            if ($this->db->sequenceExists($table)) {
                $this->db->dropSequence($table);
            }
        }
        // Database only: the setup CLI may uninstall without a client context.
        $this->db->manipulateF('DELETE FROM settings WHERE module = %s', [ilDBConstants::T_TEXT], [Tables::SETTINGS_MODULE]);
        if (defined('CLIENT_DATA_DIR')) {
            $key = Services::keyFile();
            if (is_file($key)) {
                @unlink($key);
            }
        }
    }
}
