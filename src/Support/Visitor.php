<?php
declare(strict_types=1);

namespace MeNews\Support;

/**
 * The visitor's chosen locality, kept in two small cookies set by the browser:
 *   me_loc    = "lat,lng"   (from the Geolocation API, rounded to ~100 m)
 *   me_county = "Wicklow"   (manual choice, or derived from me_loc)
 * Nothing is stored server-side; the cookies only shape the "Near you" section.
 */
final class Visitor
{
    /** @return array{lat:float,lng:float}|null */
    public static function position(): ?array
    {
        $raw = (string)($_COOKIE['me_loc'] ?? '');
        if (!preg_match('/^(-?\d{1,2}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?)$/', $raw, $m)) {
            return null;
        }
        $lat = (float)$m[1];
        $lng = (float)$m[2];
        if (abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }
        return ['lat' => $lat, 'lng' => $lng];
    }

    public static function county(): ?string
    {
        $raw = trim((string)($_COOKIE['me_county'] ?? ''));
        foreach (Locations::countyNames() as $c) {
            if (strcasecmp($c, $raw) === 0) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Resolve everything the "Near you" section needs.
     * @return array{mode:string,place:?array,stories:array,radius:int,county:?string,title:string}
     */
    public static function locality(int $limit = 8): array
    {
        $pos = self::position();
        if ($pos) {
            $place = Geo::nearest($pos['lat'], $pos['lng']);
            if ($place['in_ireland']) {
                $radius = 40;
                $stories = \MeNews\Stories::near($pos['lat'], $pos['lng'], $radius, $limit, $place['county']);
                if (count($stories) < 4) {
                    $radius = 80;
                    $stories = \MeNews\Stories::near($pos['lat'], $pos['lng'], $radius, $limit, $place['county']);
                }
                $title = $place['town'] ? $place['town'] . ', Co. ' . $place['county'] : 'Co. ' . $place['county'];
                return ['mode' => 'gps', 'place' => $place, 'stories' => $stories, 'radius' => $radius, 'county' => $place['county'], 'title' => $title, 'position' => $pos];
            }
            return ['mode' => 'abroad', 'place' => $place, 'stories' => [], 'radius' => 0, 'county' => null, 'title' => 'Outside Ireland', 'position' => $pos];
        }
        $county = self::county();
        if ($county) {
            return ['mode' => 'county', 'place' => null, 'stories' => \MeNews\Stories::feed(['county' => $county], $limit), 'radius' => 0, 'county' => $county, 'title' => 'Co. ' . $county, 'position' => null];
        }
        return ['mode' => 'none', 'place' => null, 'stories' => [], 'radius' => 0, 'county' => null, 'title' => 'Near you', 'position' => null];
    }
}
