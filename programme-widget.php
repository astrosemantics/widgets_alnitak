<?php
/**
 * Image PNG du widget « Programme de la nuit », format 4×2, pour KWGT.
 *
 * Une ligne par objet (planètes puis ciel profond, dans l'ordre d'observation) :
 * la barre montre quand il est au-dessus de `hauteur_min_objets` degrés, plus vive
 * pendant la nuit noire ; le point marque sa culmination. Même frise que le widget astro.
 *
 * GET programme-widget.php              → rouge automatique la nuit
 * GET programme-widget.php?theme=rouge  → toujours rouge ; ?theme=normal : jamais
 * GET programme-widget.php?t=<unix>     → la nuit vue à un autre instant (tests)
 *
 * Image transparente de 1002 × 450 px (334 × 150 dp à densité ×3).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/garde.php';
require_once __DIR__ . '/lib/Nuit.php';
require_once __DIR__ . '/lib/Objets.php';
require_once __DIR__ . '/lib/Dessin.php';
require_once __DIR__ . '/lib/Theme.php';
$cfg = require __DIR__ . '/config.php';

$maintenant = isset($_GET['t']) && ctype_digit((string) $_GET['t']) ? (int) $_GET['t'] : time();
$palette = Theme::choisir($cfg, $maintenant);
$d = new Dessin(array_merge($palette['couleurs'], Dessin::couleursDepuisRequete()), 150);
$tz = new DateTimeZone($cfg['fuseau']);

$nuit = Nuit::calculer($cfg, $maintenant);
if (isset($nuit['erreur'])) {
    $d->texte('Calcul impossible', 15, 'medium', 'texte', 4, 40);
    $d->envoyer((int) $cfg['cache']);
    exit;
}
$objets = Objets::calculer($cfg, $maintenant, $nuit);

/* ---------- En-tête ---------- */

$titre = 'Programme de la nuit';
$d->texte($titre, 12, 'medium', 'accent', 4, 12);
$d->texte(' · ' . $cfg['lieu'], 12, 'normal', 'discret', 4 + $d->largeur($titre, 12, 'medium'), 12);
$d->texteDroite('au-dessus de ' . (int) ($cfg['hauteur_min_objets'] ?? 25) . '°', 11, 'normal', 'discret', 330, 12);

/* ---------- Fond : obscurité, créneau ---------- */

$x0 = 90.0;
$l = 240.0;
$duree = (float) ($nuit['fin'] - $nuit['debut']);
$x = static fn(int $ts): float => $x0 + $l * max(0, min($duree, $ts - $nuit['debut'])) / $duree;
$haut = 22.0;
$hauteur = 104.0;

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
    $d->bloc($x($s['debut']), $haut, $x($s['fin']) - $x($s['debut']), $hauteur, $d->hex($palette['obscurite'][$s['etat']], 0.55));
}
$d->arrondirCoins($x0, $haut, $l, $hauteur, 3);

if ($nuit['creneau']) {
    $xa = $x($nuit['creneau']['debut']);
    $xb = $x($nuit['creneau']['fin']);
    $c = $d->couleur('accent');
    $d->trait($xa, $haut - 1, $xb, $haut - 1, 1.4, $c);
    $d->trait($xa, $haut + $hauteur + 1, $xb, $haut + $hauteur + 1, 1.4, $c);
    $d->trait($xa, $haut - 1, $xa, $haut + $hauteur + 1, 1.4, $c);
    $d->trait($xb, $haut - 1, $xb, $haut + $hauteur + 1, 1.4, $c);
}

/* ---------- Une ligne par objet ---------- */

$sombre = $objets['sombre'];
$pale = $d->hex($d->hex['accent'], 0.3);
$vif = $d->couleur('accent');
if (!$objets['rangees']) {
    $d->texte('Aucun objet bien placé cette nuit', 12, 'normal', 'discret', 4, 74);
}
foreach ($objets['rangees'] as $i => $r) {
    $y = 31 + $i * 17;
    $nom = $r['nom'];
    while ($d->largeur($nom, 11.5, 'normal') > 83 && mb_strlen($nom) > 3) {
        $nom = rtrim(mb_substr($nom, 0, mb_strlen($nom) - 2)) . '…';
    }
    $d->texte($nom, 11.5, $r['type'] === 'planete' ? 'medium' : 'normal', 'texte', 4, $y + 4);

    foreach ($r['segments'] as [$a, $b]) {
        $d->rectangle($x($a), $y - 3, max(6, $x($b) - $x($a)), 6, 3, $pale);
        if ($sombre) {
            $a2 = max($a, $sombre['debut']);
            $b2 = min($b, $sombre['fin']);
            if ($b2 > $a2) {
                $d->rectangle($x($a2), $y - 3, max(6, $x($b2) - $x($a2)), 6, 3, $vif);
            }
        }
    }
    if ($r['culmination']) {
        $xc = $x($r['culmination']['ts']);
        $d->disque($xc, $y, 4.2, $d->couleur('fond'));
        $d->disque($xc, $y, 3.0, $d->couleur('texte'));
    }
}

// Heure actuelle
if ($maintenant >= $nuit['debut'] && $maintenant < $nuit['fin']) {
    $xm = $x($maintenant);
    $d->trait($xm, $haut - 2, $xm, $haut + $hauteur + 2, 1.5, $d->couleur('texte'));
}

/* ---------- Heures et légende ---------- */

for ($h = $nuit['debut']; $h <= $nuit['fin']; $h += 3600) {
    $g = (int) (new DateTimeImmutable('@' . $h))->setTimezone($tz)->format('G');
    if ($g % 3 !== 0) {
        continue;
    }
    $xx = $x($h);
    if ($xx < $x0 + 8) {
        $d->texte($g . 'h', 10.5, 'normal', 'discret', $xx, 140);
    } elseif ($xx > 322) {
        $d->texteDroite($g . 'h', 10.5, 'normal', 'discret', $xx, 140);
    } else {
        $d->texte($g . 'h', 10.5, 'normal', 'discret', $xx, 140, true);
    }
}
$d->disque(7, 145.5, 2.6, $d->couleur('texte'));
$d->texte('culmination', 10, 'normal', 'discret', 13, 149);

$d->envoyer((int) $cfg['cache']);
