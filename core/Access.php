<?php

namespace Pageyard;

defined('PAGEYARD') or exit;

/**
 * Who may see something on the site: everyone, logged-in users only, or guests only (the last one for
 * menu items and blocks, e.g. "Register"). Pages and categories use the first two; a page in a category
 * for logged-in users is for logged-in users too.
 *
 * The site's App says once per request whether the visitor is logged in (setMember). While accounts on the
 * site are off nobody is, so content for logged-in users is hidden from everyone (administrators' previews aside).
 */
final class Access
{
    public const ALL = 0;
    public const MEMBERS = 1;
    public const GUESTS = 2;
    public const LEVELS = [self::ALL, self::MEMBERS, self::GUESTS];

    private static bool $member = false;

    public static function setMember(bool $member): void
    {
        self::$member = $member;
    }

    public static function member(): bool
    {
        return self::$member;
    }

    /** True when the current visitor sees something with this access. */
    public static function allows(int $access): bool
    {
        return in_array($access, self::visible(), true);
    }

    /** SQL condition on an access column for the current visitor (only fixed integers, no input). */
    public static function sql(string $column): string
    {
        return "$column IN (" . implode(', ', self::visible()) . ')';
    }

    /** Runs $fn as if the visitor were logged in (or not), e.g. to tell "needs a login" from "doesn't exist". */
    public static function as(bool $member, callable $fn): mixed
    {
        $before = self::$member;
        self::$member = $member;
        try {
            return $fn();
        } finally {
            self::$member = $before;
        }
    }

    /** A posted access value, limited to $allowed. */
    public static function parse(int $value, array $allowed = self::LEVELS): int
    {
        return in_array($value, $allowed, true) ? $value : self::ALL;
    }

    /** Tables with an access column => the home item type that must stay for everyone (or null). */
    public const TABLES = ['#__pages' => 'page', '#__categories' => 'category', '#__blocks' => null, '#__menu_items' => null];

    /**
     * Sets the access of several rows (list action). The home page stays for everyone.
     * @param list<int> $ids
     * @return array{int, bool} [changed count, the home item was left out]
     */
    public static function change(string $table, array $ids, int $level): array
    {
        if (!array_key_exists($table, self::TABLES)) {
            throw new \InvalidArgumentException("No access column in $table");
        }
        $level = self::parse($level, self::TABLES[$table] !== null ? [self::ALL, self::MEMBERS] : self::LEVELS);
        $homeType = self::TABLES[$table];
        $kept = $ids;
        if ($homeType !== null && $level !== self::ALL) {
            $kept = Home::without($homeType, $ids);
        }
        $count = 0;
        foreach ($kept as $id) {
            $count += Database\Db::get()->update($table, ['access' => $level, 'modified' => Database\Db::now()], ['id' => $id]);
        }
        return [$count, count($kept) < count($ids)];
    }

    /** Adds a list filter on the access column (value '0', '1' or '2' from the filter select). */
    public static function filter(string $value, string $column, array &$where, array &$params): void
    {
        if (in_array($value, ['0', '1', '2'], true)) {
            $where[] = "$column = ?";
            $params[] = (int) $value;
        }
    }

    /** @return list<int> */
    private static function visible(): array
    {
        return self::$member ? [self::ALL, self::MEMBERS] : [self::ALL, self::GUESTS];
    }
}
