<?php
declare(strict_types=1);

namespace MeNews\Support;

/**
 * Self-hosted sharing. No third-party widget, no tracking script, no extra network requests:
 * every button is a plain link built from the page's own URL and headline, so it costs nothing
 * to load and cannot follow our readers around the web.
 *
 * Placeholders in each template: {url} {title} {text} {image} {host}. All values are URL-encoded.
 */
final class Share
{
    /** Shown inline on the page, in this order. The rest live behind "More". */
    public const PRIMARY = ['whatsapp', 'facebook', 'x', 'email', 'copy'];

    /**
     * network => [label, colour, url (null = handled in the browser), icon, mobile?]
     * "mobile" entries only appear on touch devices, where their app link works.
     */
    public static function networks(): array
    {
        $i = self::icons();
        return [
            'whatsapp' => ['label' => 'WhatsApp', 'colour' => '#25D366', 'url' => 'https://api.whatsapp.com/send?text={title}%20{url}', 'icon' => $i['whatsapp']],
            'facebook' => ['label' => 'Facebook', 'colour' => '#1877F2', 'url' => 'https://www.facebook.com/sharer/sharer.php?u={url}', 'icon' => $i['facebook']],
            'x' => ['label' => 'X', 'colour' => '#000000', 'url' => 'https://twitter.com/intent/tweet?url={url}&text={title}', 'icon' => $i['x']],
            'email' => ['label' => 'Email', 'colour' => '#0f8a52', 'url' => 'mailto:?subject={title}&body={text}%0A%0A{url}', 'icon' => $i['email']],
            'copy' => ['label' => 'Copy link', 'colour' => '#0f8a52', 'url' => null, 'icon' => $i['copy']],
            'native' => ['label' => 'Share…', 'colour' => '#0f8a52', 'url' => null, 'icon' => $i['share'], 'mobile' => true],

            'messenger' => ['label' => 'Messenger', 'colour' => '#0084FF', 'url' => 'fb-messenger://share/?link={url}', 'icon' => $i['messenger'], 'mobile' => true],
            'telegram' => ['label' => 'Telegram', 'colour' => '#26A5E4', 'url' => 'https://t.me/share/url?url={url}&text={title}', 'icon' => $i['telegram']],
            'linkedin' => ['label' => 'LinkedIn', 'colour' => '#0A66C2', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}', 'icon' => $i['linkedin']],
            'reddit' => ['label' => 'Reddit', 'colour' => '#FF4500', 'url' => 'https://www.reddit.com/submit?url={url}&title={title}', 'icon' => $i['reddit']],
            'bluesky' => ['label' => 'Bluesky', 'colour' => '#0285FF', 'url' => 'https://bsky.app/intent/compose?text={title}%20{url}', 'icon' => $i['bluesky']],
            'threads' => ['label' => 'Threads', 'colour' => '#000000', 'url' => 'https://www.threads.net/intent/post?text={title}%20{url}', 'icon' => $i['threads']],
            'mastodon' => ['label' => 'Mastodon', 'colour' => '#6364FF', 'url' => 'https://mastodonshare.com/?text={title}&url={url}', 'icon' => $i['mastodon']],
            'pinterest' => ['label' => 'Pinterest', 'colour' => '#BD081C', 'url' => 'https://pinterest.com/pin/create/button/?url={url}&media={image}&description={title}', 'icon' => $i['pinterest']],
            'viber' => ['label' => 'Viber', 'colour' => '#7360F2', 'url' => 'viber://forward?text={title}%20{url}', 'icon' => $i['viber'], 'mobile' => true],
            'sms' => ['label' => 'Text message', 'colour' => '#12a06a', 'url' => 'sms:?&body={title}%20{url}', 'icon' => $i['sms'], 'mobile' => true],
            'line' => ['label' => 'LINE', 'colour' => '#06C755', 'url' => 'https://social-plugins.line.me/lineit/share?url={url}', 'icon' => $i['line']],
            'skype' => ['label' => 'Skype', 'colour' => '#00AFF0', 'url' => 'https://web.skype.com/share?url={url}&text={title}', 'icon' => $i['skype']],
            'snapchat' => ['label' => 'Snapchat', 'colour' => '#FFFC00', 'url' => 'https://www.snapchat.com/scan?attachmentUrl={url}', 'icon' => $i['snapchat']],
            'tumblr' => ['label' => 'Tumblr', 'colour' => '#36465D', 'url' => 'https://www.tumblr.com/widgets/share/tool?canonicalUrl={url}&title={title}', 'icon' => $i['tumblr']],
            'pocket' => ['label' => 'Pocket', 'colour' => '#EF3F56', 'url' => 'https://getpocket.com/edit?url={url}&title={title}', 'icon' => $i['pocket']],
            'flipboard' => ['label' => 'Flipboard', 'colour' => '#E12828', 'url' => 'https://share.flipboard.com/bookmarklet/popout?v=2&url={url}&title={title}', 'icon' => $i['flipboard']],
            'hackernews' => ['label' => 'Hacker News', 'colour' => '#FF6600', 'url' => 'https://news.ycombinator.com/submitlink?u={url}&t={title}', 'icon' => $i['hackernews']],
            'digg' => ['label' => 'Digg', 'colour' => '#1B1B1B', 'url' => 'https://digg.com/submit?url={url}&title={title}', 'icon' => $i['digg']],
            'buffer' => ['label' => 'Buffer', 'colour' => '#231F20', 'url' => 'https://buffer.com/add?text={title}&url={url}', 'icon' => $i['buffer']],
            'instapaper' => ['label' => 'Instapaper', 'colour' => '#1F1F1F', 'url' => 'https://www.instapaper.com/edit?url={url}&title={title}', 'icon' => $i['instapaper']],
            'evernote' => ['label' => 'Evernote', 'colour' => '#00A82D', 'url' => 'https://www.evernote.com/clip.action?url={url}&title={title}', 'icon' => $i['evernote']],
            'classroom' => ['label' => 'Google Classroom', 'colour' => '#0F9D58', 'url' => 'https://classroom.google.com/share?url={url}&title={title}', 'icon' => $i['classroom']],
            'xing' => ['label' => 'XING', 'colour' => '#026466', 'url' => 'https://www.xing.com/spi/shares/new?url={url}', 'icon' => $i['xing']],
            'vk' => ['label' => 'VK', 'colour' => '#0077FF', 'url' => 'https://vk.com/share.php?url={url}&title={title}&image={image}', 'icon' => $i['vk']],
            'ok' => ['label' => 'OK', 'colour' => '#EE8208', 'url' => 'https://connect.ok.ru/offer?url={url}&title={title}', 'icon' => $i['ok']],
            'weibo' => ['label' => 'Weibo', 'colour' => '#E6162D', 'url' => 'https://service.weibo.com/share/share.php?url={url}&title={title}', 'icon' => $i['weibo']],
            'qq' => ['label' => 'QQ', 'colour' => '#1296DB', 'url' => 'https://connect.qq.com/widget/shareqq/index.html?url={url}&title={title}', 'icon' => $i['qq']],
            'douban' => ['label' => 'Douban', 'colour' => '#2D963D', 'url' => 'https://www.douban.com/share/service?href={url}&name={title}', 'icon' => $i['douban']],
            'print' => ['label' => 'Print', 'colour' => '#33423a', 'url' => null, 'icon' => $i['print']],
        ];
    }

    /** Build one share URL with the page's details filled in. */
    public static function link(string $network, array $ctx): ?string
    {
        $net = self::networks()[$network] ?? null;
        if (!$net || $net['url'] === null) {
            return null;
        }
        $url = (string)($ctx['url'] ?? '');
        return strtr($net['url'], [
            '{url}' => rawurlencode($url),
            '{title}' => rawurlencode((string)($ctx['title'] ?? '')),
            '{text}' => rawurlencode((string)($ctx['text'] ?? $ctx['title'] ?? '')),
            '{image}' => rawurlencode((string)($ctx['image'] ?? '')),
            '{host}' => rawurlencode((string)parse_url($url, PHP_URL_HOST)),
        ]);
    }

    /** What the browser needs to build the rest of the list in the "More" sheet. */
    public static function jsPayload(): array
    {
        $out = [];
        foreach (self::networks() as $key => $n) {
            $out[$key] = ['label' => $n['label'], 'colour' => $n['colour'], 'url' => $n['url'], 'icon' => $n['icon'], 'mobile' => !empty($n['mobile'])];
        }
        return $out;
    }

    /**
     * The inline bar. $ctx: url (absolute), title, text, image.
     * $variant: 'row' (default) or 'compact'.
     */
    public static function bar(array $ctx, string $variant = 'row'): string
    {
        $nets = self::networks();
        $data = 'data-share-url="' . e((string)$ctx['url']) . '" data-share-title="' . e((string)($ctx['title'] ?? '')) . '" data-share-text="' . e((string)($ctx['text'] ?? '')) . '" data-share-image="' . e((string)($ctx['image'] ?? '')) . '"';
        $html = '<div class="sharebar sharebar--' . e($variant) . '" ' . $data . ' role="group" aria-label="Share this">';
        $html .= '<span class="sharebar__label mono">' . e(t('Share')) . '</span>';
        $html .= '<button class="sharetile sharetile--native" type="button" data-share-do="native" title="' . e(t('Share')) . '" aria-label="' . e(t('Share')) . '" style="--b:#0f8a52" hidden>' . $nets['native']['icon'] . '</button>';
        foreach (self::PRIMARY as $key) {
            $n = $nets[$key];
            $link = self::link($key, $ctx);
            $html .= $link
                ? '<a class="sharetile" href="' . e($link) . '" target="_blank" rel="noopener nofollow" data-share-do="' . e($key) . '" title="' . e($n['label']) . '" aria-label="' . e($n['label']) . '" style="--b:' . e($n['colour']) . '">' . $n['icon'] . '</a>'
                : '<button class="sharetile" type="button" data-share-do="' . e($key) . '" title="' . e($n['label']) . '" aria-label="' . e($n['label']) . '" style="--b:' . e($n['colour']) . '">' . $n['icon'] . '</button>';
        }
        $html .= '<button class="sharetile sharetile--more" type="button" data-share-more title="' . e(t('More ways to share')) . '" aria-label="' . e(t('More ways to share')) . '">' . self::icons()['more'] . '</button>';
        return $html . '</div>';
    }

    /** Monochrome glyphs, drawn here so no icon font or third-party asset is needed. */
    public static function icons(): array
    {
        return [
            'whatsapp' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.5 15.3L2 22l4.8-1.5A10 10 0 1 0 12 2m0 1.8a8.2 8.2 0 1 1-4.3 15.1l-.3-.2-2.8.9.9-2.7-.2-.3A8.2 8.2 0 0 1 12 3.8M8.6 7.6c-.2 0-.4 0-.6.2-.3.2-.9.8-.9 2s.9 2.3 1 2.5c.1.2 1.7 2.7 4.2 3.7 2 .8 2.4.7 2.9.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1 .1-1.1l-.5-.3-1.5-.7c-.2-.1-.4-.1-.5.1l-.7.9c-.1.2-.3.2-.5.1-.2-.1-1-.4-1.8-1.1-.7-.6-1.1-1.3-1.2-1.5-.1-.2 0-.4.1-.5l.4-.4c.1-.2.2-.3.2-.5v-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4z"/></svg>',
            'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.4 22v-8.2h2.8l.4-3.2h-3.2V8.5c0-.9.3-1.6 1.6-1.6h1.7V4.1c-.3 0-1.3-.1-2.5-.1-2.5 0-4.2 1.5-4.2 4.3v2.3H7.2v3.2h2.8V22z"/></svg>',
            'x' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.2 3h3.3l-7.2 8.2L21.8 21h-6.6l-5.2-6.4L4.1 21H.8l7.7-8.8L.4 3h6.8l4.7 5.9zm-1.2 16h1.8L7.3 4.8H5.4z"/></svg>',
            'email' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M3 6.5h18v11H3zM3 7l9 6 9-6"/></svg>',
            'copy' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 1 0-5.7-5.7L11.5 6.8M14 10a4 4 0 0 0-5.7 0l-3 3A4 4 0 0 0 11 18.7l1.4-1.4"/></svg>',
            'share' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12 3v13M8 7l4-4 4 4M5 13v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>',
            'more' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="19" cy="12" r="1.8" fill="currentColor"/></svg>',
            'messenger' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2C6.3 2 2 6.2 2 11.8c0 3.2 1.4 6 3.7 7.8V23l3.4-1.9c.9.3 1.9.4 2.9.4 5.7 0 10-4.2 10-9.7S17.7 2 12 2m1 13-2.5-2.7L5.6 15l5.4-5.7 2.6 2.7L18.4 9z"/></svg>',
            'telegram' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.9 4.3 18.6 20c-.2 1.1-.9 1.3-1.8.8l-5-3.6-2.4 2.3c-.3.3-.5.5-1 .5l.4-5.1 9.3-8.4c.4-.4-.1-.6-.6-.2L5.9 13.1 1 11.6c-1-.3-1.1-1 .2-1.5l19.2-7.4c.9-.3 1.7.2 1.5 1.6"/></svg>',
            'linkedin' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5M3 9.5h4V21H3zM9.5 9.5h3.8v1.6h.1c.5-1 1.8-2 3.7-2 4 0 4.7 2.6 4.7 5.9V21h-4v-5.3c0-1.3 0-2.9-1.8-2.9s-2.1 1.4-2.1 2.8V21h-4z"/></svg>',
            'reddit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 12.1a2.3 2.3 0 0 0-3.9-1.6 11.2 11.2 0 0 0-5.6-1.8l1-4.5 3.2.7a1.8 1.8 0 1 0 .2-1.2l-3.8-.8c-.2 0-.4.1-.4.3l-1.1 5A11.2 11.2 0 0 0 5.9 10.5 2.3 2.3 0 0 0 2 12.1c0 .9.5 1.6 1.2 2v.6C3.2 17.9 7 20.4 12 20.4s8.8-2.5 8.8-5.7V14c.7-.4 1.2-1.1 1.2-1.9M7 13.9a1.6 1.6 0 1 1 3.2 0 1.6 1.6 0 0 1-3.2 0m8.7 4.2c-1 1-2.7 1.1-3.7 1.1s-2.7-.1-3.7-1.1a.4.4 0 0 1 .6-.6c.7.7 2 .9 3.1.9s2.5-.2 3.1-.9a.4.4 0 1 1 .6.6m-.9-2.6a1.6 1.6 0 1 1 0-3.2 1.6 1.6 0 0 1 0 3.2"/></svg>',
            'bluesky' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 10.8C10.9 8.6 7.9 4.6 5.1 2.6 2.4.7 1.4 1 .7 1.3 0 1.7 0 3 0 3.8s.4 6.3.7 7.3c.9 3.1 4.2 4.2 7.2 3.9-4.4.7-8.3 2.3-3.2 7.9 5.6 5.8 7.7-1.3 8.7-4.8 1 3.5 2.3 10.4 8.6 4.8 4.8-4.8 1.3-7.2-3.1-7.9 3 .3 6.3-.8 7.2-3.9.3-1 .7-6.5.7-7.3s0-2.1-.7-2.5C25.4 1 24.4.7 21.7 2.6c-2.8 2-5.8 6-6.9 8.2z" transform="scale(.83) translate(2.4 2)"/></svg>',
            'threads' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.2 22h-.1c-3 0-5.3-1-6.9-3C3.8 17.2 3 14.8 3 12s.8-5.2 2.2-7C6.8 3 9.1 2 12.1 2h.1c2.3 0 4.2.6 5.7 1.7 1.4 1.1 2.4 2.6 2.9 4.6l-2 .5c-.9-3.3-3.2-4.7-6.6-4.8h-.1c-2.3 0-4 .8-5.2 2.2C5.8 7.7 5.2 9.7 5.2 12s.6 4.3 1.7 5.7c1.2 1.5 2.9 2.2 5.2 2.2h.1c2.1 0 3.5-.5 4.7-1.6 1.3-1.3 1.3-2.8 1-3.8-.3-.6-.8-1.1-1.4-1.5-.2 1.1-.5 2-1 2.7-.7 1-1.8 1.6-3.2 1.7-1.1.1-2.1-.2-2.9-.7-.9-.6-1.5-1.6-1.5-2.7-.1-2.2 1.7-3.8 4.4-4 1 0 1.9.1 2.7.2-.1-.6-.3-1.2-.6-1.5-.4-.5-1-.8-1.8-.8-.7 0-1.6.2-2.2 1.1l-1.7-1.1C10 6.4 11.2 5.8 12.7 5.8c2.5 0 4 1.5 4.2 4.2h.1c1.4.6 2.4 1.5 3 2.7.7 1.6.8 4.3-1.3 6.4-1.6 1.6-3.5 2.3-6.2 2.3zm-.3-9c-1.9.1-2.6 1-2.5 1.8 0 .9 1 1.4 2 1.3 1.4-.1 2.2-.8 2.5-3-.6-.1-1.3-.2-2-.1"/></svg>',
            'mastodon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M21.3 8.4c0-4-2.6-5.1-2.6-5.1C17.4 2.7 15.1 2.4 12.7 2.4h-.1c-2.4 0-4.7.3-6 .9 0 0-2.6 1.2-2.6 5.1v3.1c0 4.2.7 8.3 4.6 9.3 1.8.5 3.4.6 4.6.5 2.3-.1 3.5-.8 3.5-.8l-.1-1.7s-1.6.5-3.4.4c-1.8-.1-3.6-.2-3.9-2.4v-.6s1.7.4 3.9.5c1.3.1 2.6-.1 3.9-.2 2.7-.3 5.1-2 5.4-3.5.5-2.4.4-5.6.4-5.6zm-3.3 5.5h-2v-5c0-1.1-.5-1.6-1.4-1.6-1 0-1.5.7-1.5 1.9v2.7h-2V9.2c0-1.2-.5-1.9-1.5-1.9-.9 0-1.4.5-1.4 1.6v5h-2V8.7c0-1.1.3-1.9.8-2.5.6-.6 1.3-.9 2.2-.9 1.1 0 1.9.4 2.4 1.2l.5.8.5-.8c.5-.8 1.3-1.2 2.4-1.2.9 0 1.6.3 2.2.9.5.6.8 1.4.8 2.5z"/></svg>',
            'pinterest' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 0 0-3.6 19.3c-.1-.8-.2-2 0-2.9l1.2-5s-.3-.6-.3-1.5c0-1.5.8-2.5 1.9-2.5.9 0 1.3.7 1.3 1.5 0 .9-.6 2.2-.9 3.5-.2 1 .5 1.9 1.6 1.9 1.9 0 3.2-2.4 3.2-5.3 0-2.2-1.5-3.8-4.2-3.8-3 0-4.9 2.3-4.9 4.8 0 .9.3 1.5.7 2 .2.2.2.3.1.6l-.2.8c-.1.3-.3.4-.5.3-1.4-.6-2.1-2.2-2.1-4C5.3 8.5 7.7 5 12.3 5c3.7 0 6.2 2.7 6.2 5.6 0 3.8-2.1 6.6-5.2 6.6-1.1 0-2-.6-2.4-1.2l-.6 2.5c-.2.8-.7 1.7-1.1 2.3A10 10 0 1 0 12 2"/></svg>',
            'viber' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2C8.4 2 5.6 2.6 4 4.2 2.7 5.5 2.2 7.6 2.2 10.2s.5 4.7 1.8 6c.5.5 1.1.9 1.8 1.2V22l2.7-2.5c1 .1 2.1.2 3.5.2 3.6 0 6.4-.6 8-2.2 1.3-1.3 1.8-3.4 1.8-6s-.5-4.7-1.8-6C18.4 2.6 15.6 2 12 2m0 1.9c3.2 0 5.5.5 6.6 1.6.9.9 1.3 2.5 1.3 4.7s-.4 3.8-1.3 4.7c-1.1 1.1-3.4 1.6-6.6 1.6-1.4 0-2.5-.1-3.4-.3l-1.9 1.7v-2.3c-.8-.3-1.4-.6-1.8-1-.9-.9-1.3-2.5-1.3-4.7s.4-3.8 1.3-4.7C6 4.4 8.3 3.9 12 3.9zm-3.2 2.4c-.3 0-.6.1-.8.3l-.7.7c-.3.3-.4.8-.2 1.2.7 1.6 2.6 4 4.6 5 .4.2.9.1 1.2-.2l.6-.6c.3-.3.3-.7.1-1l-1-1.3c-.2-.3-.7-.4-1-.2l-.5.3c-.7-.4-1.6-1.3-2-2l.3-.5c.2-.3.1-.7-.1-1l-1-1.3c-.2-.2-.4-.3-.7-.3zm3.8.3v1c2 .1 3.3 1.5 3.4 3.5h1c-.1-2.6-1.8-4.4-4.4-4.5m.2 1.9v1c.9.1 1.3.6 1.4 1.5h1c-.1-1.5-.9-2.4-2.4-2.5"/></svg>',
            'sms' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M4 5h16v11H9l-5 4z"/></svg>',
            'line' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3C6.8 3 2.5 6.4 2.5 10.6c0 3.8 3.4 7 8 7.5.3.1.7.2.8.5.1.3 0 .7 0 1l-.1.8c0 .3-.2 1 .9.5 1.1-.4 5.8-3.4 7.9-5.8 1.4-1.6 2.1-3.1 2.1-4.5C22 6.4 17.7 3 12.5 3zM8.4 13H6.6c-.3 0-.5-.2-.5-.5V9c0-.3.2-.5.5-.5s.5.2.5.5v3h1.3c.3 0 .5.2.5.5s-.2.5-.5.5m2.2-.5c0 .3-.2.5-.5.5s-.5-.2-.5-.5V9c0-.3.2-.5.5-.5s.5.2.5.5zm4 0c0 .2-.1.4-.3.5h-.2c-.2 0-.3-.1-.4-.2l-1.8-2.4v2.1c0 .3-.2.5-.5.5s-.5-.2-.5-.5V9c0-.2.1-.4.3-.5h.2c.1 0 .3.1.4.2l1.8 2.4V9c0-.3.2-.5.5-.5s.5.2.5.5zm3 -2.3c.3 0 .5.2.5.5s-.2.5-.5.5h-1.3v.8h1.3c.3 0 .5.2.5.5s-.2.5-.5.5h-1.8c-.3 0-.5-.2-.5-.5V9c0-.3.2-.5.5-.5h1.8c.3 0 .5.2.5.5s-.2.5-.5.5h-1.3v.8z"/></svg>',
            'skype' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20.9 13.9A9 9 0 0 0 10.1 3.1a5.2 5.2 0 0 0-7 7 9 9 0 0 0 10.8 10.8 5.2 5.2 0 0 0 7-7M12.2 17.6c-2.7 0-4.7-1.2-4.7-2.9 0-.7.5-1.2 1.2-1.2 1.6 0 1.3 2.2 3.5 2.2 1.1 0 1.8-.6 1.8-1.2 0-.9-.8-1.1-2-1.4l-1.5-.4c-2-.5-3.4-1.3-3.4-3.3 0-2.4 2.2-3.5 4.5-3.5 2.1 0 4.4 1 4.4 2.5 0 .7-.5 1.2-1.2 1.2-1.3 0-1.2-1.8-3.4-1.8-1 0-1.8.4-1.8 1.1s.8 1 1.6 1.2l1.2.3c2.1.5 4 1.1 4 3.5 0 2.1-2 3.7-4.2 3.7"/></svg>',
            'snapchat' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.2c2.7 0 4.5 2 4.6 4.7v2.4c.4.1.9-.2 1.3-.2.6 0 1.1.4 1.1.9s-.6.8-1.2 1c-.6.2-.9.3-.9.7 0 .8 2.1 3.4 3.8 3.9.4.1.6.3.6.6 0 .8-1.9 1.2-2.4 1.3-.2.4-.2 1.1-.6 1.3-.3.1-.8 0-1.3 0-.8 0-1.4.1-2.1.6-.8.6-1.6 1.1-2.9 1.1s-2.1-.5-2.9-1.1c-.7-.5-1.3-.6-2.1-.6-.5 0-1 .1-1.3 0-.4-.2-.4-.9-.6-1.3-.5-.1-2.4-.5-2.4-1.3 0-.3.2-.5.6-.6 1.7-.5 3.8-3.1 3.8-3.9 0-.4-.3-.5-.9-.7-.6-.2-1.2-.5-1.2-1s.5-.9 1.1-.9c.4 0 .9.3 1.3.2V6.9c.1-2.7 1.9-4.7 4.6-4.7"/></svg>',
            'tumblr' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M14.2 22c-3.3 0-5.8-1.7-5.8-5.8v-6H5.6V7.5c3-.8 4.2-3.3 4.4-5.5h2.7v5h3.9v3.2h-3.9v5.4c0 1.7.8 2.2 2.1 2.2h1.9V22z"/></svg>',
            'pocket' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3.5 3h17c1 0 1.5.6 1.5 1.5V10c0 5.5-4.5 10-10 10S2 15.5 2 10V4.5C2 3.6 2.6 3 3.5 3m3.6 6.1 4 3.8c.5.5 1.3.5 1.8 0l4-3.8a1.2 1.2 0 0 0-1.7-1.8L12 10.4 8.8 7.3a1.2 1.2 0 1 0-1.7 1.8"/></svg>',
            'flipboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 3h18v5.3h-6v5.4h-4v5.3H3z"/></svg>',
            'hackernews' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 3h18v18H3zm4.6 3.4 3.5 6.3V18h1.8v-5.3l3.5-6.3h-2l-2.4 4.7-2.4-4.7z"/></svg>',
            'digg' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2 7h2v10H2zm3.5 0H10v13H5.5v-2h2.6v-2H5.5zm2.6 2H7.4v6h.7zM11 7h4.5v13H11v-2h2.6v-2H11zm2.6 2h-.7v6h.7zM16.5 7H21v13h-4.5v-2h2.6v-2h-2.6zm2.6 2h-.7v6h.7z"/></svg>',
            'buffer' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="m12 2 10 5-10 5L2 7zm0 9.3 8.2-4.1 1.8.9-10 5-10-5 1.8-.9zm0 5 8.2-4.1 1.8.9-10 5-10-5 1.8-.9z"/></svg>',
            'instapaper' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 3h6v2h-1.6v14H15v2H9v-2h1.6V5H9z"/></svg>',
            'evernote' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8.3 3c1 0 1.3.6 1.3 1.3v3.4c0 .9-.6 1.5-1.5 1.5H4.6c-.6 0-1.1.1-1.4.4l5.1-6.2c.2-.3.6-.4 1-.4M11 3h4.8c2.3 0 2.9 1.6 3.1 2.6.2.9.9 4.6 1.1 6.9.3 3.2-.2 5.4-1.6 6.7-1.4 1.3-3.2 1.3-3.9 1.1-1.3-.3-2.3-1.4-2.3-2.5 0-1 .6-1.5 1.5-1.5h.6c.7 0 1-.5 1-1.1 0-.7-.5-1-1.5-1.2-1.6-.4-2.9-1.9-2.9-3.8V3zM3 11h4.9v3.2c0 2.1 1.4 3.6 3.4 4.1v.5c-.1 1.2.5 2.4 1.5 3.1-.3.1-.6.1-.9.1H6.4C4.5 22 3 20.5 3 18.6z"/></svg>',
            'classroom' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 4h18v16H3zm3 11.5c0-1.2 1.4-1.8 3-1.8s3 .6 3 1.8V17H6zm9 0c0-.8.6-1.3 1.5-1.5 1.2 0 2.5.4 2.5 1.5V17h-4zM9 9.5a1.8 1.8 0 1 1 0 3.6 1.8 1.8 0 0 1 0-3.6m7.5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3"/></svg>',
            'xing' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.5 6.5h3.1l2.1 3.7-3.3 5.8H5.3l3.3-5.8zM18.7 2h-3.1l-6.1 10.7 3.9 6.9h3.1l-3.9-6.9z"/></svg>',
            'vk' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.8 17c-5.3 0-8.6-3.7-8.7-9.8h2.7c.1 4.5 2.1 6.4 3.7 6.8V7.2h2.5v3.9c1.5-.2 3.1-1.9 3.6-3.9h2.5c-.4 2.4-2.1 4.1-3.3 4.8 1.2.6 3.1 2.1 3.8 5h-2.7c-.6-1.8-2-3.2-3.9-3.5V17z"/></svg>',
            'ok' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.5a4.3 4.3 0 1 0 0 8.6 4.3 4.3 0 0 0 0-8.6m0 2.5a1.8 1.8 0 1 1 0 3.6A1.8 1.8 0 0 1 12 5m-5.4 7.6c-.5.9-.1 1.9.8 2.4 1 .5 2.1.9 3.2 1L8 18.6a1.7 1.7 0 0 0 2.4 2.4l1.6-1.6 1.6 1.6a1.7 1.7 0 0 0 2.4-2.4L13.4 16c1.1-.1 2.2-.5 3.2-1 .9-.5 1.3-1.5.8-2.4-.5-.9-1.6-1-2.5-.5-1.8 1-3.9 1-5.8 0-.9-.5-2-.4-2.5.5"/></svg>',
            'weibo' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10.3 20c-4.3 0-8.3-2.1-8.3-5.7 0-1.8 1.2-4 3.2-6C7.9 5.9 10.7 5 11.7 6c.4.5.2 1.6 0 2.3 0 .3.3.2.3.2 2-.9 3.8-.9 4.4-.1.3.4.4 1.1 0 1.9 0 .2 0 .4.3.4 1.2.4 2.6 1.4 2.6 3.1 0 2.9-4.1 6.2-9 6.2m.6-9.2c-3 .3-5.3 2.2-5.1 4.2.2 2 2.7 3.3 5.7 3 3-.3 5.3-2.2 5.1-4.2-.2-2-2.7-3.3-5.7-3m-.4 6.4c-1.5.1-2.8-.6-2.9-1.7s1-2 2.5-2.2c1.5-.1 2.8.6 2.9 1.7s-1 2.1-2.5 2.2M18.8 3.3c-.9-.2-1.8 0-2 .8s.3 1.2.9 1.4c1.5.3 2.4 1.7 2.1 3.2-.1.6.2 1.2.9 1.3.6.1 1.1-.4 1.2-1 .5-2.8-1.3-5.2-3.1-5.7"/></svg>',
            'qq' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2c2.7 0 4.9 2.3 4.9 5.2 0 .8.3 1.5.8 2.2 1.2 1.6 2.1 3.8 2.1 5.4 0 1-.3 1.6-.8 1.7-.6.1-1.1-.6-1.6-1.6-.2.9-.6 1.7-1.2 2.3.9.4 1.5.9 1.5 1.5 0 1-1.7 1.8-3.9 1.8-1.1 0-2.1-.2-2.8-.5-.7.3-1.7.5-2.8.5-2.2 0-3.9-.8-3.9-1.8 0-.6.6-1.1 1.5-1.5-.6-.6-1-1.4-1.2-2.3-.5 1-1 1.7-1.6 1.6-.5-.1-.8-.7-.8-1.7 0-1.6.9-3.8 2.1-5.4.5-.7.8-1.4.8-2.2C7.1 4.3 9.3 2 12 2"/></svg>',
            'douban' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 3h18v2H3zm1.8 3.4h14.4v8.2H4.8zm2 2v4.2h10.4V8.4zM3 19h4.6l-1.4-3H8l1.4 3h5.2l1.4-3h1.8l-1.4 3H21v2H3z"/></svg>',
            'print' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" d="M7 9V3h10v6M7 19H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 15h10v6H7z"/></svg>',
        ];
    }
}
