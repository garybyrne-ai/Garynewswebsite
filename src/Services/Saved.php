<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Stories;

/** Saved stories: every member has a default "Saved" list and can make up to 20 more. */
final class Saved
{
    public const MAX_LISTS = 20;
    public const MAX_ITEMS = 500;

    public static function lists(string $userId): array
    {
        self::ensureDefault($userId);
        return Database::all('SELECT l.*, (SELECT COUNT(*) FROM saved_items i WHERE i.list_id=l.id) AS count FROM saved_lists l WHERE l.user_id=? ORDER BY l.is_default DESC, l.name COLLATE NOCASE', [$userId]);
    }

    public static function ensureDefault(string $userId): array
    {
        $row = Database::one('SELECT * FROM saved_lists WHERE user_id=? AND is_default=1', [$userId]);
        if ($row) {
            return $row;
        }
        $id = uuid();
        Database::insert('saved_lists', ['id' => $id, 'user_id' => $userId, 'name' => 'Saved', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return Database::one('SELECT * FROM saved_lists WHERE id=?', [$id]);
    }

    public static function find(string $userId, string $listId): array
    {
        $row = Database::one('SELECT * FROM saved_lists WHERE id=? AND user_id=?', [$listId, $userId]);
        if (!$row) {
            throw new HttpException(404, 'List not found');
        }
        return $row;
    }

    public static function createList(string $userId, string $name): array
    {
        $name = mb_substr(trim($name), 0, 60);
        if ($name === '') {
            throw new HttpException(400, 'Give the list a name');
        }
        self::ensureDefault($userId);
        if ((int)Database::value('SELECT COUNT(*) FROM saved_lists WHERE user_id=?', [$userId]) >= self::MAX_LISTS) {
            throw new HttpException(400, 'You can have up to ' . self::MAX_LISTS . ' lists');
        }
        if (Database::one('SELECT id FROM saved_lists WHERE user_id=? AND name=? COLLATE NOCASE', [$userId, $name])) {
            throw new HttpException(409, 'You already have a list called ' . $name);
        }
        $id = uuid();
        Database::insert('saved_lists', ['id' => $id, 'user_id' => $userId, 'name' => $name, 'is_default' => 0, 'created_at' => now(), 'updated_at' => now()]);
        return Database::one('SELECT * FROM saved_lists WHERE id=?', [$id]);
    }

    public static function renameList(string $userId, string $listId, string $name): void
    {
        self::find($userId, $listId);
        $name = mb_substr(trim($name), 0, 60);
        if ($name === '') {
            throw new HttpException(400, 'Give the list a name');
        }
        Database::query('UPDATE saved_lists SET name=?, updated_at=? WHERE id=?', [$name, now(), $listId]);
    }

    public static function deleteList(string $userId, string $listId): void
    {
        $l = self::find($userId, $listId);
        if ((int)$l['is_default'] === 1) {
            throw new HttpException(400, 'The main Saved list cannot be deleted — clear it instead');
        }
        Database::query('DELETE FROM saved_lists WHERE id=?', [$listId]);
    }

    /** Save a story to a list (default list when none given). Returns whether it is saved afterwards. */
    public static function save(string $userId, string $storyId, ?string $listId = null): array
    {
        if (!Stories::byId($storyId)) {
            throw new HttpException(404, 'Story not found');
        }
        $list = $listId ? self::find($userId, $listId) : self::ensureDefault($userId);
        if ((int)Database::value('SELECT COUNT(*) FROM saved_items WHERE list_id=?', [$list['id']]) >= self::MAX_ITEMS) {
            throw new HttpException(400, 'That list is full (' . self::MAX_ITEMS . ' stories)');
        }
        Database::query('INSERT OR IGNORE INTO saved_items(list_id,story_id,created_at) VALUES(?,?,?)', [$list['id'], $storyId, now()]);
        Database::query('UPDATE saved_lists SET updated_at=? WHERE id=?', [now(), $list['id']]);
        return ['saved' => true, 'list' => $list];
    }

    /** Remove a story from one list, or from every list when none is given. */
    public static function unsave(string $userId, string $storyId, ?string $listId = null): void
    {
        if ($listId) {
            self::find($userId, $listId);
            Database::query('DELETE FROM saved_items WHERE list_id=? AND story_id=?', [$listId, $storyId]);
        } else {
            Database::query('DELETE FROM saved_items WHERE story_id=? AND list_id IN (SELECT id FROM saved_lists WHERE user_id=?)', [$storyId, $userId]);
        }
    }

    public static function toggle(string $userId, string $storyId, ?string $listId = null): array
    {
        $saved = self::isSaved($userId, $storyId, $listId);
        if ($saved) {
            self::unsave($userId, $storyId, $listId);
            return ['saved' => false, 'lists' => self::listsFor($userId, $storyId)];
        }
        $out = self::save($userId, $storyId, $listId);
        return ['saved' => true, 'list' => $out['list'], 'lists' => self::listsFor($userId, $storyId)];
    }

    public static function isSaved(string $userId, string $storyId, ?string $listId = null): bool
    {
        return $listId
            ? (bool)Database::value('SELECT 1 FROM saved_items WHERE list_id=? AND story_id=?', [$listId, $storyId])
            : (bool)Database::value('SELECT 1 FROM saved_items i JOIN saved_lists l ON l.id=i.list_id WHERE l.user_id=? AND i.story_id=? LIMIT 1', [$userId, $storyId]);
    }

    /** Which of the member's lists hold this story. */
    public static function listsFor(string $userId, string $storyId): array
    {
        return array_column(Database::all('SELECT l.id FROM saved_items i JOIN saved_lists l ON l.id=i.list_id WHERE l.user_id=? AND i.story_id=?', [$userId, $storyId]), 'id');
    }

    /** Subset of the given story ids that the member has saved anywhere. */
    public static function savedIds(string $userId, array $storyIds): array
    {
        $ids = array_values(array_filter(array_unique(array_map('strval', $storyIds)), static fn($v) => preg_match('/^[a-f0-9]{8,40}$/', $v) === 1));
        if (!$ids) {
            return [];
        }
        $ids = array_slice($ids, 0, 200);
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_column(Database::all("SELECT DISTINCT i.story_id FROM saved_items i JOIN saved_lists l ON l.id=i.list_id WHERE l.user_id=? AND i.story_id IN ($in)", array_merge([$userId], $ids)), 'story_id');
    }

    public static function items(string $userId, string $listId): array
    {
        self::find($userId, $listId);
        $rows = Database::all('SELECT i.id AS item_id, i.story_id, i.created_at AS saved_at FROM saved_items i WHERE i.list_id=? ORDER BY i.created_at DESC LIMIT ' . self::MAX_ITEMS, [$listId]);
        $out = [];
        foreach ($rows as $r) {
            $story = Stories::byId($r['story_id']);
            if ($story) {
                $out[] = ['item_id' => (int)$r['item_id'], 'saved_at' => $r['saved_at'], 'story' => $story];
            }
        }
        return $out;
    }

    public static function removeItem(string $userId, int $itemId): void
    {
        Database::query('DELETE FROM saved_items WHERE id=? AND list_id IN (SELECT id FROM saved_lists WHERE user_id=?)', [$itemId, $userId]);
    }
}
