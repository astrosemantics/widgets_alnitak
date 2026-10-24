<?php
/**
 * Image PNG du widget « Carte du ciel », format 4×2, pour KWGT.
 *
 * À gauche, le ciel vu depuis le site (zénith au centre, nord en haut, est à gauche,
 * comme une carte tenue au-dessus de la tête) : étoiles jusqu'à la magnitude 4,2,
 * tracés des constellations, Lune, et repères numérotés des objets du programme.
 * À droite, la liste des repères avec leur hauteur et leur direction.
 *
 * Le ciel est montré maintenant pendant la nuit, sinon au milieu du créneau.
 *
 * GET carte-widget.php              → rouge automatique la nuit
 * GET carte-widget.php?theme=rouge  → toujours rouge ; ?theme=normal : jamais
 * GET carte-widget.php?t=<unix>     → à un autre instant (tests)
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
$hm = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('G:i');

$nuit = Nuit::calculer($cfg, $maintenant);
if (isset($nuit['erreur'])) {
    $d->texte('Calcul impossible', 15, 'medium', 'texte', 4, 40);
    $d->envoyer((int) $cfg['cache']);
    exit;
}
$carte = Objets::calculer($cfg, $maintenant, $nuit)['carte'];
$instant = $carte['instant'];
$astro = new Astro((float) $cfg['latitude'], (float) $cfg['longitude']);

/** Mélange de deux couleurs hex (pour des traits fins sans effet de transparence cumulée). */
$melange = static function (string $a, string $b, float $t): string {
    $ca = array_map('hexdec', str_split($a, 2));
    $cb = array_map('hexdec', str_split($b, 2));
    return implode('', array_map(static fn($x, $y) => str_pad(dechex((int) round($x * $t + $y * (1 - $t))), 2, '0', STR_PAD_LEFT), $ca, $cb));
};

/* ---------- Le dôme du ciel ---------- */

$cx = 72.0;
$cy = 76.0;
$rayon = 62.0;
/** Hauteur / azimut → position sur la carte (projection azimutale équidistante, est à gauche). */
$projeter = static function (float $alt, float $az) use ($cx, $cy, $rayon): array {
    $r = (90 - $alt) / 90 * $rayon;
    return [$cx - $r * sin(deg2rad($az)), $cy - $r * cos(deg2rad($az))];
};

$fondCiel = $palette['ciel'];
$d->disque($cx, $cy, $rayon + 2, $d->hex($fondCiel, 0.92));
$d->cercle($cx, $cy, $rayon, 1.2, $d->hex($melange($d->hex['accent'], $fondCiel, 0.55)));
foreach ([2 / 3, 1 / 3] as $f) {   // 30° et 60° de hauteur
    $d->cercle($cx, $cy, $rayon * $f, 0.8, $d->hex($melange($d->hex['discret'], $fondCiel, 0.3)), 2, 3);
}

$ciel = require __DIR__ . '/lib/donnees/ciel.php';

// Tracés des constellations (découpés à l'horizon)
$cTrait = $d->hex($melange($d->hex['discret'], $fondCiel, 0.5));
foreach ($ciel['lignes'] as $ligne) {
    $prec = null;
    foreach ($ligne as [$ra, $dec]) {
        [$ra, $dec] = Astro::precession((float) $ra, (float) $dec, $instant);
        $pt = $astro->horizontales($ra, $dec, $instant);
        if ($prec !== null) {
            // Morceaux de 8 pas pour arrêter le trait proprement à l'horizon
            for ($k = 0; $k < 8; $k++) {
                $a1 = $prec[0] + ($pt[0] - $prec[0]) * $k / 8;
                $a2 = $prec[0] + ($pt[0] - $prec[0]) * ($k + 1) / 8;
                $daz = fmod($pt[1] - $prec[1] + 540, 360) - 180;
                $z1 = $prec[1] + $daz * $k / 8;
                $z2 = $prec[1] + $daz * ($k + 1) / 8;
                if ($a1 >= 0 && $a2 >= 0) {
                    [$x1, $y1] = $projeter($a1, $z1);
                    [$x2, $y2] = $projeter($a2, $z2);
                    $d->trait($x1, $y1, $x2, $y2, 0.7, $cTrait);
                }
            }
        }
        $prec = $pt;
    }
}

// Étoiles
foreach ($ciel['etoiles'] as [$ra, $dec, $mag]) {
    [$ra, $dec] = Astro::precession((float) $ra, (float) $dec, $instant);
    [$alt, $az] = $astro->horizontales($ra, $dec, $instant);
    if ($alt < 0) {
        continue;
    }
    [$px, $py] = $projeter($alt, $az);
    $r = max(0.45, 1.6 - 0.3 * (float) $mag);
    $d->disque($px, $py, $r, $d->hex($melange($palette['etoiles'], $fondCiel, max(0.45, min(1.0, 1.15 - 0.15 * (float) $mag)))));
}

// Lune
if ($carte['lune']['levee'] && $carte['lune']['hauteur'] > -1) {
    [$px, $py] = $projeter(max(0.0, $carte['lune']['hauteur']), $carte['lune']['azimut']);
    $d->disque($px, $py, 4.5, $d->hex($palette['lune']));
}

// Points cardinaux (est à gauche : on regarde le ciel au-dessus de soi)
$d->texte('N', 9, 'medium', 'discret', $cx, $cy - $rayon - 4, true);
$d->texte('S', 9, 'medium', 'discret', $cx, $cy + $rayon + 10.5, true);
$d->texte('E', 9, 'medium', 'discret', $cx - $rayon - 6.5, $cy + 3.2, true);
$d->texte('O', 9, 'medium', 'discret', $cx + $rayon + 6.5, $cy + 3.2, true);

// Repères numérotés
foreach ($carte['reperes'] as $i => $r) {
    [$px, $py] = $projeter($r['hauteur'], $r['azimut']);
    $d->cercle($px, $py, 5.5, 1.4, $d->couleur('accent'));
    $d->texte((string) ($i + 1), 8.5, 'gras', 'accent', $px + 6.5, $py - 5);
}

/* ---------- Liste à droite ---------- */

$xl = 156.0;
$titre = 'Le ciel à ' . $hm($instant);
$d->texte($titre, 12, 'medium', 'accent', $xl, 12);
$d->texteDroite($cfg['lieu'], 11, 'normal', 'discret', 330, 12);

if (!$carte['reperes']) {
    $d->texte('Aucun objet du programme', 12, 'normal', 'discret', $xl, 40);
    $d->texte('au-dessus de 15° à cette heure', 12, 'normal', 'discret', $xl, 56);
}
foreach ($carte['reperes'] as $i => $r) {
    $y = 36 + $i * 27;
    $d->cercle($xl + 6, $y - 4, 6.5, 1.4, $d->couleur('accent'));
    $d->texte((string) ($i + 1), 8.5, 'gras', 'accent', $xl + 6, $y - 0.8, true);
    $nom = $r['nom'];
    while ($d->largeur($nom, 13, 'medium') > 154 && mb_strlen($nom) > 3) {
        $nom = rtrim(mb_substr($nom, 0, mb_strlen($nom) - 2)) . '…';
    }
    $d->texte($nom, 13, 'medium', 'texte', $xl + 18, $y);
    $d->texte(round($r['hauteur']) . '° · ' . $r['direction'], 10.5, 'normal', 'discret', $xl + 18, $y + 13);
}

$pied = $carte['zenith'] ? 'Au zénith : ' . $carte['zenith'] : '';
$lune = $carte['lune']['levee']
    ? 'Lune ' . (int) round($carte['lune']['fraction'] * 100) . ' % ' . Objets::direction($carte['lune']['azimut'])
    : 'Lune couchée';
$pied = $pied !== '' ? $pied . ' · ' . $lune : $lune;
if ($d->largeur($pied, 10.5, 'normal') > 174) {
    $pied = ($carte['zenith'] ? 'Zénith : ' . $carte['zenith'] . ' · ' : '')
        . ($carte['lune']['levee'] ? 'Lune ' . (int) round($carte['lune']['fraction'] * 100) . ' %' : 'Lune couchée');
}
$d->texte($pied, 10.5, 'normal', 'discret', $xl, 146);

$d->envoyer((int) $cfg['cache']);
