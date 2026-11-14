<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/** Manual order (an "ordering" column) within a scope, e.g. menu items of one parent. */
final class Ordering
{
    /**
     * Moves row $id one place up (-1) or down (+1) among the rows sharing its $scope columns.
     * Orderings in the scope are renumbered 1..n first. Returns false at the edge.
     *
     * @param list<string> $scope column names
     */
    public static function move(string $table, int $id, int $direction, array $scope): bool
    {
        $db = Db::get();
        $row = $db->fetchRow("SELECT * FROM $table WHERE id = ?", [$id]);
        if ($row === null) {
            return false;
        }
        $ids = self::idsInScope($table, $row, $scope);
        $pos = array_search($id, $ids, true);
        $swap = $pos + $direction;
        if ($pos === false || $swap < 0 || $swap >= count($ids)) {
            return false;
        }
        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        self::write($table, $ids);
        return true;
    }

    /** Next ordering value for a new row in the scope (at the end). */
    public static function next(string $table, array $values): int
    {
        $where = implode(' AND ', array_map(fn ($c) => "$c = ?", array_keys($values)));
        return (int) Db::get()->fetchValue("SELECT MAX(ordering) FROM $table WHERE $where", array_values($values)) + 1;
    }

    /** @return list<int> */
    private static function idsInScope(string $table, array $row, array $scope): array
    {
        $where = implode(' AND ', array_map(fn ($c) => "$c = ?", $scope));
        $params = array_map(fn ($c) => $row[$c], $scope);
        return array_map('intval', Db::get()->fetchColumn("SELECT id FROM $table WHERE $where ORDER BY ordering, id", $params));
    }

    /** @param list<int> $ids */
    private static function write(string $table, array $ids): void
    {
        Db::get()->transaction(function (Db $db) use ($table, $ids) {
            foreach ($ids as $i => $id) {
                $db->update($table, ['ordering' => $i + 1], ['id' => $id]);
            }
        });
    }
}
