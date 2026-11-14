<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/**
 * Short-lived history of pages, HTML blocks and the custom CSS.
 *
 * - A revision is the version being replaced, written in the same transaction as the new one, so
 *   the previous version can be restored even when the history was empty. The current version
 *   lives only in the content tables.
 * - Retention (history_days) counts from the moment a version was replaced, not from its own date:
 *   a page last edited three months ago must not lose its previous version straight away.
 * - Frequent saves are thinned: the just-replaced version is always kept, and the one before it
 *   goes when it is closer than history_interval minutes to its predecessor. That leaves the
 *   state before the session, a checkpoint every interval and the direct predecessor.
 * - Saves that don't change the content (a snapshot's "content") leave no revision. Versions
 *   replaced by a restore or a deletion are never thinned.
 *
 * A snapshot is ['title', 'date', 'author', 'content' => array, 'extra' => array]; "extra" holds
 * what only a deleted item needs to come back.
 */
final class Revisions
{
    public const TYPES = ['page', 'block', 'css'];
    private const MAX_BYTES = 15_000_000;  // mediumtext holds 16 MB
    private const LIST_COLUMNS = 'id, item_type, item_id, reason, title, author, version_date, created';

    /** Current time override for tests. */
    public static ?int $now = null;

    public static function days(): int
    {
        return max(0, (int) Config::get('history_days', 7));
    }

    /**
     * Whether new versions are being recorded. Switching off only stops recording: what is already
     * kept can still be restored until it expires. history_days = 0 is the old way to switch off.
     */
    public static function enabled(): bool
    {
        return (bool) Config::get('history_enabled', true) && self::days() > 0;
    }

    /** True when an item's edit form should link to its history. */
    public static function linked(string $type, int $id): bool
    {
        return self::enabled() || self::count($type, $id) > 0;
    }

    /** Records $before after a save, unless the content is the same. */
    public static function saved(string $type, int $id, ?array $before, ?array $after, string $reason = 'save'): void
    {
        if ($before !== null && ($after === null || $before['content'] !== $after['content'])) {
            self::record($type, $id, $before, $reason);
        }
    }

    /** Never throws: a problem with the history must not stop the content from being saved. */
    public static function record(string $type, int $id, array $snapshot, string $reason = 'save'): void
    {
        if (!self::enabled()) {
            return;
        }
        try {
            $json = json_encode(['content' => $snapshot['content'], 'extra' => $snapshot['extra'] ?? []],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false || strlen($json) > self::MAX_BYTES) {
                Log::error("History: $type $id is too large to keep a version of.");
                return;
            }
            if ($reason === 'save') {
                self::thin($type, $id);
            }
            Db::get()->insert('#__revisions', [
                'item_type'    => $type,
                'item_id'      => $id,
                'reason'       => $reason,
                'title'        => mb_substr((string) ($snapshot['title'] ?? ''), 0, 255),
                'author'       => mb_substr((string) ($snapshot['author'] ?? ''), 0, 150),
                'version_date' => (string) ($snapshot['date'] ?? '') ?: self::now(),
                'created'      => self::now(),
                'data'         => $json,
            ]);
            self::purge();
        } catch (\Throwable $e) {
            Log::error("History: could not record $type $id: " . $e->getMessage());
        }
    }

    /** Drops the newest revision if it is an intermediate one: too close to the revision before it. */
    private static function thin(string $type, int $id): void
    {
        $minutes = max(0, (int) Config::get('history_interval', 10));
        if ($minutes === 0) {
            return;
        }
        $db = Db::get();
        $rows = $db->fetchAll('SELECT id, reason, created FROM #__revisions WHERE item_type = ? AND item_id = ?
            ORDER BY created DESC, id DESC LIMIT 2', [$type, $id]);
        if (count($rows) === 2 && $rows[0]['reason'] === 'save'
            && self::time($rows[0]['created']) - self::time($rows[1]['created']) < $minutes * 60) {
            $db->delete('#__revisions', ['id' => $rows[0]['id']]);
        }
    }

    /** Deletes revisions past the retention period (all of them when the history is off). */
    public static function purge(): int
    {
        $days = self::days();
        $cutoff = $days === 0 ? self::clock() + 1 : self::clock() - $days * 86400;
        return Db::get()->query('DELETE FROM #__revisions WHERE created < ?', [gmdate('Y-m-d H:i:s', $cutoff)])->rowCount();
    }

    /** A revision with its decoded "content" and "extra", or null. */
    public static function find(int $id): ?array
    {
        $row = Db::get()->fetchRow('SELECT * FROM #__revisions WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        unset($row['data']);
        $row['content'] = is_array($data['content'] ?? null) ? $data['content'] : [];
        $row['extra'] = is_array($data['extra'] ?? null) ? $data['extra'] : [];
        return $row;
    }

    /** @return list<array> an item's revisions without their data, newest first */
    public static function forItem(string $type, int $id): array
    {
        return Db::get()->fetchAll('SELECT ' . self::LIST_COLUMNS . ' FROM #__revisions WHERE item_type = ? AND item_id = ?
            ORDER BY created DESC, id DESC', [$type, $id]);
    }

    public static function count(string $type, int $id): int
    {
        return (int) Db::get()->fetchValue('SELECT COUNT(*) FROM #__revisions WHERE item_type = ? AND item_id = ?', [$type, $id]);
    }

    /** @return list<array> the last state of items that no longer exist, newest first */
    public static function deleted(): array
    {
        $out = [];
        $seen = [];
        foreach (Db::get()->fetchAll('SELECT ' . self::LIST_COLUMNS . " FROM #__revisions WHERE reason = 'delete' ORDER BY created DESC, id DESC") as $row) {
            $key = $row['item_type'] . ':' . $row['item_id'];
            if (!isset($seen[$key]) && !self::exists($row['item_type'], (int) $row['item_id'])) {
                $out[] = $row;
            }
            $seen[$key] = true;
        }
        return $out;
    }

    public static function exists(string $type, int $id): bool
    {
        return match ($type) {
            'page'  => (bool) Db::get()->fetchValue('SELECT 1 FROM #__pages WHERE id = ?', [$id]),
            'block' => (bool) Db::get()->fetchValue('SELECT 1 FROM #__blocks WHERE id = ?', [$id]),
            default => true,
        };
    }

    /**
     * Puts a revision back through the item's normal save, so the version it replaces lands in the
     * history and the restore can be undone.
     * @return array{array, int, array<string, string>, list<string>, bool}|null
     *         [revision, item id, errors, languages with a changed alias, whether a deleted item came back]
     */
    public static function restore(int $revisionId, int $userId): ?array
    {
        $rev = self::find($revisionId);
        if ($rev === null || !in_array($rev['item_type'], self::TYPES, true)) {
            return null;
        }
        $id = (int) $rev['item_id'];
        $recreated = !self::exists($rev['item_type'], $id);
        [$newId, $errors, $renamed] = match ($rev['item_type']) {
            'page'  => Pages::restore($id, $rev['content'], $rev['extra'], $userId),
            'block' => Blocks::restore($id, $rev['content'], $rev['extra']),
            'css'   => Settings::restoreCustomCss($rev['content']),
        };
        if (!$errors) {
            Log::info("History: restored {$rev['item_type']} $newId from revision $revisionId.");
        }
        return [$rev, $newId, $errors, $renamed, $recreated];
    }

    /** UTC datetime when a revision replaced at $created will be deleted. */
    public static function expires(string $created): string
    {
        return gmdate('Y-m-d H:i:s', self::time($created) + self::days() * 86400);
    }

    private static function clock(): int
    {
        return self::$now ?? time();
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s', self::clock());
    }

    private static function time(string $utc): int
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
