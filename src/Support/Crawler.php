<?php
declare(strict_types=1);

namespace MeNews\Support;

/** Who is reading: a person, a search engine, or an AI assistant fetching the page. */
final class Crawler
{
    /** Indexing and AI-answer crawlers that must see the whole article, never a teaser. */
    private const BOTS = [
        'googlebot', 'google-inspectiontool', 'storebot-google', 'googleother', 'google-extended',
        'bingbot', 'bingpreview', 'msnbot', 'adidxbot',
        'duckduckbot', 'yandexbot', 'baiduspider', 'slurp', 'applebot',
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'perplexitybot', 'perplexity-user',
        'claudebot', 'claude-web', 'anthropic-ai', 'ccbot', 'cohere-ai', 'meta-externalagent',
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot', 'slackbot', 'discordbot',
    ];

    public static function agent(): string
    {
        return mb_strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /** True for a search or AI crawler. Flexible sampling: they get the full text, people get the gate. */
    public static function isBot(?string $agent = null): bool
    {
        $ua = mb_strtolower($agent ?? self::agent());
        if ($ua === '') {
            return false;
        }
        foreach (self::BOTS as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }
        return false;
    }
}
