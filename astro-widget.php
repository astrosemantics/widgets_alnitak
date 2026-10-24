<?php
/**
 * Image PNG du widget astro « Ce soir sous les étoiles », format 4×2, pour KWGT.
 *
 * Haut : note du ciel sur 100, qualité de la nuit, meilleur créneau, nuit noire et Lune.
 * Bas  : frise de la nuit (obscurité, Lune levée, nuages, stabilité estimée, créneau).
 *
 * GET astro-widget.php              → rouge automatique la nuit (config.php : theme, rouge_soleil_sous)
 * GET astro-widget.php?theme=rouge  → toujours rouge (vision de nuit) ; ?theme=normal : jamais
 * GET astro-widget.php?t=<unix>     → la nuit vue à un autre instant (tests)
 *
 * Image transparente de 1002 × 450 px (334 × 150 dp à densité ×3).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/garde.php';
require_once __DIR__ . '/lib/Nuit.php';
require_once __DIR__ . '/lib/Dessin.php';
require_once __DIR__ . '/lib/Theme.php';
$cfg = require __DIR__ . '/config.php';

$hauteurWidget = 150;

$maintenant = isset($_GET['t']) && ctype_digit((string) $_GET['t']) ? (int) $_GET['t'] : time();
$palette = Theme::choisir($cfg, $maintenant);   // rouge automatique la nuit (lib/Theme.php)

$nuit = Nuit::calculer($cfg, $maintenant);
$d = new Dessin(array_merge($palette['couleurs'], Dessin::couleursDepuisRequete()), $hauteurWidget);
$tz = new DateTimeZone($cfg['fuseau']);

/* ---------- Haut : pastille + trois lignes ---------- */

$d->pastille();
$note = $nuit['note'] ?? null;
$d->anneau($note ?? 0);
$d->texte($note === null ? '–' : (string) $note, 22, 'gras', 'accent', Dessin::CX, Dessin::CY + 5, true);
$d->texte('CIEL', 9, 'medium', 'label_accent', Dessin::CX, Dessin::CY + 17, true);

if (isset($nuit['erreur'])) {
    $d->texte('Calcul impossible', 15, 'medium', 'texte', 82, 41);
    $d->envoyer((int) $cfg['cache']);
    exit;
}
$d->texte($nuit['ligne1'], 12, 'normal', 'accent', 82, 22);
$d->texte($nuit['ligne2'], 18, 'medium', 'texte', 82, 45);
$d->texte($nuit['ligne3'], 13, 'normal', 'discret', 82, 63);

/* ---------- Bas : frise de la nuit ---------- */

$oy = 80.0;            // haut de la frise
$x0 = 4.0;
$l = 326.0;
$duree = (float) ($nuit['fin'] - $nuit['debut']);
$x = static fn(int $ts): float => $x0 + $l * max(0, min($duree, $ts - $nuit['debut'])) / $duree;
$yBande = $oy + 6;
$hBande = 16.0;

// Obscurité : segments contigus de même niveau
$segments = [];
foreach ($nuit['tranches'] as $t) {
    $n = count($segments);
    if ($n && $segments[$n - 1]['etat'] === $t['obscurite']) {
        $segments[$n - 1]['fin'] = $t['ts'] + Nuit::PAS;
    } else {
        $segments[] = ['etat' => $t['obscurite'], 'debut' => $t['ts'], 'fin' => $t['ts'] + Nuit::PAS];
    }
}
foreach ($segments as $s) {
    $d->bloc($x($s['debut']), $yBande, $x($s['fin']) - $x($s['debut']), $hBande, $d->hex($palette['obscurite'][$s['etat']]));
}

// Nuages : hachures heure par heure, d'autant plus visibles que le ciel est couvert
$parHeure = [];
foreach ($nuit['tranches'] as $t) {
    $parHeure[$t['ts'] - $t['ts'] % 3600][] = $t;
}
foreach ($parHeure as $heure => $ts) {
    $c = $ts[(int) (count($ts) / 2)]['nuages'];
    if ($c !== null && $c >= 10) {
        $xa = $x($heure);
        $xb = $x($heure + 3600);
        $d->hachures($xa, $yBande, $xb - $xa, $hBande, $d->hex($palette['nuages'], min(0.85, $c / 100)), 4, 1.1);
    }
}
$d->arrondirCoins($x0, $yBande, $l, $hBande, 5);

// Meilleur créneau : cadre
if ($nuit['creneau']) {
    $xa = $x($nuit['creneau']['debut']) - 0.5;
    $xb = $x($nuit['creneau']['fin']) + 0.5;
    $c = $d->couleur('accent');
    $y1 = $yBande - 1;
    $y2 = $yBande + $hBande + 1;
    $d->trait($xa, $y1, $xb, $y1, 1.8, $c);
    $d->trait($xa, $y2, $xb, $y2, 1.8, $c);
    $d->trait($xa, $y1, $xa, $y2, 1.8, $c);
    $d->trait($xb, $y1, $xb, $y2, 1.8, $c);
}

// Lune levée : trait au-dessus de la bande, point au lever / coucher
$cLune = $d->hex($palette['lune']);
$debutLune = null;
$prec = null;
foreach ($nuit['tranches'] as $i => $t) {
    if ($t['lune'] && $debutLune === null) {
        $debutLune = $t['ts'];
        if ($prec !== null) {
            $d->trait($x($t['ts']), $oy + 1.5, $x($t['ts']), $oy + 1.5, 4.5, $cLune);
        }
    }
    $dernier = $i === count($nuit['tranches']) - 1;
    if ($debutLune !== null && (!$t['lune'] || $dernier)) {
        $finLune = $t['lune'] ? $t['ts'] + Nuit::PAS : $t['ts'];
        $d->trait($x($debutLune) + 1, $oy + 1.5, $x($finLune), $oy + 1.5, 2, $cLune);
        if (!$t['lune']) {
            $d->trait($x($finLune), $oy + 1.5, $x($finLune), $oy + 1.5, 4.5, $cLune);
        }
        $debutLune = null;
    }
    $prec = $t;
}

// Stabilité estimée (seeing), une case par heure
foreach ($parHeure as $heure => $ts) {
    $s = $ts[(int) (count($ts) / 2)]['seeing'];
    if ($s === null) {
        continue;
    }
    $xa = $x($heure) + 0.8;
    $xb = $x($heure + 3600) - 0.8;
    if ($xb - $xa > 2) {
        $d->rectangle($xa, $oy + 26, $xb - $xa, 6, 2, $d->nuance('accent', 0.15 + 0.21 * ($s - 1)));
    }
}

// Heure actuelle
if ($maintenant >= $nuit['debut'] && $maintenant < $nuit['fin']) {
    $xm = $x($maintenant);
    $d->trait($xm, $yBande - 2, $xm, $yBande + $hBande + 2, 1.5, $d->couleur('texte'));
}

// Heures rondes, toutes les 3 h
for ($h = $nuit['debut']; $h <= $nuit['fin']; $h += 3600) {
    $g = (int) (new DateTimeImmutable('@' . $h))->setTimezone($tz)->format('G');
    if ($g % 3 !== 0) {
        continue;
    }
    $xx = $x($h);
    $txt = $g . 'h';
    if ($xx < 12) {
        $d->texte($txt, 11, 'normal', 'discret', $xx, $oy + 45);
    } elseif ($xx > 318) {
        $d->texteDroite($txt, 11, 'normal', 'discret', $xx, $oy + 45);
    } else {
        $d->texte($txt, 11, 'normal', 'discret', $xx, $oy + 45, true);
    }
}

/* ---------- Légende ---------- */

$yl = $oy + 55;
$xl = 4.0;
$entree = static function (string $txt) use ($d, &$xl, $yl): void {
    $d->texte($txt, 10.5, 'normal', 'discret', $xl, $yl + 8);
    $xl += $d->largeur($txt, 10.5, 'normal') + 12;
};
$d->rectangle($xl, $yl, 9, 9, 2, $d->hex($palette['obscurite']['noire']));
$d->trait($xl + 0.4, $yl + 0.4, $xl + 8.6, $yl + 0.4, 0.8, $d->hex($palette['liseré']));
$d->trait($xl + 0.4, $yl + 8.6, $xl + 8.6, $yl + 8.6, 0.8, $d->hex($palette['liseré']));
$d->trait($xl + 0.4, $yl + 0.4, $xl + 0.4, $yl + 8.6, 0.8, $d->hex($palette['liseré']));
$d->trait($xl + 8.6, $yl + 0.4, $xl + 8.6, $yl + 8.6, 0.8, $d->hex($palette['liseré']));
$xl += 13;
$entree('nuit noire');
$d->trait($xl, $yl + 4.5, $xl + 10, $yl + 4.5, 2, $cLune);
$xl += 14;
$entree('Lune');
$d->rectangle($xl, $yl, 9, 9, 2, $d->hex($palette['obscurite']['noire']));
$d->hachures($xl, $yl, 9, 9, $d->hex($palette['nuages'], 0.6), 4, 1.1);
$xl += 13;
$entree('nuages');
foreach ([2, 3, 4] as $i => $s) {
    $d->rectangle($xl + 6 * $i, $yl + 2, 5, 5, 1, $d->nuance('accent', 0.15 + 0.21 * ($s - 1)));
}
$xl += 21;
$entree('stabilité');
$c = $d->couleur('accent');
$d->trait($xl + 0.8, $yl + 0.8, $xl + 8.2, $yl + 0.8, 1.6, $c);
$d->trait($xl + 0.8, $yl + 8.2, $xl + 8.2, $yl + 8.2, 1.6, $c);
$d->trait($xl + 0.8, $yl + 0.8, $xl + 0.8, $yl + 8.2, 1.6, $c);
$d->trait($xl + 8.2, $yl + 0.8, $xl + 8.2, $yl + 8.2, 1.6, $c);
$xl += 13;
$entree('créneau');

$d->envoyer((int) $cfg['cache']);
