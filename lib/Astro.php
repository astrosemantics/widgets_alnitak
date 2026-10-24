<?php
/**
 * Positions du Soleil et de la Lune (précision ~0,1–0,3°), sans dépendance.
 *
 * Formules : J. Meeus, « Astronomical Algorithms », 2e éd.
 *   - Soleil : chapitre 25 (méthode simplifiée)
 *   - Lune   : chapitre 47 (principaux termes)
 *   - Phase  : chapitre 48
 * Suffisant pour des heures de lever / coucher et de crépuscule à la minute près.
 */

declare(strict_types=1);

final class Astro
{
    private float $lat;   // degrés, nord positif
    private float $lon;   // degrés, est positif

    public function __construct(float $latitude, float $longitude)
    {
        $this->lat = $latitude;
        $this->lon = $longitude;
    }

    private static function norm(float $a): float
    {
        $a = fmod($a, 360.0);
        return $a < 0 ? $a + 360.0 : $a;
    }

    private static function sind(float $a): float { return sin(deg2rad($a)); }
    private static function cosd(float $a): float { return cos(deg2rad($a)); }

    /** Siècles juliens depuis J2000 et jours depuis J2000. */
    private static function temps(int $ts): array
    {
        $jd = $ts / 86400.0 + 2440587.5;
        $d = $jd - 2451545.0;
        return [$d / 36525.0, $d];
    }

    /** Obliquité de l'écliptique (degrés). */
    private static function obliquite(float $t): float
    {
        $omega = 125.04 - 1934.136 * $t;
        return 23.439291 - 0.0130042 * $t + 0.00256 * self::cosd($omega);
    }

    /** Longitude écliptique apparente du Soleil (degrés). */
    private static function longitudeSoleil(float $t): float
    {
        $l0 = 280.46646 + 36000.76983 * $t + 0.0003032 * $t * $t;
        $m = 357.52911 + 35999.05029 * $t - 0.0001537 * $t * $t;
        $c = (1.914602 - 0.004817 * $t - 0.000014 * $t * $t) * self::sind($m)
            + (0.019993 - 0.000101 * $t) * self::sind(2 * $m)
            + 0.000289 * self::sind(3 * $m);
        $omega = 125.04 - 1934.136 * $t;
        return self::norm($l0 + $c - 0.00569 - 0.00478 * self::sind($omega));
    }

    /** Hauteur (degrés) d'un astre de coordonnées équatoriales (ra, dec en degrés). */
    private function hauteurDepuis(float $ra, float $dec, float $d, float $t): float
    {
        $gmst = 280.46061837 + 360.98564736629 * $d + 0.000387933 * $t * $t;
        $h = self::norm($gmst + $this->lon - $ra);
        $sinAlt = self::sind($this->lat) * self::sind($dec) + self::cosd($this->lat) * self::cosd($dec) * self::cosd($h);
        return rad2deg(asin(max(-1.0, min(1.0, $sinAlt))));
    }

    /** Écliptique → équatorial. */
    private static function equatorial(float $lambda, float $beta, float $eps): array
    {
        $ra = rad2deg(atan2(
            self::sind($lambda) * self::cosd($eps) - tan(deg2rad($beta)) * self::sind($eps),
            self::cosd($lambda)
        ));
        $dec = rad2deg(asin(self::sind($beta) * self::cosd($eps) + self::cosd($beta) * self::sind($eps) * self::sind($lambda)));
        return [self::norm($ra), $dec];
    }

    /** Hauteur géométrique du centre du Soleil (degrés). */
    public function hauteurSoleil(int $ts): float
    {
        [$t, $d] = self::temps($ts);
        $eps = self::obliquite($t);
        [$ra, $dec] = self::equatorial(self::longitudeSoleil($t), 0.0, $eps);
        return $this->hauteurDepuis($ra, $dec, $d, $t);
    }

    /** Coordonnées écliptiques de la Lune : [longitude, latitude, distance km]. */
    private static function lune(float $t): array
    {
        $lp = 218.3164477 + 481267.88123421 * $t;
        $d = 297.8501921 + 445267.1114034 * $t;
        $m = 357.5291092 + 35999.0502909 * $t;
        $mp = 134.9633964 + 477198.8675055 * $t;
        $f = 93.2720950 + 483202.0175233 * $t;

        $lon = $lp
            + 6.288774 * self::sind($mp)
            + 1.274027 * self::sind(2 * $d - $mp)
            + 0.658314 * self::sind(2 * $d)
            + 0.213618 * self::sind(2 * $mp)
            - 0.185116 * self::sind($m)
            - 0.114332 * self::sind(2 * $f)
            + 0.058793 * self::sind(2 * $d - 2 * $mp)
            + 0.057066 * self::sind(2 * $d - $m - $mp)
            + 0.053322 * self::sind(2 * $d + $mp)
            + 0.045758 * self::sind(2 * $d - $m)
            - 0.040923 * self::sind($m - $mp)
            - 0.034720 * self::sind($d)
            - 0.030383 * self::sind($m + $mp)
            + 0.015327 * self::sind(2 * $d - 2 * $f)
            - 0.012528 * self::sind($mp + 2 * $f)
            + 0.010980 * self::sind($mp - 2 * $f)
            + 0.010675 * self::sind(4 * $d - $mp)
            + 0.010034 * self::sind(3 * $mp)
            + 0.008548 * self::sind(4 * $d - 2 * $mp)
            - 0.007888 * self::sind(2 * $d + $m - $mp)
            - 0.006766 * self::sind(2 * $d + $m)
            - 0.005163 * self::sind($d - $mp);

        $lat = 5.128122 * self::sind($f)
            + 0.280602 * self::sind($mp + $f)
            + 0.277693 * self::sind($mp - $f)
            + 0.173237 * self::sind(2 * $d - $f)
            + 0.055413 * self::sind(2 * $d - $mp + $f)
            + 0.046271 * self::sind(2 * $d - $mp - $f)
            + 0.032573 * self::sind(2 * $d + $f)
            + 0.017198 * self::sind(2 * $mp + $f)
            + 0.009266 * self::sind(2 * $d + $mp - $f)
            + 0.008822 * self::sind(2 * $mp - $f);

        $dist = 385000.56
            - 20905.355 * self::cosd($mp)
            - 3699.111 * self::cosd(2 * $d - $mp)
            - 2955.968 * self::cosd(2 * $d)
            - 569.925 * self::cosd(2 * $mp)
            + 48.888 * self::cosd($m)
            - 3.149 * self::cosd(2 * $f)
            + 246.158 * self::cosd(2 * $d - 2 * $mp)
            - 152.138 * self::cosd(2 * $d - $m - $mp)
            - 170.733 * self::cosd(2 * $d + $mp)
            - 204.586 * self::cosd(2 * $d - $m)
            - 129.620 * self::cosd($m - $mp)
            + 108.743 * self::cosd($d)
            + 104.755 * self::cosd($m + $mp);

        return [self::norm($lon), $lat, $dist];
    }

    /**
     * Lune : hauteur topocentrique du centre (degrés), seuil de lever (degrés),
     * fraction éclairée (0–1) et croissante (true) / décroissante.
     */
    public function lunePosition(int $ts): array
    {
        [$t, $d] = self::temps($ts);
        $eps = self::obliquite($t);
        [$lambda, $beta, $dist] = self::lune($t);
        [$ra, $dec] = self::equatorial($lambda, $beta, $eps);
        $altGeo = $this->hauteurDepuis($ra, $dec, $d, $t);

        $parallaxe = rad2deg(asin(6378.14 / $dist));
        $altTopo = $altGeo - $parallaxe * self::cosd($altGeo);

        // Phase (Meeus ch. 48, approximation par l'élongation)
        $lambdaSoleil = self::longitudeSoleil($t);
        $cosPsi = self::cosd($beta) * self::cosd($lambda - $lambdaSoleil);
        $psi = rad2deg(acos(max(-1.0, min(1.0, $cosPsi))));
        $i = 180.0 - $psi - 0.1468 * self::sind($psi) * (1 - 0.0549 * self::sind(357.5291092 + 35999.0502909 * $t))
            / (1 - 0.0167 * self::sind(357.5291092 + 35999.0502909 * $t));
        $fraction = (1 + self::cosd($i)) / 2;
        $croissante = self::norm($lambda - $lambdaSoleil) < 180.0;

        return [
            'hauteur' => $altTopo,
            // Lever / coucher : bord supérieur à l'horizon, réfraction comprise
            'levee' => $altTopo > -(0.5667 + 0.2725 * $parallaxe),
            'fraction' => $fraction,
            'croissante' => $croissante,
        ];
    }

    /* ---------- Positions quelconques (objets du ciel, planètes) ---------- */

    /**
     * Précession approchée J2000 → date (méthode des taux annuels, ~1″ sur un siècle
     * hors des pôles ; largement assez pour un widget).
     */
    public static function precession(float $ra, float $dec, int $ts): array
    {
        $n = ($ts / 86400.0 + 2440587.5 - 2451545.0) / 365.25;
        $m = 3.07496 + 0.00186 * $n / 100;          // s/an
        $nn = 1.33621 - 0.00057 * $n / 100;         // s/an
        $dRa = ($m + $nn * self::sind($ra) * tan(deg2rad(max(-89.0, min(89.0, $dec))))) * $n * 15 / 3600;
        $dDec = 20.0431 * self::cosd($ra) * $n / 3600;
        return [self::norm($ra + $dRa), $dec + $dDec];
    }

    /** Hauteur et azimut (degrés, azimut compté depuis le nord vers l'est). */
    public function horizontales(float $ra, float $dec, int $ts): array
    {
        [$t, $d] = self::temps($ts);
        $gmst = 280.46061837 + 360.98564736629 * $d + 0.000387933 * $t * $t;
        $h = self::norm($gmst + $this->lon - $ra);
        $sinAlt = self::sind($this->lat) * self::sind($dec) + self::cosd($this->lat) * self::cosd($dec) * self::cosd($h);
        $alt = rad2deg(asin(max(-1.0, min(1.0, $sinAlt))));
        $az = rad2deg(atan2(self::sind($h), self::cosd($h) * self::sind($this->lat) - tan(deg2rad($dec)) * self::cosd($this->lat)));
        return [$alt, self::norm($az + 180.0)];
    }

    /** Ascension droite et déclinaison du zénith (équinoxe de la date). */
    public function zenith(int $ts): array
    {
        [$t, $d] = self::temps($ts);
        $gmst = 280.46061837 + 360.98564736629 * $d + 0.000387933 * $t * $t;
        return [self::norm($gmst + $this->lon), $this->lat];
    }

    /** Ascension droite et déclinaison géocentriques de la Lune (degrés, date). */
    public static function luneEquatoriales(int $ts): array
    {
        [$t] = self::temps($ts);
        [$lambda, $beta] = self::lune($t);
        return self::equatorial($lambda, $beta, self::obliquite($t));
    }
}
