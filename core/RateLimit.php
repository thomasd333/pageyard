<?php

namespace Pageyard;

use Pageyard\Database\Db;

defined('PAGEYARD') or exit;

/** Counts uses of a public action per key (IP, user). Windows are at most a day long. */
final class RateLimit
{
    /** True when the action may go ahead, and counts it; false when the limit is reached. */
    public static function hit(string $action, string $key, int $limit, int $minutes): bool
    {
        $db = Db::get();
        $db->query('DELETE FROM #__rate_limits WHERE created < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $hash = hash('sha256', Config::get('secret', '') . "|$action|$key");
        $used = (int) $db->fetchValue('SELECT COUNT(*) FROM #__rate_limits WHERE action = ? AND key_hash = ? AND created >= ?',
            [$action, $hash, gmdate('Y-m-d H:i:s', time() - $minutes * 60)]);
        if ($used >= $limit) {
            return false;
        }
        $db->insert('#__rate_limits', ['action' => $action, 'key_hash' => $hash, 'created' => Db::now()]);
        return true;
    }
}
