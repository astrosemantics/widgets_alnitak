<?php
/**
 * La nuit d'observation : crépuscules, Lune, nuages, stabilité, meilleur créneau et note.
 */

declare(strict_types=1);

require_once __DIR__ . '/Astro.php';
require_once __DIR__ . '/Ciel.php';

final class Nuit
{
    public const PAS = 300; // tranches de 5 minutes

    // Obscurité, de la plus claire à la plus noire
    public const JOUR = 'jour';
    public const CIVIL = 'civil';
    public const NAUTIQUE = 'nautique';
    public const ASTRO = 'astronomique';
    public const NOIRE = 'noire';

    public static function calculer(array $cfg, int $maintenant): array
    {
        $tz = new DateTimeZone($cfg['fuseau']);
        $astro = new Astro((float) $cfg['latitude'], (float) $cfg['longitude']);

        // Quelle nuit afficher : celle en cours, sinon la prochaine
        $midi = static function (int $ts, int $decalageJours) use ($tz): int {
            $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->modify("$decalageJours day")->format('Y-m-d');
            return (new DateTimeImmutable($d . ' 12:00', $tz))->getTimestamp();
        };
        $nuit = self::bornes($astro, $midi($maintenant, -1));
        if ($nuit === null || $maintenant >= $nuit['lever']) {
            $nuit = self::bornes($astro, $midi($maintenant, 0));
        }
        if ($nuit === null) {
            return ['erreur' => 'pas de coucher de soleil calculable'];
        }

        // Fenêtre de la frise : 30 min avant le coucher → 30 min après le lever, en heures rondes
        $debut = (int) (floor(($nuit['coucher'] - 1800) / 3600) * 3600);
        $fin = (int) (ceil(($nuit['lever'] + 1800) / 3600) * 3600);

        // Prévisions météo
        $dossier = dirname(__DIR__) . '/cache';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0755, true);
        }
        $fichier = (is_writable($dossier) ? $dossier : sys_get_temp_dir()) . '/ciel.json';
        $ciel = (new Ciel((float) $cfg['latitude'], (float) $cfg['longitude'], (string) $cfg['modele'], $fichier))->horaire();

        $seuilNuages = (float) $cfg['seuil_nuages'];
        $tranches = [];
        for ($ts = $debut; $ts < $fin; $ts += self::PAS) {
            $milieu = $ts + self::PAS / 2;
            $h = $astro->hauteurSoleil($milieu);
            $obscurite = $h > -0.833 ? self::JOUR : ($h > -6 ? self::CIVIL : ($h > -12 ? self::NAUTIQUE : ($h > -18 ? self::ASTRO : self::NOIRE)));
            $lune = $astro->lunePosition($milieu);
            $meteo = $ciel[$milieu - $milieu % 3600] ?? null;
            $tranches[] = [
                'ts' => $ts,
                'obscurite' => $obscurite,
                'lune' => $lune['levee'],
                'fraction' => $lune['fraction'],
                'nuages' => $meteo ? $meteo['total'] : null,
                'gene' => $meteo ? Ciel::geneNuages($meteo) : null,
                'seeing' => $meteo ? Ciel::seeing($meteo['jet']) : null,
            ];
        }

        // Ce qu'on appelle « nuit » : la nuit noire si elle existe, sinon la nuit astronomique (été)
        $aNuitNoire = (bool) array_filter($tranches, static fn($t) => $t['obscurite'] === self::NOIRE);
        $estSombre = static fn(array $t): bool => $aNuitNoire
            ? $t['obscurite'] === self::NOIRE
            : in_array($t['obscurite'], [self::ASTRO, self::NOIRE], true);
        $sombres = array_values(array_filter($tranches, $estSombre));

        // Meilleur créneau (sur ce qui reste de la nuit) : sombre, dégagé, sans Lune gênante
        $restantes = array_values(array_filter($tranches, static fn($t) => $t['ts'] + self::PAS > $maintenant));
        $degage = static fn(array $t): bool => $t['gene'] !== null && $t['gene'] <= $seuilNuages;
        $sansLune = static fn(array $t): bool => !$t['lune'] || $t['fraction'] < 0.15;
        $creneau = self::plusLongue($restantes, static fn($t) => $estSombre($t) && $degage($t) && $sansLune($t));
        $avecLune = false;
        if ($creneau === null) {
            $creneau = self::plusLongue($restantes, static fn($t) => $estSombre($t) && $degage($t));
            $avecLune = $creneau !== null;
        }
        if ($creneau !== null && $creneau['fin'] - $creneau['debut'] < 45 * 60) {
            $creneau = null;
            $avecLune = false;
        }

        // Note sur 100 (sur toute la nuit sombre)
        $moyenne = static function (array $xs, callable $f, float $defaut): float {
            if (!$xs) {
                return $defaut;
            }
            $s = 0.0;
            foreach ($xs as $x) {
                $s += $f($x);
            }
            return $s / count($xs);
        };
        $clarte = $moyenne($sombres, static fn($t) => $t['gene'] === null ? 0.5 : 1 - min(100.0, $t['gene']) / 100, 0.0);
        $noirceur = $moyenne($sombres, static fn($t) => $t['lune'] ? 1 - $t['fraction'] : 1.0, 0.0);
        $stabilite = $moyenne($sombres, static fn($t) => $t['seeing'] === null ? 0.6 : $t['seeing'] / 5, 0.0);
        $note = $ciel === null ? null : (int) round(100 * (0.55 * $clarte + 0.30 * $noirceur + 0.15 * $stabilite));

        // Textes
        $hm = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('G:i');
        // « ce soir » avant le coucher du soleil ; pendant la nuit, pas de complément
        $quand = $maintenant >= $nuit['coucher'] ? '' : ' ce soir';
        if ($note === null) {
            $titre = 'Météo indisponible';
        } elseif ($note >= 70) {
            $titre = 'Belle nuit' . $quand;
        } elseif ($note >= 45) {
            $titre = 'Nuit correcte' . $quand;
        } elseif ($note >= 20) {
            $titre = 'Nuit médiocre' . $quand;
        } else {
            $titre = 'Ciel bouché' . $quand;
        }
        $ligne1 = $titre . ' · ' . $cfg['lieu'];

        if ($creneau === null) {
            $ligne2 = 'Pas de créneau dégagé';
        } else {
            $ligne2 = $hm($creneau['debut']) . ' – ' . $hm($creneau['fin']);
            $ligne2 .= $avecLune ? ' avec Lune' : ' sans Lune';
        }

        $fractionSoir = $tranches ? $tranches[(int) (count($tranches) / 2)]['fraction'] : 0.0;
        $pctLune = (int) round($fractionSoir * 100);
        if ($sombres) {
            $ligne3 = ($aNuitNoire ? 'nuit noire ' : 'nuit astro. ') . $hm($sombres[0]['ts']) . ' – '
                . $hm(end($sombres)['ts'] + self::PAS) . ' · Lune ' . $pctLune . ' %';
        } else {
            $ligne3 = 'pas de nuit noire · Lune ' . $pctLune . ' %';
        }

        return [
            'lieu' => $cfg['lieu'],
            'debut' => $debut,
            'fin' => $fin,
            'maintenant' => $maintenant,
            'coucher' => $nuit['coucher'],
            'lever' => $nuit['lever'],
            'note' => $note,
            'detail_note' => ['clarte' => round($clarte, 2), 'noirceur' => round($noirceur, 2), 'stabilite' => round($stabilite, 2)],
            'creneau' => $creneau,
            'creneau_avec_lune' => $avecLune,
            'meteo_disponible' => $ciel !== null,
            'ligne1' => $ligne1,
            'ligne2' => $ligne2,
            'ligne3' => $ligne3,
            'tranches' => $tranches,
        ];
    }

    /** Coucher et lever du soleil encadrant la nuit qui suit $midi. */
    private static function bornes(Astro $astro, int $midi): ?array
    {
        $coucher = null;
        $prec = $astro->hauteurSoleil($midi);
        for ($ts = $midi + 60; $ts <= $midi + 86400; $ts += 60) {
            $h = $astro->hauteurSoleil($ts);
            if ($coucher === null && $prec > -0.833 && $h <= -0.833) {
                $coucher = $ts;
            } elseif ($coucher !== null && $prec <= -0.833 && $h > -0.833) {
                return ['coucher' => $coucher, 'lever' => $ts];
            }
            $prec = $h;
        }
        return null;
    }

    /** Plus longue suite de tranches qui vérifient $test : [debut, fin] ou null. */
    private static function plusLongue(array $tranches, callable $test): ?array
    {
        $meilleur = null;
        $courant = null;
        foreach ($tranches as $t) {
            if ($test($t)) {
                $courant = $courant ?? ['debut' => $t['ts'], 'fin' => $t['ts']];
                $courant['fin'] = $t['ts'] + self::PAS;
                if ($meilleur === null || $courant['fin'] - $courant['debut'] > $meilleur['fin'] - $meilleur['debut']) {
                    $meilleur = $courant;
                }
            } else {
                $courant = null;
            }
        }
        return $meilleur;
    }
}
