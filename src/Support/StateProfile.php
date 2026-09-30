<?php
declare(strict_types=1);

namespace MeNews\Support;

/** Real per-state/UT "local touches": capital, official language, a signature festival and what the place is known for (config/states.json). */
final class StateProfile
{
    private static ?array $data = null;

    private static function data(): array
    {
        return self::$data ??= json_decode((string)file_get_contents(ME_ROOT . '/config/states.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{capital:string,language:string,festival:string,known_for:string}|null */
    public static function get(string $state): ?array
    {
        return self::data()[$state] ?? null;
    }
}
