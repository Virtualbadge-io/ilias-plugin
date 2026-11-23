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
use Virtualbadge\Core\Fields\SourceValues;
use Virtualbadge\Core\Issue\Environment;
use Virtualbadge\Core\Issue\Instance;
use Virtualbadge\Core\Issue\Issue;

/**
 * ILIAS implementation of what the issuer needs: objects, users, courses, learning progress.
 */
class IliasEnvironment implements Environment
{
    /** @var \Closure(string): string */
    private \Closure $txt;

    /**
     * @param callable(string): string $txt plugin language lookup
     */
    public function __construct(
        private readonly InstanceRepository $instances,
        private readonly LearningProgress $lp,
        private readonly ProfileFields $profile,
        private readonly TestResults $tests,
        private readonly CourseMetadata $metadata,
        private readonly \ilTree $tree,
        private readonly \ilAccessHandler $access,
        private readonly \ilLanguage $lng,
        private readonly \ilLogger $logger,
        callable $txt
    ) {
        $this->txt = \Closure::fromCallable($txt);
    }

    public function instance(int $obj_id): ?Instance
    {
        return $this->instances->find($obj_id);
    }

    public function isEligible(Instance $instance, int $usr_id): bool
    {
        if ($instance->course_obj_id <= 0 || $instance->ref_id <= 0) {
            return false;
        }
        // Only course members receive certificates, not tutors or administrators of the course.
        if (!\ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->isMember($usr_id)) {
            return false;
        }
        return $this->access->checkAccessOfUser($usr_id, 'read', '', $instance->ref_id);
    }

    public function userEmail(int $usr_id): ?string
    {
        if (!\ilObjUser::_exists($usr_id, false, 'usr')) {
            return null;
        }
        return (string) \ilObjUser::_lookupEmail($usr_id);
    }

    public function userFullName(int $usr_id): string
    {
        $name = \ilObjUser::_lookupName($usr_id);
        // Same as ilObjUser::setFullname(): academic title, first name, last name (spec "Full name as ILIAS builds it").
        return FieldMapper::collapse(
            ($name['title'] ?? '') . ' ' . ($name['firstname'] ?? '') . ' ' . ($name['lastname'] ?? '')
        );
    }

    public function sourceValues(Instance $instance, int $usr_id, Issue $issue): SourceValues
    {
        $user = new \ilObjUser($usr_id);
        $country = $user->getCountry();
        // Country names are in the "meta" module, which is not loaded in cron runs.
        $this->lng->loadLanguageModule('meta');
        $user_values = [
            'firstname' => $user->getFirstname(),
            'lastname' => $user->getLastname(),
            // Full name as ILIAS builds it, with academic title (ilObjUser::setFullname(), called when the user is read).
            'fullname' => FieldMapper::collapse($user->getFullname()),
            'title' => $user->getUTitle(),
            'email' => $user->getEmail(),
            'login' => $user->getLogin(),
            'matriculation' => $user->getMatriculation(),
            'institution' => $user->getInstitution(),
            'department' => $user->getDepartment(),
            'city' => $user->getCity(),
            'country' => $country !== '' && $this->lng->exists('meta_c_' . strtoupper($country))
                ? $this->lng->txt('meta_c_' . strtoupper($country))
                : $country,
        ];

        $course = [];
        $course_fields = [];
        if ($instance->course_ref_id > 0) {
            // Read when the certificate is sent: a reissue also uses the current values.
            $course_fields = $this->metadata->values($instance->course_obj_id, $instance->course_ref_id);
            $crs = new \ilObjCourse($instance->course_ref_id);
            $course = [
                'title' => $crs->getTitle(),
                'description' => $crs->getDescription(),
                'category' => $this->categoryTitle($instance->course_ref_id),
                'startdate' => $crs->getCourseStart() ? (int) $crs->getCourseStart()->get(IL_CAL_UNIX) : 0,
                'enddate' => $crs->getCourseEnd() ? (int) $crs->getCourseEnd()->get(IL_CAL_UNIX) : 0,
            ];
        }

        $result = null;
        if ($instance->trigger === Instance::TRIGGER_OBJECT && TestResults::isTest($instance->trigger_obj_id)) {
            $result = $this->tests->result($instance->trigger_obj_id, $usr_id);
        }

        return new SourceValues(
            $user_values,
            $this->profile->values($usr_id),
            $course,
            $course_fields,
            $issue->completed_at,
            $result['percent'] ?? null,
            $result['points'] ?? null,
            $result['max_points'] ?? null
        );
    }

    public function validSources(Instance $instance): array
    {
        return FieldMapper::sources(
            array_map('strval', array_keys($this->profile->available())),
            array_map('strval', array_keys($this->courseFields($instance)))
        );
    }

    public function sourceLabel(Instance $instance, string $source): string
    {
        [$type, $name] = array_pad(explode(':', $source, 2), 2, '');
        if ($type === 'profile') {
            return $this->profile->available()[$name] ?? $name;
        }
        if ($type === 'coursefield') {
            $field = $this->courseFields($instance)[$name] ?? null;
            return $field !== null ? $field['record'] . ' – ' . $field['title'] : $name;
        }
        return ($this->txt)('source_' . $type . ($name !== '' ? '_' . $name : ''));
    }

    /**
     * Custom metadata fields active for the object's course, titles in the current user's language.
     * Not escaped (configured by administrators).
     *
     * @return array<string, array{record: string, title: string}> field id => record title and field title
     */
    public function courseFields(Instance $instance): array
    {
        return $this->metadata->fields($instance->course_ref_id, $this->lng->getLangKey());
    }

    public function conditionMet(Instance $instance): array
    {
        if ($instance->course_obj_id <= 0) {
            return [];
        }
        $members = \ilCourseParticipants::_getInstanceByObjId($instance->course_obj_id)->getMembers();
        $members = array_map('intval', $members);
        if ($members === []) {
            return [];
        }
        switch ($instance->trigger) {
            case Instance::TRIGGER_COMPLETION:
            case Instance::TRIGGER_APPROVAL:
                $result = $this->lp->completed($instance->course_obj_id, $members);
                $others = $this->lp->otherCollectionItems($instance);
                if ($others !== null) {
                    $now = time();
                    foreach ($this->lp->completedAll($others, array_diff($members, array_keys($result))) as $usr_id) {
                        $result[$usr_id] = $now;
                    }
                }
                break;
            case Instance::TRIGGER_OBJECT:
                $result = [];
                if ($instance->trigger_obj_id <= 0) {
                    break;
                }
                if ($instance->min_percent !== null && TestResults::isTest($instance->trigger_obj_id)) {
                    foreach ($members as $usr_id) {
                        $test = $this->tests->result($instance->trigger_obj_id, $usr_id);
                        if ($test !== null && $test['percent'] >= $instance->min_percent) {
                            $result[$usr_id] = $this->lp->completedAt($instance->trigger_obj_id, $usr_id) ?? time();
                        }
                    }
                } else {
                    $result = $this->lp->completed($instance->trigger_obj_id, $members);
                }
                break;
            default:
                $result = [];
        }
        return array_filter(
            $result,
            fn(int $usr_id): bool => $this->access->checkAccessOfUser($usr_id, 'read', '', $instance->ref_id)
                && trim((string) $this->userEmail($usr_id)) !== '',
            ARRAY_FILTER_USE_KEY
        );
    }

    public function siteId(): string
    {
        $path = defined('ILIAS_HTTP_PATH') ? (string) ILIAS_HTTP_PATH : '';
        $client = defined('CLIENT_ID') ? (string) CLIENT_ID : '';
        return $path . '|' . $client;
    }

    public function issueChanged(Instance $instance, Issue $issue): void
    {
        if ($instance->completion_issued) {
            \ilLPStatusWrapper::_updateStatus($instance->obj_id, $issue->usr_id);
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $text = $message . ($context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '');
        match ($level) {
            'error' => $this->logger->error($text),
            'warning' => $this->logger->warning($text),
            'debug' => $this->logger->debug($text),
            default => $this->logger->info($text),
        };
    }

    /**
     * Title of the category nearest to the course.
     */
    private function categoryTitle(int $course_ref_id): string
    {
        $cat_ref = (int) $this->tree->checkForParentType($course_ref_id, 'cat');
        return $cat_ref > 0 ? (string) \ilObject::_lookupTitle(\ilObject::_lookupObjId($cat_ref)) : '';
    }

    /**
     * Ref ids and import ids of the categories from the course upwards, for the category map.
     *
     * @return list<list<string>>
     */
    public function categoryPath(int $course_ref_id): array
    {
        $path = [];
        foreach (array_reverse($this->tree->getPathFull($course_ref_id)) as $node) {
            if (($node['type'] ?? '') !== 'cat') {
                continue;
            }
            $refs = [(string) $node['child']];
            $import_id = (string) \ilObject::_lookupImportId((int) $node['obj_id']);
            if ($import_id !== '') {
                $refs[] = $import_id;
            }
            $path[] = $refs;
        }
        return $path;
    }
}
