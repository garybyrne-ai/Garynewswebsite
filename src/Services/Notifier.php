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

    /** In-app note to every editor and administrator. */
    public static function staff(string $type, string $title, ?string $storyId = null): void
    {
        foreach (Database::all("SELECT id FROM users WHERE role IN ('editor','admin')") as $u) {
            self::send($u['id'], $type, $title, '', $storyId);
        }
    }

    /** Tell a reporter what happened to their report: in-app for members, email for anyone with an address. */
    public static function reportStatus(array $story, string $status, string $note = ''): void
    {
        $title = match ($status) {
            'published' => 'Your report is live',
            'rejected' => 'About your report',
            'hold' => 'Your report is on hold',
            default => 'Your report is ' . $status,
        };
        self::send($story['author_user_id'] ?? null, 'report-status', $title, $note, $story['id']);
        $email = null;
        if (!empty($story['author_user_id'])) {
            $email = Database::value('SELECT email FROM users WHERE id=?', [$story['author_user_id']]) ?: null;
        } elseif (!empty($story['reporter_contact']) && filter_var($story['reporter_contact'], FILTER_VALIDATE_EMAIL)) {
            $email = $story['reporter_contact'];
        }
        if (!$email) {
            return;
        }
        $link = absolute_url('/story/' . $story['slug']);
        $body = match ($status) {
            'published' => '<p><b>' . e($story['title']) . '</b> is now published on ME News: <a href="' . e($link) . '">' . e($link) . '</a>.</p><p>Thank you for reporting. Neighbours can now add “I saw this too”; three confirmations earn a Corroborated label.</p>',
            'rejected' => '<p>We were not able to publish <b>' . e($story['title']) . '</b>.</p>' . ($note ? '<p>The editor wrote: ' . e($note) . '</p>' : '<p>Usually this is because we could not confirm it, or it named someone we cannot name. Reply to this email if you think we got it wrong.</p>'),
            default => '<p><b>' . e($story['title']) . '</b> is on hold while an editor checks something.</p>' . ($note ? '<p>' . e($note) . '</p>' : ''),
        };
        Mailer::send($email, $title . ' · ME News', $body);
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
