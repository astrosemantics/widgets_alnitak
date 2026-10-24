<?php
/**
 * Que regarder cette nuit ? Planètes visibles et plus beaux objets du ciel profond,
 * choisis pour la nuit calculée par Nuit::calculer().
 *
 * - Planètes (Mercure à Saturne) : retenues si elles passent au moins 30 min
 *   au-dessus de `hauteur_min_objets` pendant la nuit (après le crépuscule nautique).
 * - Ciel profond (Messier + objets NGC remarquables) : doivent monter à 30° au moins
 *   et rester 1 h au-dessus de `hauteur_min_objets` pendant le créneau (ou la nuit noire).
 *   Classement : intérêt de l'objet (ou éclat), hauteur, et gêne de la Lune
 *   (forte pour les galaxies et nébuleuses, faible pour les amas).
 * - Rangées triées dans l'ordre d'observation (heure de culmination).
 */

declare(strict_types=1);

require_once __DIR__ . '/Astro.php';
require_once __DIR__ . '/Planetes.php';
require_once __DIR__ . '/Nuit.php';

final class Objets
{
    public const PAS = 600;          // échantillonnage : 10 min
    private const RANGEES = 6;
    private const PLANETES_MAX = 3;
    private const DIFFUS = ['s', 'e', 'i', 'sfr', 'rn', 'snr'];

    public static function calculer(array $cfg, int $maintenant, array $nuit): array
    {
        $astro = new Astro((float) $cfg['latitude'], (float) $cfg['longitude']);
        $seuil = (float) ($cfg['hauteur_min_objets'] ?? 25);
        $milieu = (int) (($nuit['debut'] + $nuit['fin']) / 2);

        // Instants d'échantillonnage et état du ciel (obscurité, Lune) à chacun
        $tranches = [];
        foreach ($nuit['tranches'] as $t) {
            $tranches[$t['ts']] = $t;
        }
        $instants = [];
        for ($ts = $nuit['debut']; $ts <= $nuit['fin']; $ts += self::PAS) {
            $tr = $tranches[$ts - ($ts - $nuit['debut']) % Nuit::PAS] ?? null;
            $instants[] = [
                'ts' => $ts,
                'sombre' => $tr !== null && in_array($tr['obscurite'], [Nuit::NAUTIQUE, Nuit::ASTRO, Nuit::NOIRE], true),
                'lune' => $tr !== null && $tr['lune'] ? (float) $tr['fraction'] : 0.0,
                'nuit' => $ts >= $nuit['coucher'] && $ts <= $nuit['lever'],
            ];
        }

        // Fenêtre de choix du ciel profond : le créneau, sinon la nuit noire (ou astronomique)
        $noire = array_values(array_filter($nuit['tranches'], static fn($t) => $t['obscurite'] === Nuit::NOIRE));
        if (!$noire) {
            $noire = array_values(array_filter($nuit['tranches'], static fn($t) => $t['obscurite'] === Nuit::ASTRO));
        }
        $sombre = $noire ? ['debut' => $noire[0]['ts'], 'fin' => end($noire)['ts'] + Nuit::PAS] : null;
        $choix = $nuit['creneau'] ?? $sombre;

        /** Hauteurs d'un astre à chaque instant, et ce qu'on en retient. */
        $profil = static function (float $ra, float $dec) use ($astro, $instants, $seuil): array {
            $h = [];
            foreach ($instants as $i => $in) {
                $h[$i] = $astro->horizontales($ra, $dec, $in['ts'])[0];
            }
            return $h;
        };
        $segments = static function (array $h) use ($instants, $seuil, $nuit): array {
            $segs = [];
            $debut = null;
            foreach ($instants as $i => $in) {
                $ok = $h[$i] >= $seuil && $in['nuit'];
                if ($ok && $debut === null) {
                    $debut = $in['ts'];
                } elseif (!$ok && $debut !== null) {
                    $segs[] = [$debut, $in['ts']];
                    $debut = null;
                }
            }
            if ($debut !== null) {
                $segs[] = [$debut, min($nuit['lever'], end($instants)['ts'])];
            }
            return $segs;
        };
        /** Culmination pendant la nuit (null si l'astre monte encore à l'aube ou descend déjà au crépuscule). */
        $culmination = static function (array $h) use ($instants): ?array {
            $best = null;
            $n = count($instants);
            foreach ($instants as $i => $in) {
                if (!$in['nuit']) {
                    continue;
                }
                if ($best === null || $h[$i] > $h[$best]) {
                    $best = $i;
                }
            }
            if ($best === null || $best === 0 || $best === $n - 1
                || !$instants[$best - 1]['nuit'] || !$instants[$best + 1]['nuit']) {
                return null;
            }
            return ['ts' => $instants[$best]['ts'], 'hauteur' => $h[$best]];
        };

        $rangees = [];

        /* ---------- Planètes ---------- */
        $planetes = [];
        foreach (Planetes::NOMS as $cle => $nom) {
            [$ra, $dec] = Astro::precession(...array_merge(Planetes::equatoriales($cle, $milieu), [$milieu]));
            $h = $profil($ra, $dec);
            $minutes = 0;
            $max = -90.0;
            foreach ($instants as $i => $in) {
                if ($in['sombre'] && $h[$i] >= $seuil) {
                    $minutes += self::PAS / 60;
                    $max = max($max, $h[$i]);
                }
            }
            if ($minutes >= 30) {
                $planetes[] = ['nom' => $nom, 'type' => 'planete', 'ra' => $ra, 'dec' => $dec, 'hauteurs' => $h, 'score' => $max];
            }
        }
        usort($planetes, static fn($a, $b) => $b['score'] <=> $a['score']);
        $planetes = array_slice($planetes, 0, self::PLANETES_MAX);

        /* ---------- Ciel profond ---------- */
        $candidats = [];
        if ($choix !== null) {
            $dans = array_keys(array_filter($instants, static fn($in) => $in['ts'] >= $choix['debut'] && $in['ts'] < $choix['fin']));
            $dureeChoix = count($dans) * self::PAS / 60;
            foreach (require __DIR__ . '/donnees/objets.php' as [$nom, $type, $ra0, $dec0, $mag, $interet]) {
                // Tri rapide : un objet qui ne monte jamais à 30° est écarté sans calcul détaillé
                if ($dec0 < (float) $cfg['latitude'] - 60) {
                    continue;
                }
                [$ra, $dec] = Astro::precession($ra0, $dec0, $milieu);
                $h = $profil($ra, $dec);
                $minutes = 0;
                $max = -90.0;
                $luneGene = 0.0;
                foreach ($dans as $i) {
                    $max = max($max, $h[$i]);
                    if ($h[$i] >= $seuil) {
                        $minutes += self::PAS / 60;
                        $luneGene += $instants[$i]['lune'];
                    }
                }
                if ($max < 30 || $minutes < min(60, $dureeChoix / 2)) {
                    continue;
                }
                $luneGene /= max(1, $minutes / (self::PAS / 60));   // 0 (pas de Lune) à 1 (pleine Lune levée)
                $base = $interet > 0 ? (float) $interet : max(0.5, 8.5 - (float) $mag) * 0.6;
                $facteurLune = in_array($type, self::DIFFUS, true) ? 1 - 0.8 * $luneGene
                    : ($type === 'oc' ? 1 - 0.1 * $luneGene : 1 - 0.3 * $luneGene);
                $score = $base * (0.6 + 0.4 * sin(deg2rad($max))) * $facteurLune;
                $candidats[] = ['nom' => $nom, 'type' => $type, 'ra' => $ra, 'dec' => $dec, 'hauteurs' => $h, 'score' => $score];
            }
            usort($candidats, static fn($a, $b) => $b['score'] <=> $a['score']);
            $candidats = array_slice($candidats, 0, self::RANGEES - count($planetes));
        }

        /* ---------- Rangées : planètes puis ciel profond, chacun dans l'ordre de culmination ---------- */
        $finir = static function (array $o) use ($segments, $culmination): array {
            $c = $culmination($o['hauteurs']);
            $o['segments'] = $segments($o['hauteurs']);
            $o['culmination'] = $c;
            $o['ordre'] = $c['ts'] ?? ($o['segments'][0][0] ?? PHP_INT_MAX);
            unset($o['hauteurs']);
            return $o;
        };
        $trier = static fn($a, $b) => $a['ordre'] <=> $b['ordre'];
        $planetes = array_map($finir, $planetes);
        $candidats = array_map($finir, $candidats);
        usort($planetes, $trier);
        usort($candidats, $trier);
        $rangees = array_merge($planetes, $candidats);

        return [
            'rangees' => $rangees,
            'sombre' => $sombre,
            'choix' => $choix,
            'carte' => self::carte($cfg, $astro, $maintenant, $nuit, $rangees, $sombre),
        ];
    }

    /** Le ciel à un instant : maintenant pendant la nuit, sinon au milieu du créneau. */
    private static function carte(array $cfg, Astro $astro, int $maintenant, array $nuit, array $rangees, ?array $sombre): array
    {
        $soleil = $astro->hauteurSoleil($maintenant);
        if ($maintenant >= $nuit['coucher'] && $maintenant < $nuit['lever'] && $soleil < -6) {
            $instant = $maintenant - $maintenant % 300;
            $maintenantOk = true;
        } else {
            $c = $nuit['creneau'] ?? $sombre ?? ['debut' => $nuit['coucher'], 'fin' => $nuit['lever']];
            $instant = (int) (round((($c['debut'] + $c['fin']) / 2) / 1800) * 1800);
            $maintenantOk = false;
        }

        $reperes = [];
        foreach ($rangees as $r) {
            [$alt, $az] = $astro->horizontales($r['ra'], $r['dec'], $instant);
            if ($alt >= 15 && count($reperes) < 4) {
                $reperes[] = ['nom' => $r['nom'], 'hauteur' => $alt, 'azimut' => $az, 'direction' => self::direction($az)];
            }
        }

        $lune = $astro->lunePosition($instant);
        [$lra, $ldec] = Astro::luneEquatoriales($instant);
        [, $laz] = $astro->horizontales($lra, $ldec, $instant);

        return [
            'instant' => $instant,
            'maintenant' => $maintenantOk,
            'reperes' => $reperes,
            'lune' => ['hauteur' => $lune['hauteur'], 'azimut' => $laz, 'fraction' => $lune['fraction'], 'levee' => $lune['levee']],
            'zenith' => self::constellationAuZenith($astro, $instant),
        ];
    }

    public static function direction(float $az): string
    {
        $noms = ['nord', 'nord-est', 'est', 'sud-est', 'sud', 'sud-ouest', 'ouest', 'nord-ouest'];
        return $noms[(int) round($az / 45) % 8];
    }

    /** Constellation qui contient le zénith (limites officielles, test point-dans-polygone). */
    private static function constellationAuZenith(Astro $astro, int $ts): ?string
    {
        [$ra, $dec] = $astro->zenith($ts);
        // Les limites sont données en J2000 : on ramène le zénith à J2000 (précession inverse)
        [$ra2, $dec2] = Astro::precession($ra, $dec, 2 * 946728000 - $ts);
        $ciel = require __DIR__ . '/donnees/ciel.php';
        foreach ($ciel['limites'] as [$nom, $poly]) {
            // Ascensions droites « déroulées » pour que le polygone soit d'un seul tenant
            $pts = [];
            $prec = null;
            foreach ($poly as [$r, $dd]) {
                if ($prec !== null) {
                    $r += 360 * round(($prec - $r) / 360);
                }
                $pts[] = [$r, $dd];
                $prec = $r;
            }
            foreach ([$ra2 - 360, $ra2, $ra2 + 360] as $px) {
                $dedans = false;
                $n = count($pts);
                for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                    [$xi, $yi] = $pts[$i];
                    [$xj, $yj] = $pts[$j];
                    if (($yi > $dec2) !== ($yj > $dec2) && $px < $xi + ($xj - $xi) * ($dec2 - $yi) / ($yj - $yi)) {
                        $dedans = !$dedans;
                    }
                }
                if ($dedans) {
                    return $nom;
                }
            }
        }
        return null;
    }
}
