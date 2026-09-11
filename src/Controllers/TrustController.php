<?php
declare(strict_types=1);

namespace MeNews\Controllers;

use MeNews\Auth;
use MeNews\Database;
use MeNews\Http\HttpException;
use MeNews\Http\Request;
use MeNews\Http\Response;
use MeNews\Services\Audit;
use MeNews\Services\Mailer;
use MeNews\Services\RateLimiter;
use MeNews\Support\Categories;
use MeNews\View;

/** Corrections, ownership, privacy, moderation policy and the takedown route. */
final class TrustController
{
    private static function base(array $extra = []): array
    {
        return ['user' => Auth::user(), 'nav' => Categories::NAV, 'wireLast' => \MeNews\Services\NewsWire::lastRefresh()] + $extra;
    }

    public static function corrections(Request $r): Response
    {
        return View::page('corrections', self::base([
            'title' => 'Corrections policy & log — ME News Ireland',
            'description' => 'How ME News corrects mistakes, and a public log of every correction we have made.',
            'log' => Database::all('SELECT c.*, s.slug, s.title AS story_title FROM corrections c LEFT JOIN stories s ON s.id=c.story_id ORDER BY c.created_at DESC LIMIT 200'),
        ]));
    }

    public static function ownership(Request $r): Response
    {
        return View::page('ownership', self::base([
            'title' => 'Who owns ME News — ownership, funding and editors',
            'description' => 'Who owns ME News Ireland, how it is funded, who the editors are and how to reach them.',
            'editors' => Database::all("SELECT display_name,handle,title,desk,home_county FROM users WHERE role IN ('editor','admin','contributor') ORDER BY role='admin' DESC, created_at"),
            'owner' => Database::setting('owner_name', 'Gary Byrne'),
            'company' => Database::setting('owner_company', 'ME News Ireland'),
            'contact' => Database::setting('contact_email', \MeNews\Config::get('MAIL_REPLY_TO', Mailer::from())),
            'address' => Database::setting('owner_address', 'Ireland'),
        ]));
    }

    public static function privacy(Request $r): Response
    {
        return View::page('privacy', self::base([
            'title' => 'Privacy & cookies — ME News Ireland',
            'description' => 'What ME News stores, which cookies it sets, how location and advertising work, and your rights under GDPR.',
            'contact' => Database::setting('contact_email', \MeNews\Config::get('MAIL_REPLY_TO', Mailer::from())),
        ]));
    }

    public static function moderation(Request $r): Response
    {
        return View::page('moderation', self::base([
            'title' => 'Moderation policy & takedowns — ME News Ireland',
            'description' => 'How community reports and comments are screened, our no-naming rule, and how to ask for something to be removed.',
        ]));
    }

    /** Notice-and-action route (Digital Services Act): anyone can flag content for removal. */
    public static function takedown(Request $r): Response
    {
        RateLimiter::hit($r->ip() . '|takedown', 10, 3600, 'Too many requests from this connection.');
        $url = $r->post('url', '', 400);
        $reason = $r->post('reason', '', 60);
        $contact = $r->post('contact', '', 200);
        if (!preg_match('~^https?://~', $url) && !str_starts_with($url, '/')) {
            throw new HttpException(400, 'Paste the address of the page');
        }
        if (!in_array($reason, ['defamation', 'privacy', 'copyright', 'minor', 'illegal', 'inaccurate', 'other'], true)) {
            throw new HttpException(400, 'Choose a reason');
        }
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException(400, 'Enter an email so we can reply');
        }
        Database::insert('takedowns', ['created_at' => now(), 'url' => $url, 'reason' => $reason, 'detail' => $r->post('detail', '', 3000), 'contact' => $contact]);
        $admin = Database::setting('contact_email', \MeNews\Config::get('MAIL_REPLY_TO', ''));
        if ($admin) {
            Mailer::send($admin, 'Takedown request: ' . $reason, '<p><b>' . e($url) . '</b></p><p>' . e($reason) . ' — from ' . e($contact) . '</p><p>' . nl2br(e($r->post('detail', '', 3000))) . '</p><p><a href="' . e(absolute_url('/newsroom')) . '">Open the newsroom</a></p>');
        }
        Mailer::send($contact, 'We received your request', '<p>Thanks. We aim to respond to removal requests within two working days. Content that names an unconvicted person in connection with a crime, or identifies a minor, is taken down first and reviewed after.</p>');
        return Response::json(['ok' => true, 'message' => 'Received. We aim to respond within two working days.']);
    }

    // --------------------------------------------------------- newsroom endpoints

    public static function adminCorrections(Request $r): Response
    {
        Auth::require(['editor', 'admin']);
        return Response::json(Database::all('SELECT c.*, s.slug, s.title AS story_title, u.display_name AS editor FROM corrections c LEFT JOIN stories s ON s.id=c.story_id LEFT JOIN users u ON u.id=c.editor_id ORDER BY c.created_at DESC LIMIT 200'));
    }

    public static function addCorrection(Request $r): Response
    {
        $u = Auth::require(['editor', 'admin']);
        $title = $r->post('title', '', 200);
        $summary = $r->post('summary', '', 600);
        if (mb_strlen($title) < 3 || mb_strlen($summary) < 5) {
            throw new HttpException(400, 'Add a title and a summary of what changed');
        }
        $storyId = $r->post('story_id', '', 40) ?: null;
        Database::insert('corrections', ['created_at' => now(), 'story_id' => $storyId, 'title' => $title, 'summary' => $summary, 'detail' => $r->post('detail', '', 3000), 'editor_id' => $u['id']]);
        if ($storyId) {
            Database::query('UPDATE stories SET editorial_note=COALESCE(editorial_note,\'\') || ? , updated_at=? WHERE id=?', [(Database::value('SELECT editorial_note FROM stories WHERE id=?', [$storyId]) ? "\n" : '') . 'Correction (' . date_irish(now(), 'j M Y') . '): ' . $summary, now(), $storyId]);
        }
        Audit::log($u['id'], 'correction.add', 'story', $storyId ?? '', $title);
        return Response::json(['ok' => true]);
    }

    public static function adminTakedowns(Request $r): Response
    {
        Auth::require(['editor', 'admin']);
        return Response::json(Database::all('SELECT * FROM takedowns ORDER BY status=\'open\' DESC, created_at DESC LIMIT 200'));
    }

    public static function takedownDecision(Request $r, array $p): Response
    {
        $u = Auth::require(['editor', 'admin']);
        $status = $r->post('status', '', 20);
        if (!in_array($status, ['actioned', 'declined', 'open'], true)) {
            throw new HttpException(400, 'Invalid status');
        }
        Database::query('UPDATE takedowns SET status=?,note=?,handled_at=? WHERE id=?', [$status, $r->post('note', '', 1000), now(), (int)$p['id']]);
        Audit::log($u['id'], 'takedown.' . $status, 'takedown', $p['id']);
        return Response::json(['ok' => true]);
    }
}
