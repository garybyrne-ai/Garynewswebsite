<?php
declare(strict_types=1);

namespace MeNews\Support;

/** Editorial sections used across the wire, community reporting and navigation. */
final class Categories
{
    /** @var array<string,array{slug:string,blurb:string,icon:string}> */
    public const ALL = [
        'National'  => ['slug' => 'national',  'blurb' => 'India-wide news, politics and public affairs', 'icon' => 'national'],
        'Local'     => ['slug' => 'local',     'blurb' => 'State-by-state reporting from every corner of the country', 'icon' => 'pin'],
        'Business'  => ['slug' => 'business',  'blurb' => 'Economy, enterprise, jobs and markets', 'icon' => 'briefcase'],
        'Technology'=> ['slug' => 'technology','blurb' => 'The latest in tech, from Indian startups to the biggest names in the world', 'icon' => 'cpu'],
        'Sport'     => ['slug' => 'sport',     'blurb' => 'Cricket, kabaddi, football, badminton and everything in between', 'icon' => 'trophy'],
        'Entertainment' => ['slug' => 'entertainment', 'blurb' => 'Bollywood, television and entertainment from across India', 'icon' => 'clapperboard'],
        'Culture'   => ['slug' => 'culture',   'blurb' => 'Arts, music, books and Indian life', 'icon' => 'culture'],
        'Defence'   => ['slug' => 'defence',   'blurb' => 'Defence technology and the armed forces — India and the world', 'icon' => 'shield'],
        'Community' => ['slug' => 'community', 'blurb' => 'Reports from the people who live where it happens', 'icon' => 'community'],
        'Traffic'   => ['slug' => 'traffic',   'blurb' => 'Roads, rail, delays and transport alerts', 'icon' => 'traffic'],
        'Council'   => ['slug' => 'council',   'blurb' => 'Local authorities, planning and civic decisions', 'icon' => 'council'],
        "What's On" => ['slug' => 'whats-on',  'blurb' => 'Events, festivals, gigs and things to do', 'icon' => 'calendar'],
        'World'     => ['slug' => 'world',     'blurb' => 'The stories beyond our shores that matter at home', 'icon' => 'world'],
    ];

    /** Sections shown in the primary navigation, in order. */
    public const NAV = ['National', 'Local', 'Business', 'Technology', 'Sport', 'Entertainment', 'Culture', 'Defence', 'Community', 'Traffic', 'Council', "What's On", 'World'];

    public const LABELS = ['Community Report', 'Developing', 'Corroborated', 'Verified', 'Official'];

    /** Labels valid on any story, including the machine-set one for wire headlines. */
    public static function validLabel(string $label): bool
    {
        return in_array($label, self::LABELS, true) || $label === 'Wire';
    }

    public static function names(): array
    {
        return array_keys(self::ALL);
    }

    public static function slug(string $name): string
    {
        return self::ALL[$name]['slug'] ?? slugify($name);
    }

    public static function fromSlug(string $slug): ?string
    {
        foreach (self::ALL as $name => $meta) {
            if ($meta['slug'] === $slug) {
                return $name;
            }
        }
        return null;
    }

    public static function valid(string $name): bool
    {
        return isset(self::ALL[$name]);
    }

    /** Community reporting categories offered in the report form. */
    public static function community(): array
    {
        return ['Community', 'Traffic', 'Council', 'Sport', "What's On", 'Business', 'Local'];
    }
}
