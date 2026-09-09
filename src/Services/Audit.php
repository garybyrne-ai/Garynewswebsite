<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;

final class Audit
{
    public static function log(?string $userId, string $action, string $type = '', string $id = '', string $detail = ''): void
    {
        Database::insert('audit_log', [
            'created_at' => now(), 'user_id' => $userId, 'action' => $action,
            'entity_type' => $type, 'entity_id' => $id, 'detail' => mb_substr($detail, 0, 500),
        ]);
    }
}
