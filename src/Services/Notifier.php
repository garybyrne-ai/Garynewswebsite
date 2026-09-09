<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;

final class Notifier
{
    public static function send(?string $userId, string $type, string $title, string $body = '', ?string $storyId = null): void
    {
        if (!$userId) {
            return;
        }
        Database::insert('notifications', [
            'user_id' => $userId, 'created_at' => now(), 'type' => $type,
            'title' => $title, 'body' => $body, 'story_id' => $storyId,
        ]);
    }

    /** Alert members who follow the story's town or county (or call it home). */
    public static function localAlerts(array $story): void
    {
        $loc = (string)($story['location_name'] ?? '');
        $county = (string)($story['county'] ?? '');
        if ($loc === '' && $county === '') {
            return;
        }
        $rows = Database::all(
            "SELECT DISTINCT u.id FROM users u LEFT JOIN follows f ON f.user_id=u.id
             WHERE u.id<>? AND (
               (?<>'' AND lower(COALESCE(u.home_town,''))=lower(?)) OR
               (?<>'' AND lower(COALESCE(f.location_name,''))=lower(?)) OR
               (?<>'' AND lower(COALESCE(f.county,''))=lower(?)))",
            [$story['author_user_id'] ?? '', $loc, $loc, $loc, $loc, $county, $county]
        );
        foreach ($rows as $u) {
            self::send($u['id'], 'local-alert', 'New report in ' . ($loc ?: $county), (string)$story['title'], $story['id']);
        }
    }
}
