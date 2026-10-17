<?php
declare(strict_types=1);

namespace MeNews\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A computed Hindu almanac (panchang) strip: tithi (lunar day) and nakshatra (lunar mansion)
 * for a given moment, in India Standard Time. Purely algorithmic — low-precision Sun/Moon
 * ecliptic-longitude formulas (the largest periodic terms from Meeus, "Astronomical
 * Algorithms") converted to sidereal longitude with the Lahiri ayanamsa, the standard most
 * Indian panchangs use. This is good to roughly a few arcminutes for the Sun and a few tenths
 * of a degree for the Moon — plenty for a tithi (12° wide) or nakshatra (13°20' wide) most of
 * the time, but exact transition *times* near a boundary can differ by up to an hour or two
 * from an official panchang, the same way different panchangs occasionally disagree with each
 * other. Treat it as a news-page touch, not a religious authority.
 */
final class Panchang
{
    private const TITHI_NAMES = [
        'Pratipada', 'Dwitiya', 'Tritiya', 'Chaturthi', 'Panchami', 'Shashthi', 'Saptami', 'Ashtami',
        'Navami', 'Dashami', 'Ekadashi', 'Dwadashi', 'Trayodashi', 'Chaturdashi',
    ];

    private const NAKSHATRAS = [
        'Ashwini', 'Bharani', 'Krittika', 'Rohini', 'Mrigashira', 'Ardra', 'Punarvasu', 'Pushya', 'Ashlesha',
        'Magha', 'Purva Phalguni', 'Uttara Phalguni', 'Hasta', 'Chitra', 'Swati', 'Vishakha', 'Anuradha', 'Jyeshtha',
        'Mula', 'Purva Ashadha', 'Uttara Ashadha', 'Shravana', 'Dhanishta', 'Shatabhisha', 'Purva Bhadrapada',
        'Uttara Bhadrapada', 'Revati',
    ];

    private static function deg2rad(float $d): float
    {
        return $d * M_PI / 180;
    }

    private static function norm360(float $d): float
    {
        $d = fmod($d, 360);
        return $d < 0 ? $d + 360 : $d;
    }

    /** Julian centuries since J2000.0 (TT ≈ UTC here — close enough for this precision). */
    private static function centuries(DateTimeImmutable $utc): float
    {
        $jd = (float)$utc->format('U') / 86400 + 2440587.5;
        return ($jd - 2451545.0) / 36525;
    }

    /** Tropical ecliptic longitude of the Sun, low-precision (Meeus ch. 25), degrees. */
    private static function sunLongitude(float $t): float
    {
        $l0 = 280.46646 + 36000.76983 * $t + 0.0003032 * $t ** 2;
        $m = self::deg2rad(357.52911 + 35999.05029 * $t - 0.0001537 * $t ** 2);
        $c = (1.914602 - 0.004817 * $t - 0.000014 * $t ** 2) * sin($m)
            + (0.019993 - 0.000101 * $t) * sin(2 * $m)
            + 0.000289 * sin(3 * $m);
        return self::norm360($l0 + $c);
    }

    /** Tropical ecliptic longitude of the Moon, low-precision (largest terms, Meeus ch. 47), degrees. */
    private static function moonLongitude(float $t): float
    {
        $lp = 218.3164477 + 481267.88123421 * $t;
        $d = self::deg2rad(297.8501921 + 445267.1114034 * $t);
        $m = self::deg2rad(357.5291092 + 35999.0502909 * $t);
        $mp = self::deg2rad(134.9633964 + 477198.8675055 * $t);
        $f = self::deg2rad(93.2720950 + 483202.0175233 * $t);
        $dl = 6.288774 * sin($mp) + 1.274027 * sin(2 * $d - $mp) + 0.658314 * sin(2 * $d) + 0.213618 * sin(2 * $mp)
            - 0.185116 * sin($m) - 0.114332 * sin(2 * $f) + 0.058793 * sin(2 * $d - 2 * $mp) + 0.057066 * sin(2 * $d - $m - $mp)
            + 0.053322 * sin(2 * $d + $mp) + 0.045758 * sin(2 * $d - $m) - 0.040923 * sin($m - $mp) - 0.034720 * sin($d)
            - 0.030383 * sin($m + $mp) + 0.015327 * sin(2 * $d - 2 * $f) - 0.012528 * sin(2 * $f + $mp);
        return self::norm360($lp + $dl);
    }

    /** Lahiri ayanamsa (precession correction, tropical → sidereal), degrees — linear approximation. */
    private static function lahiriAyanamsa(int $year): float
    {
        return 23.85 + ($year - 2000) * 0.01397;
    }

    /** @return array{tithi:string,paksha:string,nakshatra:string,moon_phase_pct:int} */
    public static function forNow(): array
    {
        $ist = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $utc = $ist->setTimezone(new DateTimeZone('UTC'));
        $t = self::centuries($utc);
        $ayanamsa = self::lahiriAyanamsa((int)$ist->format('Y'));
        $sun = self::norm360(self::sunLongitude($t) - $ayanamsa);
        $moon = self::norm360(self::moonLongitude($t) - $ayanamsa);
        $elongation = self::norm360($moon - $sun);

        $tithiIndex = (int)floor($elongation / 12); // 0..29
        $paksha = $tithiIndex < 15 ? 'Shukla' : 'Krishna';
        $withinPaksha = $tithiIndex % 15;
        $tithiName = match (true) {
            $withinPaksha === 14 && $paksha === 'Shukla' => 'Purnima',
            $withinPaksha === 14 && $paksha === 'Krishna' => 'Amavasya',
            default => self::TITHI_NAMES[$withinPaksha],
        };

        $nakshatraIndex = (int)floor($moon / (360 / 27)) % 27;

        return [
            'tithi' => $tithiName,
            'paksha' => in_array($tithiName, ['Purnima', 'Amavasya'], true) ? '' : $paksha,
            'nakshatra' => self::NAKSHATRAS[$nakshatraIndex],
            'moon_phase_pct' => (int)round((1 - cos(self::deg2rad($elongation))) * 50),
        ];
    }
}
