<?php
declare(strict_types=1);

use MeNews\Controllers\AccountController as Account;
use MeNews\Controllers\AdminController as Admin;
use MeNews\Controllers\ApiController as Api;
use MeNews\Controllers\KidsController as Kids;
use MeNews\Controllers\PageController as Page;
use MeNews\Http\Router;

return static function (Router $r): void {
    // ---- Pages
    $r->get('/', [Page::class, 'home']);
    $r->get('/section/{slug:[a-z-]+}', [Page::class, 'category']);
    $r->get('/county/{slug:[a-z-]+}', [Page::class, 'county']);
    $r->get('/story/{slug:[a-z0-9-]+}', [Page::class, 'story']);
    $r->get('/search', [Page::class, 'search']);
    $r->get('/map', [Page::class, 'map']);
    $r->get('/kids', [Kids::class, 'hub']);
    $r->get('/kids/crossword', [Kids::class, 'crossword']);
    $r->get('/kids/crossword/{date:[0-9]+-[0-9]+-[0-9]+}', [Kids::class, 'crossword']);
    $r->get('/kids/wordsearch', [Kids::class, 'wordsearch']);
    $r->get('/kids/wordsearch/{date:[0-9]+-[0-9]+-[0-9]+}', [Kids::class, 'wordsearch']);
    $r->get('/kids/quiz', [Kids::class, 'quiz']);
    $r->get('/kids/quiz/{date:[0-9]+-[0-9]+-[0-9]+}', [Kids::class, 'quiz']);
    $r->get('/kids/county-game', [Kids::class, 'county']);
    $r->get('/api/kids/crossword', [Kids::class, 'apiCrossword']);
    $r->get('/api/kids/wordsearch', [Kids::class, 'apiWordsearch']);
    $r->get('/api/kids/quiz', [Kids::class, 'apiQuiz']);
    $r->get('/api/kids/county', [Kids::class, 'apiCounty']);
    $r->get('/contributors', [Page::class, 'contributorsPage']);
    $r->get('/contributors/{handle:[a-z0-9-]+}', [Page::class, 'contributor']);
    $r->get('/about', [Page::class, 'about']);
    $r->get('/plus', [Page::class, 'plus']);
    $r->get('/dashboard', [Page::class, 'dashboard']);
    $r->get('/newsroom', [Page::class, 'newsroom']);
    $r->get('/admin', static fn() => \MeNews\Http\Response::redirect('/newsroom', 301));
    $r->get('/media/{id:[a-f0-9]+}', [Page::class, 'media']);
    $r->get('/sitemap.xml', [Page::class, 'sitemap']);
    $r->get('/feed.xml', [Page::class, 'rss']);

    // ---- Public API
    $r->get('/api/health', [Api::class, 'health']);
    $r->get('/api/counties', [Api::class, 'counties']);
    $r->get('/api/locations', [Api::class, 'locations']);
    $r->get('/api/categories', [Api::class, 'categories']);
    $r->get('/api/feed', [Api::class, 'feed']);
    $r->get('/api/story/{key:[a-z0-9-]+}', [Api::class, 'story']);
    $r->get('/api/map', [Api::class, 'map']);
    $r->get('/cron/wire', [Api::class, 'cronWire']);
    $r->get('/api/pulse', [Api::class, 'pulse']);
    $r->get('/api/ads', [Api::class, 'ads']);
    $r->get('/ads/{id:[a-f0-9]+}/go', [Api::class, 'adClick']);
    $r->get('/api/wire/status', [Api::class, 'wireStatus']);
    $r->post('/api/wire/refresh', [Api::class, 'wireRefresh']);

    // ---- Auth & account
    $r->post('/api/auth/register', [Account::class, 'register']);
    $r->post('/api/auth/login', [Account::class, 'login']);
    $r->post('/api/auth/logout', [Account::class, 'logout']);
    $r->get('/api/me', [Account::class, 'me']);
    $r->post('/api/me/profile', [Account::class, 'profile']);
    $r->post('/api/me/password', [Account::class, 'password']);
    $r->get('/api/me/reports', [Account::class, 'myReports']);
    $r->get('/api/me/notifications', [Account::class, 'notifications']);
    $r->post('/api/me/notifications/read', [Account::class, 'notificationsRead']);
    $r->get('/api/me/follows', [Account::class, 'follows']);
    $r->post('/api/me/follows', [Account::class, 'follow']);
    $r->delete('/api/me/follows/{id:\d+}', [Account::class, 'unfollow']);
    $r->get('/api/me/ads', [Account::class, 'myAds']);

    // ---- Community reporting & engagement
    $r->post('/api/report', [Account::class, 'report']);
    $r->post('/api/story/{id:[a-f0-9]+}/confirm', [Account::class, 'confirm']);
    $r->post('/api/story/{id:[a-f0-9]+}/comment', [Account::class, 'comment']);
    $r->post('/api/ads/submit', [Account::class, 'adSubmit']);

    // ---- Billing
    $r->get('/api/billing/status', [Account::class, 'billingStatus']);
    $r->post('/api/billing/checkout', [Account::class, 'checkout']);
    $r->post('/api/stripe/webhook', [Account::class, 'stripeWebhook']);

    // ---- Newsroom
    $r->get('/api/admin/summary', [Admin::class, 'summary']);
    $r->get('/api/admin/stories', [Admin::class, 'stories']);
    $r->get('/api/admin/stories/{id:[a-f0-9]+}/preview', [Admin::class, 'preview']);
    $r->post('/api/admin/stories/{id:[a-f0-9]+}/decision', [Admin::class, 'decision']);
    $r->post('/api/admin/stories/{id:[a-f0-9]+}/rerun-safety', [Admin::class, 'rerun']);
    $r->post('/api/admin/stories/{id:[a-f0-9]+}/edit', [Admin::class, 'edit']);
    $r->get('/api/admin/users', [Admin::class, 'users']);
    $r->post('/api/admin/users/{id:[a-f0-9]+}', [Admin::class, 'updateUser']);
    $r->get('/api/admin/comments', [Admin::class, 'comments']);
    $r->get('/api/admin/ads', [Admin::class, 'ads']);
    $r->post('/api/admin/{table:comments|ads}/{id:[a-zA-Z0-9-]+}', [Admin::class, 'itemDecision']);
    $r->post('/api/admin/locations/refresh', [Admin::class, 'refreshLocations']);
    $r->post('/api/admin/wire/refresh', [Admin::class, 'refreshWire']);
    $r->get('/api/admin/wire/runs', [Admin::class, 'wireRuns']);
    $r->get('/api/admin/audit', [Admin::class, 'audit']);
};
