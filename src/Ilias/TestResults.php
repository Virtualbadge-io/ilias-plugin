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
 * Scored result of a user in an ILIAS test.
 *
 * Reads the result cache of the test (tst_result_cache), which exists with the same columns in
 * ILIAS 10 and 11, while the PHP API for test results changed between the versions.
 */
class TestResults
{
    public function __construct(private readonly \ilDBInterface $db)
    {
    }

    /**
     * @return array{points: float, max_points: float, percent: float, passed: bool}|null null if no result exists
     */
    public function result(int $test_obj_id, int $usr_id): ?array
    {
        $result = $this->db->queryF(
            'SELECT rc.reached_points, rc.max_points, rc.passed FROM tst_result_cache rc'
            . ' JOIN tst_active a ON a.active_id = rc.active_fi'
            . ' JOIN tst_tests t ON t.test_id = a.test_fi'
            . ' WHERE t.obj_fi = %s AND a.user_fi = %s',
            [\ilDBConstants::T_INTEGER, \ilDBConstants::T_INTEGER],
            [$test_obj_id, $usr_id]
        );
        $row = $this->db->fetchAssoc($result);
        if ($row === null) {
            return null;
        }
        $max = (float) $row['max_points'];
        $points = (float) $row['reached_points'];
        return [
            'points' => $points,
            'max_points' => $max,
            'percent' => $max > 0 ? $points / $max * 100 : 0.0,
            'passed' => (bool) $row['passed'],
        ];
    }

    public static function isTest(int $obj_id): bool
    {
        return $obj_id > 0 && \ilObject::_lookupType($obj_id) === 'tst';
    }
}
