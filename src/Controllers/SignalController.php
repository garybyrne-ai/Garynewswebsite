<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Signal;
use MeNews\Support\Categories;
use MeNews\Support\Locations;
use MeNews\Support\Visitor;
use MeNews\View;

final class SignalController
{
    public static function page(Request $r): Response
    {
        $window = in_array($r->query('window', 'today', 10), ['today', 'week', 'rising'], true) ? $r->query('window', 'today', 10) : 'today';
        $county = $r->query('county', '', 40);
        if ($county !== '' && !in_array($county, Locations::countyNames(), true)) {
            $county = '';
        }
        $board = Signal::leaderboard($window, 30, $county);
        $user = Auth::user();
        foreach ($board as &$s) {
            $s['mine'] = Signal::myVote($s['id'], $user);
        }
        unset($s);
        return View::page('signal', [
            'user' => $user, 'nav' => Categories::NAV,
            'title' => 'The Signal — Ireland\'s most-voted stories — ME News Ireland',
            'description' => 'Readers vote on why a story matters. The Signal ranks them with a transparent, locality-aware algorithm.',
            'board' => $board, 'window' => $window, 'county' => $county, 'counties' => Locations::countyNames(),
            'stats' => Signal::stats(), 'signals' => Signal::SIGNALS, 'myCounty' => Visitor::locality(1)['county'] ?? null,
            'warmup' => count($board) < 3 ? Signal::featured(8, 'week') : [],
            'bodyClass' => 'page-signal',
        ]);
    }

    public static function board(Request $r): Response
    {
        $window = $r->query('window', 'today', 10);
        $rows = Signal::leaderboard(in_array($window, ['today', 'week', 'rising'], true) ? $window : 'today', $r->int('limit', 12, 1, 40), $r->query('county', '', 40));
        $user = Auth::user();
        foreach ($rows as &$s) {
            $s['mine'] = Signal::myVote($s['id'], $user);
        }
        return Response::json(['window' => $window, 'items' => $rows, 'signals' => Signal::SIGNALS, 'stats' => Signal::stats()]);
    }

    public static function story(Request $r, array $p): Response
    {
        $t = Signal::tally($p['id']);
        $t['mine'] = Signal::myVote($p['id'], Auth::user());
        return Response::json($t);
    }

    public static function vote(Request $r): Response
    {
        $user = Auth::user();
        if ($user && !$r->isFetch()) {
            throw new HttpException(403, 'Cross-site request blocked');
        }
        $storyId = $r->post('story_id', '', 40);
        $signal = $r->post('signal', '', 12);
        $county = Visitor::locality(1)['county'] ?? null;
        return Response::json(Signal::vote($storyId, $signal, $user, $county, $r->ip()));
    }
}
