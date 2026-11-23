<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

use ILIAS\Data\Order;
use ILIAS\Data\Range;
use ILIAS\UI\Component\Table\DataRetrieval;
use ILIAS\UI\Component\Table\DataRowBuilder;

/**
 * Rows of the status list of all participants (UI framework data table).
 *
 * VERSION SPECIFIC (ILIAS 10): DataRetrieval signature and $f->table()->data($title, $columns, $retrieval).
 * ILIAS 11 changed both, see branch release_11.
 */
class ilVirtualbadgeParticipantTable implements DataRetrieval
{
    public const ACTIONS = ['release', 'retry', 'reissue', 'resend'];

    /**
     * @param list<array{id: int, name: string, status: string, condition: string, issued: string,
     *     error: string, actions: array<string, bool>}> $rows prepared by ilObjVirtualbadgeGUI
     */
    public function __construct(private readonly array $rows)
    {
    }

    /**
     * @param array<string, \ILIAS\UI\Component\Table\Action\Action> $actions
     */
    public static function build(
        \ILIAS\UI\Factory $f,
        string $title,
        array $columns,
        self $retrieval,
        array $actions
    ): \ILIAS\UI\Component\Table\Data {
        return $f->table()->data($title, $columns, $retrieval)->withActions($actions)->withRange(new Range(0, 50));
    }

    public function getRows(
        DataRowBuilder $row_builder,
        array $visible_column_ids,
        Range $range,
        Order $order,
        ?array $filter_data,
        ?array $additional_parameters
    ): Generator {
        $rows = $this->rows;
        [$field, $direction] = $order->join([], fn($ret, $key, $value) => [$key, $value]);
        if (in_array($field, ['name', 'status', 'condition', 'issued'], true)) {
            usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a[$field], $b[$field]));
            if ($direction === Order::DESC) {
                $rows = array_reverse($rows);
            }
        }
        foreach (array_slice($rows, $range->getStart(), $range->getLength()) as $row) {
            $data_row = $row_builder->buildDataRow((string) $row['id'], $row);
            foreach (self::ACTIONS as $action) {
                if (empty($row['actions'][$action])) {
                    $data_row = $data_row->withDisabledAction($action);
                }
            }
            yield $data_row;
        }
    }

    public function getTotalRowCount(?array $filter_data, ?array $additional_parameters): ?int
    {
        return count($this->rows);
    }
}
