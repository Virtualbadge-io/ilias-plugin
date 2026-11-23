<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use ILIAS\Data\URI;
use ILIAS\UI\Component\Input\Container\Form\Standard as StandardForm;
use ILIAS\UI\URLBuilder;
use Virtualbadge\Core\Api\ApiException;
use Virtualbadge\Core\Fields\FieldMapper;
use Virtualbadge\Core\Issue\Instance;
use Virtualbadge\Core\Issue\Issue;
use Virtualbadge\Ilias\Services;
use Virtualbadge\Ilias\UiCompat;

/**
 * Views of a Virtualbadge.io object:
 * - learners (read): link to their certificate and validation page, or the condition
 * - tutors (write): status list of all participants with release, retry, reissue and resend email
 * - settings (write): organisation, template, field mapping, trigger
 *
 * @ilCtrl_isCalledBy ilObjVirtualbadgeGUI: ilRepositoryGUI, ilAdministrationGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls      ilObjVirtualbadgeGUI: ilPermissionGUI, ilInfoScreenGUI, ilObjectCopyGUI, ilCommonActionDispatcherGUI, ilLearningProgressGUI
 *
 * Commands that change data via a link carry the ILIAS CSRF token (ilCtrlSecurityInterface). Texts from
 * Virtualbadge.io, users and the configuration are escaped: the UI components used print them as HTML.
 */
class ilObjVirtualbadgeGUI extends ilObjectPluginGUI implements ilCtrlSecurityInterface
{
    public const CMD_VIEW = 'view';
    public const CMD_SETTINGS = 'settings';
    private const CMD_SAVE_SETTINGS = 'saveSettings';
    private const CMD_SAVE_FIELDS = 'saveFields';
    private const CMD_REFRESH = 'refreshTemplates';
    private const CMD_TABLE_ACTION = 'tableAction';
    private const CMD_REISSUE = 'reissueConfirmed';
    private const CMD_RELEASE_ALL = 'releaseAll';

    private const TAB_VIEW = 'view';
    private const TAB_SETTINGS = 'settings';

    private const READ_COMMANDS = [self::CMD_VIEW];
    private const WRITE_COMMANDS = [self::CMD_SETTINGS, self::CMD_SAVE_SETTINGS, self::CMD_SAVE_FIELDS, self::CMD_REFRESH,
        self::CMD_TABLE_ACTION, self::CMD_REISSUE, self::CMD_RELEASE_ALL];

    /** Users a tutor action queues at most in one request. */
    private const RELEASE_MAX = 500;

    final public function getType(): string
    {
        return ilVirtualbadgePlugin::ID;
    }

    /**
     * GET commands that change data: ILIAS adds and checks the CSRF token for them.
     *
     * @return string[]
     */
    public function getUnsafeGetCommands(): array
    {
        return [self::CMD_TABLE_ACTION, self::CMD_REISSUE, self::CMD_RELEASE_ALL, self::CMD_REFRESH];
    }

    /**
     * @return string[]
     */
    public function getSafePostCommands(): array
    {
        return [];
    }

    /**
     * Curly braces as HTML entities: the ILIAS template engine removes "{word}" from rendered output as an unset
     * template variable, so placeholders like {firstname} in help texts would disappear.
     */
    private static function braces(string $text): string
    {
        return str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function getAfterCreationCmd(): string
    {
        return self::CMD_SETTINGS;
    }

    public function getStandardCmd(): string
    {
        return self::CMD_VIEW;
    }

    public function performCommand(string $cmd): void
    {
        if (in_array($cmd, self::WRITE_COMMANDS, true)) {
            $this->checkPermission('write');
        } elseif (in_array($cmd, self::READ_COMMANDS, true)) {
            $this->checkPermission('read');
        } else {
            $this->checkPermission('read');
            $cmd = self::CMD_VIEW;
        }
        match ($cmd) {
            self::CMD_SETTINGS => $this->showSettings(),
            self::CMD_SAVE_SETTINGS => $this->saveSettings(),
            self::CMD_SAVE_FIELDS => $this->saveFields(),
            self::CMD_REFRESH => $this->refreshTemplates(),
            self::CMD_TABLE_ACTION => $this->tableAction(),
            self::CMD_REISSUE => $this->reissueConfirmed(),
            self::CMD_RELEASE_ALL => $this->releaseAll(),
            default => $this->showView(),
        };
    }

    protected function setTabs(): void
    {
        if ($this->access->checkAccess('read', '', $this->object->getRefId())) {
            $this->tabs->addTab(self::TAB_VIEW, $this->txt('tab_view'), $this->ctrl->getLinkTarget($this, self::CMD_VIEW));
        }
        $this->addInfoTab();
        if ($this->access->checkAccess('write', '', $this->object->getRefId())) {
            $this->tabs->addTab(self::TAB_SETTINGS, $this->txt('settings'), $this->ctrl->getLinkTarget($this, self::CMD_SETTINGS));
        }
        if (ilLearningProgressAccess::checkAccess($this->object->getRefId())) {
            $this->tabs->addTab(
                'learning_progress',
                $this->lng->txt('learning_progress'),
                $this->ctrl->getLinkTargetByClass(ilLearningProgressGUI::class, '')
            );
        }
        $this->addPermissionTab();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function services(): Services
    {
        return Services::get($this->getPlugin());
    }

    private function vbObject(): ilObjVirtualbadge
    {
        /** @var ilObjVirtualbadge $object */
        $object = $this->object;
        return $object;
    }

    private function instance(): Instance
    {
        return $this->vbObject()->getInstance();
    }

    private function ui(): \ILIAS\UI\Factory
    {
        global $DIC;
        return $DIC->ui()->factory();
    }

    private function render(array|\ILIAS\UI\Component\Component $components): string
    {
        global $DIC;
        return $DIC->ui()->renderer()->render($components);
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        global $DIC;
        return $DIC->http()->request();
    }

    private function message(string $type, string $text, bool $keep = false): void
    {
        $this->tpl->setOnScreenMessage($type, $text, $keep);
    }

    private function formatDate(?int $time): string
    {
        if (!$time) {
            return '';
        }
        return ilDatePresentation::formatDate(new ilDateTime($time, IL_CAL_UNIX));
    }

    // ------------------------------------------------------------------
    // View
    // ------------------------------------------------------------------

    private function showView(): void
    {
        $this->tabs->activateTab(self::TAB_VIEW);
        $instance = $this->instance();
        $components = [];
        $can_write = $this->access->checkAccess('write', '', $this->object->getRefId());

        if ($instance->course_obj_id <= 0) {
            $components[] = $this->ui()->messageBox()->info($this->txt('not_in_course'));
        }
        if (!$this->services()->api()->isConfigured($instance->org !== '' ? $instance->org : 'main')) {
            $components[] = $this->ui()->messageBox()->info($this->txt('not_configured'));
        } elseif (!$instance->isConfigured()) {
            $components[] = $this->ui()->messageBox()->info($this->txt($can_write ? 'no_template_tutor' : 'no_template'));
        }

        $components = array_merge($components, $this->learnerView($instance));
        if ($can_write && $instance->isConfigured()) {
            $components = array_merge($components, $this->tutorView($instance));
        }
        $this->tpl->setContent($this->render($components));
    }

    /**
     * @return \ILIAS\UI\Component\Component[]
     */
    private function learnerView(Instance $instance): array
    {
        $usr_id = $this->user->getId();
        $issue = $this->services()->issues()->findFor($instance->obj_id, $usr_id);
        if ($issue === null && (!$instance->isConfigured() || $instance->course_obj_id <= 0
            || !ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->isMember($usr_id))) {
            return [];
        }
        $f = $this->ui();
        $state = self::learnerState($issue);
        $is_badge = $instance->template_type === 'badge';

        $description = match ($state) {
            self::STATE_ISSUED => $this->txt($is_badge ? 'learner_issued_badge' : 'learner_issued'),
            self::STATE_PROGRESS => $this->txt('in_progress_learner'),
            self::STATE_PROBLEM => $this->txt('learner_problem_' . $issue->status),
            default => $this->conditionText($instance),
        };
        $properties = [$this->txt('col_status') => $this->txt('learner_state_' . $state)];
        if ($instance->course_obj_id > 0) {
            $properties[$this->txt('prop_course')] = self::esc((string) ilObject::_lookupTitle($instance->course_obj_id));
        }
        if ($state === self::STATE_ISSUED && $issue->time_issued) {
            $properties[$this->txt('col_issued')] = $this->formatDate($issue->time_issued);
        }
        if ($issue?->remote_id !== null && in_array($state, [self::STATE_ISSUED, self::STATE_PROBLEM], true)) {
            $properties[$this->txt('prop_certificate_id')] = self::esc($issue->remote_id);
        }

        $item = $f->item()->standard(self::esc($instance->template_name !== '' ? $instance->template_name : $this->object->getTitle()))
            ->withLeadIcon($this->stateIcon($state, 'large'))
            ->withColor((new \ILIAS\Data\Factory())->color(self::STATE_COLORS[$state]))
            ->withDescription($description)
            ->withProperties($properties);

        $content = [$item];
        if ($state === self::STATE_ISSUED && $issue->certificate_url !== null) {
            $content[] = $this->newTabButton($f->button()->primary($this->txt($is_badge ? 'open_badge' : 'open_certificate'), ''), $issue->certificate_url);
            if ($issue->validation_url !== null) {
                $content[] = $this->newTabButton($f->button()->standard($this->txt('open_validation'), ''), $issue->validation_url);
            }
        }
        return [$f->panel()->standard($this->txt($is_badge ? 'your_badge' : 'your_certificate'), $content)];
    }

    /**
     * The certificate and validation pages are on Virtualbadge.io: open them in a new tab so the
     * learner stays in ILIAS. The button gets no own action, otherwise ILIAS would navigate itself.
     */
    private function newTabButton(\ILIAS\UI\Component\Button\Button $button, string $url): \ILIAS\UI\Component\Button\Button
    {
        $js_url = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return $button->withAdditionalOnLoadCode(
            static fn(string $id): string => "document.getElementById('$id').addEventListener('click', function (event) {"
                . " event.preventDefault(); window.open($js_url, '_blank', 'noopener'); });"
        );
    }

    private const STATE_NONE = 'none';
    private const STATE_PROGRESS = 'progress';
    private const STATE_ISSUED = 'issued';
    private const STATE_PROBLEM = 'problem';

    /** Colour bar of the learner's item per state; readable on light and dark skins. */
    private const STATE_COLORS = [
        self::STATE_NONE => '#8a8f98',
        self::STATE_PROGRESS => '#b26a00',
        self::STATE_ISSUED => '#2e7d32',
        self::STATE_PROBLEM => '#c62828',
    ];

    private const STATE_ICONS = [
        self::STATE_NONE => 'status_none.svg',
        self::STATE_PROGRESS => 'status_progress.svg',
        self::STATE_ISSUED => 'status_ok.svg',
        self::STATE_PROBLEM => 'status_problem.svg',
    ];

    /**
     * Four states learners and tutors see instead of the internal status.
     */
    private static function learnerState(?Issue $issue): string
    {
        return match ($issue?->status) {
            null => self::STATE_NONE,
            Issue::STATUS_ISSUED => $issue->certificate_url !== null ? self::STATE_ISSUED : self::STATE_PROGRESS,
            Issue::STATUS_QUEUED, Issue::STATUS_PENDING, Issue::STATUS_RETRYING => self::STATE_PROGRESS,
            default => self::STATE_PROBLEM,
        };
    }

    private function stateIcon(string $state, string $size = 'small'): \ILIAS\UI\Component\Symbol\Icon\Icon
    {
        return $this->ui()->symbol()->icon()->custom(
            ilRepositoryObjectPlugin::_getImagePath(
                ilComponentInfo::TYPES[0],
                'Repository',
                'robj',
                ilVirtualbadgePlugin::NAME,
                self::STATE_ICONS[$state]
            ),
            $this->txt('learner_state_' . $state),
            $size
        );
    }

    private function conditionText(Instance $instance): string
    {
        return match ($instance->trigger) {
            Instance::TRIGGER_APPROVAL => $this->txt('condition_approval'),
            Instance::TRIGGER_MANUAL => $this->txt('condition_manual'),
            Instance::TRIGGER_OBJECT => $instance->min_percent !== null
                ? sprintf($this->txt('condition_object_percent'), $this->objectTitle($instance->trigger_ref_id), $this->formatPercent($instance->min_percent))
                : sprintf($this->txt('condition_object'), $this->objectTitle($instance->trigger_ref_id)),
            default => $this->txt('condition_completion'),
        };
    }

    private function objectTitle(int $ref_id): string
    {
        return $ref_id > 0 ? self::esc((string) ilObject::_lookupTitle(ilObject::_lookupObjId($ref_id))) : '?';
    }

    private function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /**
     * @return \ILIAS\UI\Component\Component[]
     */
    private function tutorView(Instance $instance): array
    {
        $f = $this->ui();
        $issuer = $this->services()->issuer();
        $components = [];

        $problems = $issuer->templateProblems($instance);
        if (isset($problems['unavailable'])) {
            $components[] = $f->messageBox()->failure(sprintf($this->txt('template_unavailable'), self::esc($instance->template_name)));
        } elseif (isset($problems['missing'])) {
            $components[] = $f->messageBox()->failure(sprintf(
                $this->txt('template_changed'),
                self::esc($instance->template_name),
                self::esc(implode(', ', $problems['missing']))
            ))
                ->withLinks([$f->link()->standard($this->txt('settings'), $this->ctrl->getLinkTarget($this, self::CMD_SETTINGS))]);
        }
        foreach ($this->mappingWarning($instance) as $warning) {
            $components[] = $warning->withLinks([
                $f->link()->standard($this->txt('settings'), $this->ctrl->getLinkTarget($this, self::CMD_SETTINGS)),
            ]);
        }

        $pending = $issuer->pendingUsers($instance);
        $components[] = $this->overview($instance, $pending);
        if ($pending !== []) {
            $info = $instance->trigger === Instance::TRIGGER_APPROVAL ? 'release_all_info' : 'retroactive_info';
            $label = $instance->trigger === Instance::TRIGGER_APPROVAL ? 'release_all' : 'retroactive';
            $components[] = $f->messageBox()->info(sprintf($this->txt($info), count($pending)))
                ->withButtons([$f->button()->standard(
                    sprintf($this->txt($label), count($pending)),
                    $this->ctrl->getLinkTarget($this, self::CMD_RELEASE_ALL)
                )]);
        }

        $components[] = $this->participantTable($instance, $pending);
        return $components;
    }

    /**
     * Summary for tutors: share of members with a certificate and the number per state.
     *
     * @param array<int, int> $pending users who meet the condition without an issue
     */
    private function overview(Instance $instance, array $pending): \ILIAS\UI\Component\Panel\Standard
    {
        $f = $this->ui();
        $members = $instance->course_obj_id > 0
            ? array_map('intval', ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->getMembers())
            : [];
        $counts = array_fill_keys([self::STATE_ISSUED, self::STATE_PROGRESS, self::STATE_PROBLEM], 0);
        $with_issue = [];
        foreach ($this->services()->issues()->forObject($instance->obj_id) as $issue) {
            $counts[self::learnerState($issue)]++;
            $with_issue[$issue->usr_id] = true;
        }
        $without = count(array_diff_key(array_flip($members), $with_issue));
        $total = max(count($members), $counts[self::STATE_ISSUED], 1);

        // Flat bar with the markup and classes of the ILIAS progress bar. The UI component
        // progress()->bar() only shows values loaded asynchronously, not a fixed value.
        $label = sprintf($this->txt('overview_issued_of'), $counts[self::STATE_ISSUED], count($members));
        $bar = UiCompat::legacy($f, '<div class="c-progress c-progress-bar"><div class="c-progress-bar__label">'
            . htmlspecialchars($label) . '</div><progress class="c-progress-bar__progress" value="'
            . $counts[self::STATE_ISSUED] . '" max="' . $total . '" aria-label="' . htmlspecialchars($label) . '"></progress></div>');

        $values = [
            $this->txt('learner_state_' . self::STATE_ISSUED) => (string) $counts[self::STATE_ISSUED],
            $this->txt('learner_state_' . self::STATE_PROGRESS) => (string) $counts[self::STATE_PROGRESS],
            $this->txt('overview_problems') => (string) $counts[self::STATE_PROBLEM],
            $this->txt($instance->trigger === Instance::TRIGGER_APPROVAL ? 'overview_waiting_release' : 'overview_condition_met')
                => (string) count($pending),
            $this->txt('overview_not_yet') => (string) max(0, $without - count($pending)),
        ];
        return $f->panel()->standard($this->txt('overview'), [
            $bar,
            $f->listing()->characteristicValue()->text($values),
        ]);
    }

    /**
     * @param array<int, int> $pending users who meet the condition without an issue
     */
    private function participantTable(Instance $instance, array $pending): \ILIAS\UI\Component\Table\Data
    {
        $f = $this->ui();
        $issues = [];
        foreach ($this->services()->issues()->forObject($instance->obj_id) as $issue) {
            $issues[$issue->usr_id] = $issue;
        }
        $members = $instance->course_obj_id > 0
            ? array_map('intval', ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->getMembers())
            : [];
        $usr_ids = array_values(array_unique(array_merge($members, array_keys($issues))));

        $rows = [];
        $manual = $instance->trigger === Instance::TRIGGER_MANUAL;
        foreach ($usr_ids as $usr_id) {
            $issue = $issues[$usr_id] ?? null;
            $name = ilObjUser::_lookupName($usr_id);
            $met = $pending[$usr_id] ?? null;
            $rows[] = [
                'id' => $usr_id,
                'state' => $this->stateIcon(self::learnerState($issue)),
                'name' => self::esc(trim(($name['lastname'] ?? '') . ', ' . ($name['firstname'] ?? ''), ', ') ?: (string) $usr_id),
                'status' => $this->txt('status_' . ($issue?->status ?? 'none')),
                'condition' => $met !== null ? sprintf($this->txt('condition_met_on'), $this->formatDate($met)) : '',
                'issued' => $this->formatDate($issue?->time_issued),
                'error' => self::esc((string) ($issue?->last_error ?? '')),
                'actions' => [
                    'release' => $issue === null && ($manual ? in_array($usr_id, $members, true) : $met !== null),
                    'retry' => $issue !== null && in_array($issue->status, [Issue::STATUS_FAILED, Issue::STATUS_DELETED], true),
                    'reissue' => $issue !== null && $issue->hasCertificate(),
                    'resend' => $issue !== null && $issue->hasCertificate() && $issue->email_sent < \Virtualbadge\Core\Issue\Issuer::MAX_EMAILS,
                ],
            ];
        }

        $columns = [
            'state' => $f->table()->column()->statusIcon($this->txt('col_status'))->withIsSortable(false),
            'name' => $f->table()->column()->text($this->txt('col_name')),
            'status' => $f->table()->column()->text($this->txt('col_status_detail')),
            'condition' => $f->table()->column()->text($this->txt('col_condition')),
            'issued' => $f->table()->column()->text($this->txt('col_issued')),
            'error' => $f->table()->column()->text($this->txt('col_error'))->withIsSortable(false),
        ];

        $uri = new URI(ILIAS_HTTP_PATH . '/' . $this->ctrl->getLinkTarget($this, self::CMD_TABLE_ACTION));
        [$builder, $action_token, $ids_token] = (new URLBuilder($uri))->acquireParameters(['xvbd', 'table'], 'action', 'ids');
        $actions = [
            'release' => $f->table()->action()->standard($this->txt('action_release'), $builder->withParameter($action_token, 'release'), $ids_token),
            'retry' => $f->table()->action()->single($this->txt('action_retry'), $builder->withParameter($action_token, 'retry'), $ids_token),
            'reissue' => $f->table()->action()->single($this->txt('action_reissue'), $builder->withParameter($action_token, 'reissue'), $ids_token),
            'resend' => $f->table()->action()->single($this->txt('action_resend'), $builder->withParameter($action_token, 'resend'), $ids_token),
        ];

        return ilVirtualbadgeParticipantTable::build($f, $this->txt('participants'), $columns, new ilVirtualbadgeParticipantTable($rows), $actions)
            ->withRequest($this->request());
    }

    /**
     * @return int[]|null null means all rows
     */
    private function tableIds(): ?array
    {
        global $DIC;
        $query = $DIC->http()->wrapper()->query();
        if (!$query->has('xvbd_table_ids')) {
            return [];
        }
        $raw = $query->retrieve('xvbd_table_ids', $DIC->refinery()->custom()->transformation(
            static fn($value): array => is_array($value) ? $value : [$value]
        ));
        if (in_array('ALL_OBJECTS', $raw, true)) {
            return null;
        }
        return array_values(array_filter(array_map('intval', $raw)));
    }

    private function tableAction(): void
    {
        global $DIC;
        $query = $DIC->http()->wrapper()->query();
        $action = $query->has('xvbd_table_action')
            ? $query->retrieve('xvbd_table_action', $DIC->refinery()->kindlyTo()->string())
            : '';
        $ids = $this->tableIds();
        $instance = $this->instance();
        $issuer = $this->services()->issuer();
        $issues = $this->services()->issues();

        switch ($action) {
            case 'release':
                if ($ids === null && $instance->course_obj_id <= 0) {
                    $ids = [];
                } elseif ($ids === null) {
                    $ids = $instance->trigger === Instance::TRIGGER_MANUAL
                        ? array_map('intval', ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->getMembers())
                        : array_keys($issuer->pendingUsers($instance));
                }
                $count = $issuer->releaseSelected($instance, array_slice($ids, 0, self::RELEASE_MAX));
                $this->message($count > 0 ? 'success' : 'info', $count > 0
                    ? sprintf($this->txt('release_done'), $count)
                    : $this->txt('release_not_possible'), true);
                break;
            case 'retry':
                $issue = $ids ? $issues->findFor($instance->obj_id, $ids[0]) : null;
                $ok = $issue !== null && $issuer->retry($issue);
                $this->message($ok ? 'success' : 'failure', $this->txt($ok ? 'retry_done' : 'retry_not_possible'), true);
                break;
            case 'reissue':
                $issue = $ids ? $issues->findFor($instance->obj_id, $ids[0]) : null;
                if ($issue !== null && $issue->hasCertificate()) {
                    $this->confirmReissue($issue);
                    return;
                }
                $this->message('failure', $this->txt('reissue_not_possible'), true);
                break;
            case 'resend':
                $issue = $ids ? $issues->findFor($instance->obj_id, $ids[0]) : null;
                try {
                    $ok = $issue !== null && $issuer->resendEmail($issue);
                    $this->message($ok ? 'success' : 'failure', $this->txt($ok ? 'resend_done' : 'resend_not_possible'), true);
                } catch (ApiException $e) {
                    $this->message('failure', self::esc($e->getMessage()), true);
                }
                break;
        }
        $this->ctrl->redirect($this, self::CMD_VIEW);
    }

    private function confirmReissue(Issue $issue): void
    {
        $this->tabs->activateTab(self::TAB_VIEW);
        $f = $this->ui();
        $this->ctrl->setParameter($this, 'issue_id', $issue->id);
        $confirm = $this->ctrl->getLinkTarget($this, self::CMD_REISSUE);
        $this->ctrl->clearParameters($this);
        $name = self::esc(ilObjUser::_lookupFullname($issue->usr_id));
        $this->tpl->setContent($this->render(
            $f->messageBox()->confirmation(sprintf($this->txt('reissue_confirm'), $name))->withButtons([
                $f->button()->primary($this->txt('action_reissue'), $confirm),
                $f->button()->standard($this->lng->txt('cancel'), $this->ctrl->getLinkTarget($this, self::CMD_VIEW)),
            ])
        ));
    }

    private function reissueConfirmed(): void
    {
        global $DIC;
        $query = $DIC->http()->wrapper()->query();
        $id = $query->has('issue_id') ? $query->retrieve('issue_id', $DIC->refinery()->kindlyTo()->int()) : 0;
        $issue = $this->services()->issues()->find($id);
        $ok = $issue !== null && $issue->obj_id === $this->object->getId() && $this->services()->issuer()->reissue($issue);
        $this->message($ok ? 'success' : 'failure', $this->txt($ok ? 'reissue_done' : 'reissue_not_possible'), true);
        $this->ctrl->redirect($this, self::CMD_VIEW);
    }

    private function releaseAll(): void
    {
        $count = $this->services()->issuer()->issueRetroactively($this->instance());
        $this->message($count > 0 ? 'success' : 'info', $count > 0
            ? sprintf($this->txt('release_done'), $count)
            : $this->txt('release_not_possible'), true);
        $this->ctrl->redirect($this, self::CMD_VIEW);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    private function showSettings(?StandardForm $settings_form = null, ?StandardForm $fields_form = null): void
    {
        $this->tabs->activateTab(self::TAB_SETTINGS);
        $f = $this->ui();
        $components = [];
        $instance = $this->instance();
        $templates = null;
        try {
            $templates = $this->templatesByOrg($instance);
        } catch (ApiException $e) {
            $components[] = $f->messageBox()->failure(sprintf($this->txt('templates_unavailable'), self::esc($e->getMessage())));
        }
        $components[] = $f->button()->standard($this->txt('refresh_templates'), $this->ctrl->getLinkTarget($this, self::CMD_REFRESH));
        $components[] = $settings_form ?? $this->settingsForm($templates ?? []);
        if ($instance->isConfigured()) {
            array_push($components, ...$this->mappingWarning($instance));
            $template = $this->currentTemplate($templates ?? []);
            if ($template !== null) {
                array_push($components, ...$this->coursePlaceholders($instance));
                $components[] = $fields_form ?? $this->fieldsForm($template);
            }
        }
        $this->tpl->setContent($this->render($components));
    }

    /**
     * Warning naming the template fields mapped to a source that is not available in the course
     * (e.g. a custom metadata record deactivated or the object copied into another course). No API call.
     *
     * @return list<\ILIAS\UI\Component\MessageBox\MessageBox>
     */
    private function mappingWarning(Instance $instance): array
    {
        $problems = $this->services()->issuer()->mappingProblems($instance);
        if ($problems === []) {
            return [];
        }
        return [$this->ui()->messageBox()->failure(sprintf($this->txt('mapping_unavailable'), self::esc(implode(', ', $problems))))];
    }

    /**
     * Placeholders of the custom metadata fields of the course, for fixed text.
     *
     * @return list<\ILIAS\UI\Component\MessageBox\MessageBox>
     */
    private function coursePlaceholders(Instance $instance): array
    {
        $lines = [];
        foreach ($this->services()->environment()->courseFields($instance) as $id => $field) {
            // Record and field titles are configured by administrators: escape.
            $lines[] = self::esc('{coursefield:' . $id . '}: ' . $field['record'] . ' – ' . $field['title']);
        }
        if ($lines === []) {
            return [];
        }
        return [$this->ui()->messageBox()->info($this->txt('coursefield_placeholders') . '<br>' . implode('<br>', $lines))];
    }

    /**
     * Titles of the custom metadata fields of the course (without record), for suggestions.
     *
     * @return array<string, string> field id => title
     */
    private function courseFieldTitles(Instance $instance): array
    {
        return array_map(
            static fn(array $field): string => $field['title'],
            $this->services()->environment()->courseFields($instance)
        );
    }

    /**
     * Templates per allowed organisation.
     *
     * @return array<string, list<array{id: string, name: string, type: string, fields: string[], slots: array<string, string>}>>
     */
    private function templatesByOrg(Instance $instance, bool $refresh = false): array
    {
        $api = $this->services()->api();
        $result = [];
        foreach (array_keys($this->allowedOrgs($instance)) as $org) {
            $result[$org] = $api->isConfigured($org) ? $api->templates($org, $refresh) : [];
        }
        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function allowedOrgs(Instance $instance): array
    {
        return $this->services()->allowedOrgs(
            $instance->course_ref_id,
            $instance->org !== '' ? $instance->org : null,
            $this->user->getId()
        );
    }

    private function currentTemplate(array $templates): ?array
    {
        $instance = $this->instance();
        foreach ($templates[$instance->org] ?? [] as $template) {
            if ($template['id'] === $instance->template_id) {
                return $template;
            }
        }
        return null;
    }

    private function refreshTemplates(): void
    {
        try {
            $this->templatesByOrg($this->instance(), true);
            $this->message('success', $this->txt('templates_refreshed'), true);
        } catch (ApiException $e) {
            $this->message('failure', sprintf($this->txt('templates_unavailable'), self::esc($e->getMessage())), true);
        }
        $this->ctrl->redirect($this, self::CMD_SETTINGS);
    }

    private function settingsForm(array $templates): StandardForm
    {
        global $DIC;
        $f = $this->ui();
        $field = $f->input()->field();
        $refinery = $DIC->refinery();
        $instance = $this->instance();
        $orgs = $this->allowedOrgs($instance);

        $org_groups = [];
        foreach ($orgs as $key => $name) {
            $options = [];
            foreach ($templates[$key] ?? [] as $template) {
                $type = $this->txt('type_' . ($template['type'] === 'badge' ? 'badge' : 'certificate'));
                $options[$template['id']] = self::esc($template['name']) . ' (' . $type . ')';
            }
            $select = $field->select($this->txt('template'), $options, $this->txt('template_info'))->withRequired(true);
            if ($instance->org === $key && isset($options[$instance->template_id])) {
                $select = $select->withValue($instance->template_id);
            }
            $org_groups[$key] = $field->group(['template' => $select], self::esc($name));
        }
        $org_value = isset($orgs[$instance->org]) ? $instance->org : (string) array_key_first($orgs);
        if ($instance->org === '' && $instance->course_ref_id > 0) {
            $course_org = $this->services()->courseOrg($instance->course_ref_id);
            $org_value = isset($orgs[$course_org]) ? $course_org : $org_value;
        }
        $org_input = $field->switchableGroup($org_groups, $this->txt('org'), $this->txt('org_info'))
            ->withValue($org_value)
            ->withRequired(true);

        $object_options = $this->triggerObjectOptions($instance);
        $object_select = $field->select($this->txt('trigger_object_select'), $object_options, $this->txt('trigger_object_select_info'))
            ->withRequired(true);
        if (isset($object_options[$instance->trigger_ref_id])) {
            $object_select = $object_select->withValue($instance->trigger_ref_id);
        }
        $percent = $field->numeric($this->txt('min_percent'), $this->txt('min_percent_info'))
            ->withAdditionalTransformation($refinery->custom()->constraint(
                static fn(?int $v): bool => $v === null || ($v > 0 && $v <= 100),
                $this->txt('min_percent_invalid')
            ));
        if ($instance->min_percent !== null) {
            $percent = $percent->withValue((int) round($instance->min_percent));
        }
        $trigger = $field->switchableGroup([
            Instance::TRIGGER_COMPLETION => $field->group([], $this->txt('trigger_completion'), $this->txt('trigger_completion_info')),
            Instance::TRIGGER_APPROVAL => $field->group([], $this->txt('trigger_approval'), $this->txt('trigger_approval_info')),
            Instance::TRIGGER_OBJECT => $field->group(['object' => $object_select, 'min_percent' => $percent], $this->txt('trigger_object'), $this->txt('trigger_object_info')),
            Instance::TRIGGER_MANUAL => $field->group([], $this->txt('trigger_manual'), $this->txt('trigger_manual_info')),
        ], $this->txt('trigger'))->withValue($instance->trigger)->withRequired(true);

        $inputs = [
            'title' => $field->text($this->lng->txt('title'))->withValue($this->object->getTitle())->withRequired(true),
            'description' => $field->textarea($this->lng->txt('description'))->withValue($this->object->getLongDescription()),
            'org' => $org_input,
            'trigger' => $trigger,
            'send_email' => $field->checkbox($this->txt('send_email'), $this->txt('send_email_info'))->withValue($instance->send_email),
            'completion_issued' => $field->checkbox($this->txt('completion_issued'), $this->txt('completion_issued_info'))
                ->withValue($instance->completion_issued),
        ];
        return $f->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, self::CMD_SAVE_SETTINGS),
            ['settings' => $field->section($inputs, $this->txt('section_settings'))]
        );
    }

    /**
     * Objects of the course that can be the trigger: everything with learning progress except containers.
     *
     * @return array<int, string> ref id => title
     */
    private function triggerObjectOptions(Instance $instance): array
    {
        if ($instance->course_ref_id <= 0) {
            return [];
        }
        $options = [];
        $excluded = ['fold', 'grp', 'itgr', 'rolf', 'crs', ilVirtualbadgePlugin::ID];
        foreach ($this->tree->getSubTree($this->tree->getNodeData($instance->course_ref_id)) as $node) {
            if (in_array($node['type'], $excluded, true) || ilObject::_isInTrash((int) $node['child'])) {
                continue;
            }
            if (!ilObjectLP::isSupportedObjectType($node['type'])) {
                continue;
            }
            $options[(int) $node['child']] = self::esc((string) $node['title']) . ' (' . $this->lng->txt('obj_' . $node['type']) . ')';
        }
        return $options;
    }

    private function saveSettings(): void
    {
        $instance = $this->instance();
        $form = $this->settingsForm($this->safeTemplates($instance))->withRequest($this->request());
        $data = $form->getData();
        if (!is_array($data)) {
            $this->message('failure', $this->txt('form_invalid'));
            $this->showSettings($form);
            return;
        }
        $data = $data['settings'];
        $this->object->setTitle((string) $data['title']);
        $this->object->setDescription((string) ($data['description'] ?? ''));

        [$org, $org_values] = $data['org'];
        $allowed = $this->allowedOrgs($instance);
        if (!isset($allowed[$org])) {
            $this->message('failure', $this->txt('org_not_allowed'));
            $this->showSettings($form);
            return;
        }
        $template_id = (string) ($org_values['template'] ?? '');
        $template = null;
        foreach ($this->safeTemplates($instance)[$org] ?? [] as $candidate) {
            if ($candidate['id'] === $template_id) {
                $template = $candidate;
            }
        }
        $template_changed = $template !== null && ($template_id !== $instance->template_id || $org !== $instance->org);
        if ($template !== null) {
            $instance->org = $org;
            $instance->template_id = $template_id;
            $instance->template_name = $template['name'];
            $instance->template_type = $template['type'] === 'badge' ? 'badge' : 'certificate';
            if ($template_changed) {
                $instance->field_map = $this->suggestedMap($template, $instance->field_map);
            }
        }

        [$trigger, $trigger_values] = $data['trigger'];
        $instance->trigger = in_array($trigger, Instance::TRIGGERS, true) ? $trigger : Instance::TRIGGER_COMPLETION;
        if ($instance->trigger === Instance::TRIGGER_OBJECT) {
            $instance->trigger_ref_id = (int) ($trigger_values['object'] ?? 0);
            $instance->min_percent = isset($trigger_values['min_percent']) ? (float) $trigger_values['min_percent'] : null;
        } else {
            $instance->trigger_ref_id = 0;
            $instance->min_percent = null;
        }
        $instance->send_email = (bool) $data['send_email'];
        $instance->completion_issued = (bool) $data['completion_issued'];
        $this->vbObject()->setInstance($instance);
        $this->object->update();

        $this->message('success', $this->txt($template_changed ? 'settings_saved_check_fields' : 'settings_saved'), true);
        $this->ctrl->redirect($this, self::CMD_SETTINGS);
    }

    private function safeTemplates(Instance $instance): array
    {
        try {
            return $this->templatesByOrg($instance);
        } catch (ApiException $e) {
            return [];
        }
    }

    /**
     * Mapping for a new template: kept where the field existed before, otherwise suggested from the field name.
     *
     * @return array<string, array{source: string, value: string}>
     */
    private function suggestedMap(array $template, array $old): array
    {
        $profile = $this->services()->profileFields()->available();
        $course_fields = $this->courseFieldTitles($this->instance());
        $map = [];
        foreach ($template['fields'] as $name) {
            $map[$name] = isset($old[$name])
                ? FieldMapper::normalize($old[$name])
                : ['source' => FieldMapper::suggest($name, $profile, $course_fields), 'value' => ''];
        }
        return $map;
    }

    /**
     * @return array<string, string> source => label
     */
    private function sourceOptions(Instance $instance): array
    {
        $environment = $this->services()->environment();
        $options = [];
        foreach ($environment->validSources($instance) as $source) {
            // Custom user fields and custom metadata fields are named by administrators: escape.
            $options[$source] = $this->sourceGroupLabel($source) . ': ' . self::esc($environment->sourceLabel($instance, $source));
        }
        return $options;
    }

    private function sourceGroupLabel(string $source): string
    {
        $type = explode(':', $source, 2)[0];
        return match ($type) {
            'text' => $this->txt('sourcegroup_text'),
            'user', 'profile' => $this->txt('sourcegroup_user'),
            'course' => $this->txt('sourcegroup_course'),
            'coursefield' => $this->txt('sourcegroup_coursefield'),
            default => $this->txt('sourcegroup_issue'),
        };
    }

    private static function fieldKey(string $name): string
    {
        return 'f_' . substr(sha1($name), 0, 16);
    }

    private function fieldsForm(array $template): StandardForm
    {
        $f = $this->ui();
        $field = $f->input()->field();
        $instance = $this->instance();
        $options = $this->sourceOptions($instance);
        $profile = $this->services()->profileFields()->available();
        $course_fields = $this->courseFieldTitles($instance);
        $sections = [];
        foreach ($template['fields'] as $name) {
            $entry = isset($instance->field_map[$name])
                ? FieldMapper::normalize($instance->field_map[$name])
                : ['source' => FieldMapper::suggest($name, $profile, $course_fields), 'value' => ''];
            $source = $field->select($this->txt('field_source'), $options, $this->txt('field_source_info'))->withRequired(true);
            if (isset($options[$entry['source']])) {
                $source = $source->withValue($entry['source']);
            }
            $text = $field->text($this->txt('field_text'), self::braces($this->txt('field_text_info')))->withValue($entry['value']);
            $sections[self::fieldKey($name)] = $field->section(
                ['source' => $source, 'value' => $text],
                sprintf($this->txt('field_heading'), self::esc($name))
            );
        }
        if ($sections === []) {
            $sections['none'] = $field->section([], $this->txt('fields_none'));
        }
        return $f->input()->container()->form()->standard($this->ctrl->getFormAction($this, self::CMD_SAVE_FIELDS), $sections);
    }

    private function saveFields(): void
    {
        $instance = $this->instance();
        $template = $this->currentTemplate($this->safeTemplates($instance));
        if ($template === null) {
            $this->message('failure', sprintf($this->txt('template_unavailable'), self::esc($instance->template_name)), true);
            $this->ctrl->redirect($this, self::CMD_SETTINGS);
        }
        $form = $this->fieldsForm($template)->withRequest($this->request());
        $data = $form->getData();
        if (!is_array($data)) {
            $this->message('failure', $this->txt('form_invalid'));
            $this->showSettings(null, $form);
            return;
        }
        $map = [];
        foreach ($template['fields'] as $name) {
            $values = $data[self::fieldKey($name)] ?? [];
            $map[$name] = [
                'source' => (string) ($values['source'] ?? FieldMapper::TEXT),
                'value' => trim((string) ($values['value'] ?? '')),
            ];
        }
        $instance->field_map = $map;
        $this->vbObject()->setInstance($instance);
        $this->object->update();
        $this->message('success', $this->txt('fields_saved'), true);
        $this->ctrl->redirect($this, self::CMD_SETTINGS);
    }
}
