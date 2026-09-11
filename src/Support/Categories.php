<?php
declare(strict_types=1);

namespace MeNews\Support;

/** Editorial sections used across the wire, community reporting and navigation. */
final class Categories
{
    /** @var array<string,array{slug:string,blurb:string,icon:string}> */
    public const ALL = [
        'National'  => ['slug' => 'national',  'blurb' => 'Ireland-wide news, politics and public affairs', 'icon' => 'national'],
        'Local'     => ['slug' => 'local',     'blurb' => 'County-by-county reporting from every corner of the island', 'icon' => 'pin'],
        'Business'  => ['slug' => 'business',  'blurb' => 'Economy, enterprise, jobs and technology', 'icon' => 'briefcase'],
        'Sport'     => ['slug' => 'sport',     'blurb' => 'GAA, rugby, soccer, racing and everything in between', 'icon' => 'trophy'],
        'Culture'   => ['slug' => 'culture',   'blurb' => 'Arts, music, screen, books and Irish life', 'icon' => 'culture'],
        'Community' => ['slug' => 'community', 'blurb' => 'Reports from the people who live where it happens', 'icon' => 'community'],
        'Traffic'   => ['slug' => 'traffic',   'blurb' => 'Roads, rail, delays and transport alerts', 'icon' => 'traffic'],
        'Council'   => ['slug' => 'council',   'blurb' => 'Local authorities, planning and civic decisions', 'icon' => 'council'],
        "What's On" => ['slug' => 'whats-on',  'blurb' => 'Events, festivals, gigs and things to do', 'icon' => 'calendar'],
        'World'     => ['slug' => 'world',     'blurb' => 'The stories beyond our shores that matter at home', 'icon' => 'world'],
    ];

    /** Sections shown in the primary navigation, in order. */
    public const NAV = ['National', 'Local', 'Business', 'Sport', 'Culture', 'Community', 'Traffic', 'Council', "What's On", 'World'];

    public const LABELS = ['Community Report', 'Developing', 'Verified', 'Official'];

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
