<?php
/**
 * Positions approchées des planètes (précision de l'ordre de la minute d'arc),
 * à partir des éléments képlériens moyens du JPL valables de 1800 à 2050 :
 * E. M. Standish, « Keplerian Elements for Approximate Positions of the Major Planets ».
 * Aucune donnée à mettre à jour.
 */

declare(strict_types=1);

final class Planetes
{
    /** Noms affichés. */
    public const NOMS = [
        'mercure' => 'Mercure',
        'venus' => 'Vénus',
        'mars' => 'Mars',
        'jupiter' => 'Jupiter',
        'saturne' => 'Saturne',
    ];

    /** a (ua), e, I, L, ϖ, Ω (degrés) et leurs variations par siècle. */
    private const ELEMENTS = [
        'mercure' => [[0.38709927, 0.20563593, 7.00497902, 252.25032350, 77.45779628, 48.33076593],
                      [0.00000037, 0.00001906, -0.00594749, 149472.67411175, 0.16047689, -0.12534081]],
        'venus'   => [[0.72333566, 0.00677672, 3.39467605, 181.97909950, 131.60246718, 76.67984255],
                      [0.00000390, -0.00004107, -0.00078890, 58517.81538729, 0.00268329, -0.27769418]],
        'terre'   => [[1.00000261, 0.01671123, -0.00001531, 100.46457166, 102.93768193, 0.0],
                      [0.00000562, -0.00004392, -0.01294668, 35999.37244981, 0.32327364, 0.0]],
        'mars'    => [[1.52371034, 0.09339410, 1.84969142, -4.55343205, -23.94362959, 49.55953891],
                      [0.00001847, 0.00007882, -0.00813131, 19140.30268499, 0.44441088, -0.29257343]],
        'jupiter' => [[5.20288700, 0.04838624, 1.30439695, 34.39644051, 14.72847983, 100.47390909],
                      [-0.00011607, -0.00013253, -0.00183714, 3034.74612775, 0.21252668, 0.20469106]],
        'saturne' => [[9.53667594, 0.05386179, 2.48599187, 49.95424423, 92.59887831, 113.66242448],
                      [-0.00125060, -0.00050991, 0.00193609, 1222.49362201, -0.41897216, -0.28867794]],
    ];

    /** Ascension droite et déclinaison géocentriques (degrés, J2000). */
    public static function equatoriales(string $planete, int $ts): array
    {
        $t = ($ts / 86400.0 + 2440587.5 - 2451545.0) / 36525.0;
        [$px, $py, $pz] = self::helio($planete, $t);
        [$tx, $ty, $tz] = self::helio('terre', $t);
        $x = $px - $tx;
        $y = $py - $ty;
        $z = $pz - $tz;
        $eps = deg2rad(23.43928);
        $ye = $y * cos($eps) - $z * sin($eps);
        $ze = $y * sin($eps) + $z * cos($eps);
        $ra = rad2deg(atan2($ye, $x));
        $dec = rad2deg(atan2($ze, hypot($x, $ye)));
        return [$ra < 0 ? $ra + 360 : $ra, $dec];
    }

    /** Coordonnées héliocentriques écliptiques (ua, J2000). */
    private static function helio(string $p, float $t): array
    {
        [$e0, $de] = self::ELEMENTS[$p];
        [$a, $e, $i, $l, $w, $o] = array_map(static fn($v, $d) => $v + $d * $t, $e0, $de);
        $m = deg2rad(fmod($l - $w, 360.0));
        $om = deg2rad($w - $o);   // argument du périhélie
        $o = deg2rad($o);
        $i = deg2rad($i);

        // Équation de Kepler
        $ea = $m + $e * sin($m);
        for ($k = 0; $k < 10; $k++) {
            $d = ($ea - $e * sin($ea) - $m) / (1 - $e * cos($ea));
            $ea -= $d;
            if (abs($d) < 1e-9) {
                break;
            }
        }
        $xp = $a * (cos($ea) - $e);
        $yp = $a * sqrt(1 - $e * $e) * sin($ea);

        $x = (cos($om) * cos($o) - sin($om) * sin($o) * cos($i)) * $xp + (-sin($om) * cos($o) - cos($om) * sin($o) * cos($i)) * $yp;
        $y = (cos($om) * sin($o) + sin($om) * cos($o) * cos($i)) * $xp + (-sin($om) * sin($o) + cos($om) * cos($o) * cos($i)) * $yp;
        $z = (sin($om) * sin($i)) * $xp + (cos($om) * sin($i)) * $yp;
        return [$x, $y, $z];
    }
}
