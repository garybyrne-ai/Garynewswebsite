<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Puzzles;
use MeNews\Stories;
use MeNews\Support\Categories;
use MeNews\Support\Daily;
use MeNews\View;

/** ME Óg — the kids' section: daily puzzles, Irish word of the day and free things to do. */
final class KidsController
{
    /** Stories a young reader can enjoy: What's On, Culture and Sport, minus anything grim. */
    private const BLOCK = '/\b(dies?|died|death|dead|killed?|kill|murder|crash|collision|assault|rape|abuse|drugs?|cocaine|court|jail|prison|gardaí|garda|stab|shoot|shot|gun|knife|body|missing|injur|hospital|fatal|war|bomb|attack|threat|scam|fraud|suicide|overdose|arrest|charged|guilty|victim|racis|misogyn|cancer|tragedy|tragic|drown|drumcree|orange order|parade permission|protest|controvers|sectarian|loyalist|paramilitar|politic|election|minister|government|tax|strike|row over|anger|outrage|slam|blast|fury|feud|dispute|ban|lawsuit|sue|divorce|split|cheat|scandal|betting|gambl|alcohol|drink|vape|smok)/iu';

    public static function youngReaders(int $limit = 6): array
    {
        $out = [];
        foreach (["What's On", 'Culture', 'Sport'] as $cat) {
            foreach (Stories::feed(['category' => $cat, 'with_image' => true], 20) as $s) {
                if (!preg_match(self::BLOCK, $s['title'] . ' ' . ($s['summary'] ?? ''))) {
                    $out[] = $s;
                }
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    private static function base(array $extra): array
    {
        return [
            'user' => Auth::user(), 'nav' => Categories::NAV, 'bodyClass' => 'page-kids',
            'today' => Daily::date(), 'edition' => Daily::edition(), 'longDate' => Daily::longDate(), 'focal' => Daily::focal(),
        ] + $extra;
    }

    private static function date(Request $r, array $p = []): string
    {
        $d = Daily::validDate($p['date'] ?? $r->query('date', '', 10)) ?? Daily::date();
        if ($d > Daily::date()) {
            throw new HttpException(404, "That edition hasn't been printed yet.");
        }
        return $d;
    }

    public static function hub(Request $r): Response
    {
        $today = Daily::date();
        return View::page('kids', self::base([
            'title' => 'ME Óg — kids, puzzles and free things to do — ME News Ireland',
            'description' => "Daily crossword, word search, Ireland quiz, the Irish word of the day and free things for kids across Ireland.",
            'crossword' => Puzzles::crossword($today, 'junior'),
            'wordsearch' => Puzzles::wordsearch($today),
            'quiz' => Puzzles::quiz($today),
            'stories' => self::youngReaders(6),
            'stuff' => json_decode((string)file_get_contents(ME_ROOT . '/config/kids/free-stuff.json'), true)['items'] ?? [],
        ]));
    }

    public static function crossword(Request $r, array $p = []): Response
    {
        $date = self::date($r, $p);
        $level = $r->query('level', 'junior', 10) === 'adult' ? 'adult' : 'junior';
        $puzzle = Puzzles::crossword($date, $level);
        return View::page('kids-crossword', self::base([
            'title' => 'Daily crossword No. ' . $puzzle['edition'] . ' (' . $puzzle['label'] . ') — ME Óg',
            'description' => 'A new Irish-flavoured crossword every day, in junior and grown-up sizes.',
            'puzzle' => $puzzle, 'date' => $date, 'level' => $level, 'levels' => Puzzles::LEVELS, 'archive' => Puzzles::archive(14),
        ]));
    }

    public static function wordsearch(Request $r, array $p = []): Response
    {
        $date = self::date($r, $p);
        $puzzle = Puzzles::wordsearch($date);
        return View::page('kids-wordsearch', self::base([
            'title' => 'Daily word search: ' . $puzzle['theme'] . ' — ME Óg',
            'description' => 'Find ten hidden Irish words in today\'s word search.',
            'puzzle' => $puzzle, 'date' => $date, 'archive' => Puzzles::archive(14),
        ]));
    }

    public static function quiz(Request $r, array $p = []): Response
    {
        $date = self::date($r, $p);
        return View::page('kids-quiz', self::base([
            'title' => 'Know Your Ireland quiz — ME Óg',
            'description' => 'Five questions about Ireland every day.',
            'quiz' => Puzzles::quiz($date), 'date' => $date, 'archive' => Puzzles::archive(14),
        ]));
    }

    public static function county(Request $r): Response
    {
        return View::page('kids-county', self::base([
            'title' => 'Find the County — ME Óg',
            'description' => 'Can you find all 26 counties on the map?',
            'game' => Puzzles::countyGame(Daily::date()),
        ]));
    }

    // JSON for the interactive pieces
    public static function apiCrossword(Request $r): Response
    {
        return Response::json(Puzzles::crossword(self::date($r), $r->query('level', 'junior', 10) === 'adult' ? 'adult' : 'junior'));
    }

    public static function apiWordsearch(Request $r): Response
    {
        return Response::json(Puzzles::wordsearch(self::date($r)));
    }

    public static function apiQuiz(Request $r): Response
    {
        return Response::json(Puzzles::quiz(self::date($r)));
    }

    public static function apiCounty(Request $r): Response
    {
        return Response::json(Puzzles::countyGame(self::date($r)));
    }
}
