<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use ILIAS\UI\Component\Input\Container\Form\Standard as StandardForm;
use Virtualbadge\Core\Api\ApiException;
use Virtualbadge\Core\Config\Settings;
use Virtualbadge\Core\Issue\Issue;
use Virtualbadge\Ilias\Services;

/**
 * Plugin configuration in Administration > Extending ILIAS > Plugins > Virtualbadge > Configure.
 *
 * API keys are stored encrypted and never shown again: the password fields are always empty,
 * leaving them empty keeps the stored key.
 *
 * @ilCtrl_IsCalledBy ilVirtualbadgeConfigGUI: ilObjComponentSettingsGUI
 */
class ilVirtualbadgeConfigGUI extends ilPluginConfigGUI
{
    private const CMD_CONFIGURE = 'configure';
    private const CMD_SAVE = 'save';
    private const CMD_TEST = 'testConnection';

    private ilCtrlInterface $ctrl;
    private ilGlobalTemplateInterface $tpl;
    private \ILIAS\UI\Factory $ui;
    private \ILIAS\UI\Renderer $renderer;
    private \Psr\Http\Message\ServerRequestInterface $request;
    private \ILIAS\Refinery\Factory $refinery;

    public function performCommand(string $cmd): void
    {
        global $DIC;
        $this->ctrl = $DIC->ctrl();
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->ui = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->request = $DIC->http()->request();
        $this->refinery = $DIC->refinery();

        match ($cmd) {
            self::CMD_SAVE => $this->save(),
            self::CMD_TEST => $this->testConnection(),
            default => $this->configure(),
        };
    }

    private function plugin(): ilVirtualbadgePlugin
    {
        /** @var ilVirtualbadgePlugin $plugin */
        $plugin = $this->getPluginObject();
        return $plugin;
    }

    private function services(): Services
    {
        return Services::get($this->plugin());
    }

    private function txt(string $key): string
    {
        return $this->plugin()->txt($key);
    }

    private function configure(?StandardForm $form = null): void
    {
        $toolbar = $this->ui->button()->standard(
            $this->txt('test_connection'),
            $this->ctrl->getLinkTarget($this, self::CMD_TEST)
        );
        $this->tpl->setContent($this->renderer->render([
            $toolbar,
            $form ?? $this->form(),
            $this->statusPanel(),
        ]));
    }

    private function form(): StandardForm
    {
        $field = $this->ui->input()->field();
        $settings = $this->services()->settings();
        $orgs = $settings->orgs($this->txt('main_org'));

        $key_byline = fn(string $org): string => $settings->hasApiKey($org)
            ? $this->txt('api_key_set')
            : $this->txt('api_key_not_set');

        $api = [
            'api_url' => $field->text($this->txt('api_url'), $this->txt('api_url_info'))
                ->withValue($settings->apiUrl())
                ->withRequired(true)
                ->withAdditionalTransformation($this->refinery->custom()->constraint(
                    static fn(string $url): bool => (bool) preg_match('~^https://[^\s/?#]+~i', trim($url)),
                    $this->txt('api_url_invalid')
                )),
            'api_key' => $field->password($this->txt('api_key'), $this->txt('api_key_info') . ' ' . $key_byline(Settings::MAIN_ORG)),
            'rate_limit' => $field->numeric($this->txt('rate_limit'), $this->txt('rate_limit_info'))
                ->withValue($settings->rateLimit(Settings::MAIN_ORG)),
            'date_format' => $field->select($this->txt('date_format'), [
                'dmy' => '31.12.2026',
                'ymd' => '2026-12-31',
                'mdy' => '12/31/2026',
            ], $this->txt('date_format_info'))
                ->withValue($settings->dateFormat())
                ->withRequired(true),
        ];

        $org_inputs = [
            'orgs' => $field->textarea($this->txt('orgs'), $this->txt('orgs_info'))
                ->withValue($settings->orgsText())
                ->withAdditionalTransformation($this->refinery->custom()->constraint(
                    static fn(string $text): bool => Settings::invalidOrgLines($text) === [],
                    $this->txt('orgs_invalid')
                )),
        ];
        foreach ($orgs as $key => $name) {
            if ($key === Settings::MAIN_ORG) {
                continue;
            }
            $org_inputs['api_key_' . $key] = $field->password(
                sprintf($this->txt('api_key_org'), $name),
                $key_byline($key)
            );
            $org_inputs['rate_limit_' . $key] = $field->numeric(sprintf($this->txt('rate_limit_org'), $name))
                ->withValue($settings->rateLimit($key));
        }
        $org_inputs['category_map'] = $field->textarea($this->txt('category_map'), $this->txt('category_map_info'))
            ->withValue($settings->categoryMapText());

        return $this->ui->input()->container()->form()->standard(
            $this->ctrl->getFormAction($this, self::CMD_SAVE),
            [
                'api' => $field->section($api, $this->txt('section_api'), $this->txt('section_api_info')),
                'orgs' => $field->section($org_inputs, $this->txt('section_orgs'), $this->txt('section_orgs_info')),
            ]
        );
    }

    private function save(): void
    {
        $form = $this->form()->withRequest($this->request);
        $data = $form->getData();
        if (!is_array($data)) {
            $this->tpl->setOnScreenMessage('failure', $this->txt('form_invalid'));
            $this->configure($form);
            return;
        }
        // The category map may only use organisations of the posted organisation list.
        $new_orgs = [Settings::MAIN_ORG => ''] + Settings::parseOrgs((string) ($data['orgs']['orgs'] ?? ''));
        $problems = Settings::categoryMapProblems((string) ($data['orgs']['category_map'] ?? ''), $new_orgs);
        if ($problems['invalid'] !== [] || $problems['unknown_orgs'] !== []) {
            $details = array_merge(
                array_map(fn(string $line): string => sprintf($this->txt('category_map_invalid_line'), $line), $problems['invalid']),
                array_map(fn(string $org): string => sprintf($this->txt('category_map_unknown_org'), $org), $problems['unknown_orgs'])
            );
            $this->tpl->setOnScreenMessage('failure', implode('<br>', array_map('htmlspecialchars', $details)));
            $this->configure($form);
            return;
        }
        $settings = $this->services()->settings();
        $api = $data['api'];
        $settings->setApiUrl((string) $api['api_url']);
        $this->storeKey(Settings::MAIN_ORG, $api['api_key'] ?? null);
        $settings->setRateLimit(Settings::MAIN_ORG, (int) ($api['rate_limit'] ?? Settings::DEFAULT_RATE_LIMIT));
        $settings->setDateFormat((string) $api['date_format']);

        $orgs = $data['orgs'];
        $old_orgs = $settings->orgs();
        $settings->setOrgsText((string) ($orgs['orgs'] ?? ''));
        foreach ($old_orgs as $key => $name) {
            if ($key === Settings::MAIN_ORG || !array_key_exists('rate_limit_' . $key, $orgs)) {
                continue;
            }
            $this->storeKey($key, $orgs['api_key_' . $key] ?? null);
            $settings->setRateLimit($key, (int) ($orgs['rate_limit_' . $key] ?? Settings::DEFAULT_RATE_LIMIT));
        }
        $settings->setCategoryMapText((string) ($orgs['category_map'] ?? ''));
        $this->services()->cache()->clear();

        $this->tpl->setOnScreenMessage('success', $this->txt('settings_saved'), true);
        $this->ctrl->redirect($this, self::CMD_CONFIGURE);
    }

    /**
     * Store a newly entered key. An empty field keeps the stored key.
     */
    private function storeKey(string $org, mixed $password): void
    {
        $value = $password instanceof \ILIAS\Data\Password ? $password->toString() : (string) $password;
        if (trim($value) !== '') {
            $this->services()->settings()->setApiKey($org, $value);
        }
    }

    /**
     * Load the templates of every organisation with an API key.
     */
    private function testConnection(): void
    {
        $settings = $this->services()->settings();
        $api = $this->services()->api();
        $messages = [];
        $ok = true;
        foreach ($settings->orgs($this->txt('main_org')) as $key => $name) {
            if (!$api->isConfigured($key)) {
                $messages[] = sprintf($this->txt('test_not_configured'), $name);
                $ok = false;
                continue;
            }
            try {
                $count = count($api->templates($key, true));
                $messages[] = sprintf($this->txt('test_ok'), $name, $count);
            } catch (ApiException $e) {
                $messages[] = sprintf($this->txt('test_failed'), $name, $e->getMessage());
                $ok = false;
            }
        }
        $this->tpl->setOnScreenMessage($ok ? 'success' : 'failure', implode('<br>', array_map('htmlspecialchars', $messages)), true);
        $this->ctrl->redirect($this, self::CMD_CONFIGURE);
    }

    /**
     * Certificates by status in the last 24 hours and the latest errors.
     */
    private function statusPanel(): \ILIAS\UI\Component\Panel\Standard
    {
        $issues = $this->services()->issues();
        $counts = $issues->countByStatus(time() - 86400);
        $items = [];
        foreach (Issue::STATUSES as $status) {
            $items[$this->txt('status_' . $status)] = (string) ($counts[$status] ?? 0);
        }
        $content = [$this->ui->listing()->descriptive($items)];
        $errors = [];
        foreach ($issues->latestErrors(10) as $issue) {
            $errors[] = sprintf(
                '%s · %s · %s',
                ilDatePresentation::formatDate(new ilDateTime($issue->time_modified, IL_CAL_UNIX)),
                ilObject::_lookupTitle($issue->obj_id),
                (string) $issue->last_error
            );
        }
        if ($errors !== []) {
            $content[] = $this->ui->listing()->unordered(array_map('htmlspecialchars', $errors));
        }
        return $this->ui->panel()->standard($this->txt('status_overview'), $content);
    }
}
