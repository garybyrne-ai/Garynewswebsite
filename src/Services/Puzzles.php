<?php
declare(strict_types=1);

namespace MeNews\Services;

use MeNews\Config;
use MeNews\Support\Daily;
use MeNews\Support\Geo;
use MeNews\Support\Locations;
use MeNews\Support\Rng;

/**
 * Daily puzzles for ME Óg (the kids' section). Everything is generated deterministically from
 * the date, so every reader in Ireland gets the same puzzle, and cached on disk.
 */
final class Puzzles
{
    public const LEVELS = ['junior' => ['size' => 11, 'target' => 10, 'min' => 7, 'label' => 'Junior'], 'adult' => ['size' => 15, 'target' => 18, 'min' => 12, 'label' => 'Grown-up']];

    private static function bank(string $file): array
    {
        static $cache = [];
        return $cache[$file] ??= json_decode((string)file_get_contents(ME_ROOT . '/config/kids/' . $file), true) ?: [];
    }

    private static function cached(string $key, callable $build): array
    {
        $file = Config::storage() . '/cache/puzzle-' . preg_replace('/[^a-z0-9-]/', '', $key) . '.json';
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                return $data;
            }
        }
        $data = $build();
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $data;
    }

    // ------------------------------------------------------------------ crossword

    public static function crossword(string $date, string $level = 'junior'): array
    {
        $level = isset(self::LEVELS[$level]) ? $level : 'junior';
        return self::cached("crossword-{$date}-{$level}", static fn() => self::buildCrossword($date, $level));
    }

    private static function buildCrossword(string $date, string $level): array
    {
        $cfg = self::LEVELS[$level];
        $bank = array_values(array_filter(self::bank('crossword-words.json')['words'] ?? [], static fn($w) => $level === 'adult' || $w['level'] === 'junior'));
        $bank = array_values(array_filter($bank, static fn($w) => strlen($w['word']) <= $cfg['size'] - 2 && ($level === 'adult' || strlen($w['word']) <= 9)));
        $best = null;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $rng = Daily::rng("crossword-{$level}-{$attempt}", $date);
            $result = self::layout($rng->shuffle($bank), $cfg['size'], $cfg['target'], $rng);
            if ($best === null || count($result['placed']) > count($best['placed'])) {
                $best = $result;
            }
            if (count($result['placed']) >= $cfg['target']) {
                break;
            }
        }
        return self::finishCrossword($best, $date, $level);
    }

    /** Greedy crossword layout: place the first word, then keep adding words that cross existing letters. */
    private static function layout(array $words, int $n, int $target, Rng $rng): array
    {
        $grid = array_fill(0, $n, array_fill(0, $n, null));
        $placed = [];
        $used = [];
        // Seed with a long word across the middle.
        usort($words, static fn($a, $b) => strlen($b['word']) <=> strlen($a['word']));
        $first = $words[0];
        $row = intdiv($n, 2);
        $col = intdiv($n - strlen($first['word']), 2);
        self::put($grid, $first['word'], $row, $col, 'across');
        $placed[] = ['word' => $first['word'], 'clue' => $first['clue'], 'row' => $row, 'col' => $col, 'dir' => 'across'];
        $used[$first['word']] = true;
        for ($pass = 0; $pass < 4 && count($placed) < $target; $pass++) {
            foreach ($words as $entry) {
                if (count($placed) >= $target) {
                    break;
                }
                $w = $entry['word'];
                if (isset($used[$w])) {
                    continue;
                }
                $options = [];
                for ($r = 0; $r < $n; $r++) {
                    for ($c = 0; $c < $n; $c++) {
                        $letter = $grid[$r][$c];
                        if ($letter === null) {
                            continue;
                        }
                        for ($i = 0, $len = strlen($w); $i < $len; $i++) {
                            if ($w[$i] !== $letter) {
                                continue;
                            }
                            foreach ([['across', $r, $c - $i], ['down', $r - $i, $c]] as [$dir, $sr, $sc]) {
                                $score = self::fits($grid, $w, $sr, $sc, $dir, $n);
                                if ($score > 0) {
                                    $options[] = [$score, $sr, $sc, $dir];
                                }
                            }
                        }
                    }
                }
                if (!$options) {
                    continue;
                }
                usort($options, static fn($a, $b) => $b[0] <=> $a[0]);
                $top = array_filter($options, static fn($o) => $o[0] === $options[0][0]);
                [, $sr, $sc, $dir] = $rng->pick(array_values($top));
                self::put($grid, $w, $sr, $sc, $dir);
                $placed[] = ['word' => $w, 'clue' => $entry['clue'], 'row' => $sr, 'col' => $sc, 'dir' => $dir];
                $used[$w] = true;
            }
        }
        return ['grid' => $grid, 'placed' => $placed, 'n' => $n];
    }

    private static function put(array &$grid, string $w, int $r, int $c, string $dir): void
    {
        for ($i = 0, $len = strlen($w); $i < $len; $i++) {
            $grid[$dir === 'across' ? $r : $r + $i][$dir === 'across' ? $c + $i : $c] = $w[$i];
        }
    }

    /** Returns crossings count (>0) when the word can be placed legally, otherwise 0. */
    private static function fits(array $grid, string $w, int $r, int $c, string $dir, int $n): int
    {
        $len = strlen($w);
        $dr = $dir === 'down' ? 1 : 0;
        $dc = $dir === 'across' ? 1 : 0;
        if ($r < 0 || $c < 0 || $r + $dr * ($len - 1) >= $n || $c + $dc * ($len - 1) >= $n) {
            return 0;
        }
        // Cell before and after must be empty.
        if (self::cell($grid, $r - $dr, $c - $dc, $n) !== null || self::cell($grid, $r + $dr * $len, $c + $dc * $len, $n) !== null) {
            return 0;
        }
        $crossings = 0;
        for ($i = 0; $i < $len; $i++) {
            $rr = $r + $dr * $i;
            $cc = $c + $dc * $i;
            $existing = $grid[$rr][$cc];
            if ($existing !== null) {
                if ($existing !== $w[$i]) {
                    return 0;
                }
                $crossings++;
                continue;
            }
            // Empty cell: its side neighbours (perpendicular) must be empty to avoid accidental words.
            if (self::cell($grid, $rr + $dc, $cc + $dr, $n) !== null || self::cell($grid, $rr - $dc, $cc - $dr, $n) !== null) {
                return 0;
            }
        }
        return $crossings === $len ? 0 : $crossings;
    }

    private static function cell(array $grid, int $r, int $c, int $n): ?string
    {
        return ($r < 0 || $c < 0 || $r >= $n || $c >= $n) ? null : $grid[$r][$c];
    }

    private static function finishCrossword(array $lay, string $date, string $level): array
    {
        $grid = $lay['grid'];
        $n = $lay['n'];
        $minR = $n; $maxR = -1; $minC = $n; $maxC = -1;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($grid[$r][$c] !== null) {
                    $minR = min($minR, $r); $maxR = max($maxR, $r); $minC = min($minC, $c); $maxC = max($maxC, $c);
                }
            }
        }
        $rows = $maxR - $minR + 1;
        $cols = $maxC - $minC + 1;
        $cells = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $cells[$r][$c] = $grid[$r + $minR][$c + $minC];
            }
        }
        // Numbering
        $numbers = [];
        $num = 0;
        $across = [];
        $down = [];
        $byStart = [];
        foreach ($lay['placed'] as $p) {
            $byStart[$p['dir']][($p['row'] - $minR) . ',' . ($p['col'] - $minC)] = $p;
        }
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                if ($cells[$r][$c] === null) {
                    continue;
                }
                $startsAcross = isset($byStart['across']["$r,$c"]);
                $startsDown = isset($byStart['down']["$r,$c"]);
                if ($startsAcross || $startsDown) {
                    $num++;
                    $numbers["$r,$c"] = $num;
                    if ($startsAcross) {
                        $p = $byStart['across']["$r,$c"];
                        $across[] = ['n' => $num, 'clue' => $p['clue'], 'answer' => $p['word'], 'row' => $r, 'col' => $c, 'len' => strlen($p['word'])];
                    }
                    if ($startsDown) {
                        $p = $byStart['down']["$r,$c"];
                        $down[] = ['n' => $num, 'clue' => $p['clue'], 'answer' => $p['word'], 'row' => $r, 'col' => $c, 'len' => strlen($p['word'])];
                    }
                }
            }
        }
        return [
            'id' => "xw-{$date}-{$level}", 'date' => $date, 'level' => $level, 'label' => self::LEVELS[$level]['label'],
            'edition' => Daily::edition($date), 'rows' => $rows, 'cols' => $cols, 'cells' => $cells, 'numbers' => $numbers,
            'across' => $across, 'down' => $down, 'words' => count($lay['placed']),
        ];
    }

    // ------------------------------------------------------------------ word search

    public static function wordsearch(string $date): array
    {
        return self::cached("wordsearch-{$date}", static function () use ($date): array {
            $themes = self::bank('wordsearch.json')['themes'] ?? [];
            $rng = Daily::rng('wordsearch', $date);
            $names = array_keys($themes);
            $theme = $names[(int)(new \DateTimeImmutable($date))->format('z') % count($names)];
            $list = $rng->shuffle($themes[$theme]);
            $n = 12;
            $grid = array_fill(0, $n, array_fill(0, $n, null));
            $dirs = [[0, 1], [1, 0], [1, 1], [-1, 1]];
            $placed = [];
            foreach ($list as $w) {
                if (count($placed) >= 10 || strlen($w) > $n) {
                    continue;
                }
                for ($try = 0; $try < 60; $try++) {
                    [$dr, $dc] = $rng->pick($dirs);
                    $len = strlen($w);
                    $r = $rng->int($dr < 0 ? $len - 1 : 0, $dr > 0 ? $n - $len : $n - 1);
                    $c = $rng->int(0, $dc > 0 ? $n - $len : $n - 1);
                    $ok = true;
                    for ($i = 0; $i < $len; $i++) {
                        $cell = $grid[$r + $dr * $i][$c + $dc * $i];
                        if ($cell !== null && $cell !== $w[$i]) {
                            $ok = false;
                            break;
                        }
                    }
                    if (!$ok) {
                        continue;
                    }
                    for ($i = 0; $i < $len; $i++) {
                        $grid[$r + $dr * $i][$c + $dc * $i] = $w[$i];
                    }
                    $placed[] = ['word' => $w, 'row' => $r, 'col' => $c, 'dr' => $dr, 'dc' => $dc];
                    break;
                }
            }
            $letters = 'AAAABCDEEEEFGHIIILMNNOOOPRRSSTTUV';
            for ($r = 0; $r < $n; $r++) {
                for ($c = 0; $c < $n; $c++) {
                    $grid[$r][$c] ??= $letters[$rng->int(0, strlen($letters) - 1)];
                }
            }
            usort($placed, static fn($a, $b) => strcmp($a['word'], $b['word']));
            return ['id' => "ws-{$date}", 'date' => $date, 'theme' => $theme, 'size' => $n, 'grid' => $grid, 'words' => $placed];
        });
    }

    // ------------------------------------------------------------------ quiz

    public static function quiz(string $date): array
    {
        return self::cached("quiz-{$date}", static function () use ($date): array {
            $bank = self::bank('quiz.json')['questions'] ?? [];
            $rng = Daily::rng('quiz', $date);
            $pick = array_slice($rng->shuffle($bank), 0, 5);
            $questions = [];
            foreach ($pick as $i => $q) {
                $order = $rng->shuffle(range(0, count($q['options']) - 1));
                $options = array_map(static fn($k) => $q['options'][$k], $order);
                $questions[] = ['n' => $i + 1, 'q' => $q['q'], 'options' => $options, 'answer' => array_search($q['answer'], $order, true), 'fact' => $q['fact']];
            }
            return ['id' => "quiz-{$date}", 'date' => $date, 'questions' => $questions];
        });
    }

    // ------------------------------------------------------------------ find the county

    public static function countyGame(string $date): array
    {
        $rng = Daily::rng('county', $date);
        $counties = [];
        foreach ($rng->shuffle(Locations::countyNames()) as $c) {
            $pt = Geo::county($c);
            if ($pt) {
                $counties[] = ['county' => $c, 'province' => Locations::provinceFor($c), 'latitude' => $pt[0], 'longitude' => $pt[1]];
            }
            if (count($counties) >= 10) {
                break;
            }
        }
        return ['id' => "county-{$date}", 'date' => $date, 'rounds' => $counties];
    }

    /** Recent dates for the puzzle archive (newest first). */
    public static function archive(int $days = 14): array
    {
        $out = [];
        $today = new \DateTimeImmutable(Daily::date(), Daily::zone());
        $start = new \DateTimeImmutable('2026-09-01', Daily::zone());
        for ($i = 0; $i < $days; $i++) {
            $d = $today->modify("-{$i} days");
            if ($d < $start) {
                break;
            }
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }
}
