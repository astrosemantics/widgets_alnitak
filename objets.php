<?php
/**
 * Endpoint JSON des objets à observer (mêmes données que programme-widget.php et carte-widget.php).
 * GET objets.php?t=<unix> → la nuit vue à un autre instant.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/garde.php';
require_once __DIR__ . '/lib/Nuit.php';
require_once __DIR__ . '/lib/Objets.php';
$cfg = require __DIR__ . '/config.php';

$maintenant = isset($_GET['t']) && ctype_digit((string) $_GET['t']) ? (int) $_GET['t'] : time();
$nuit = Nuit::calculer($cfg, $maintenant);
header('Content-Type: application/json; charset=utf-8');
if (isset($nuit['erreur'])) {
    http_response_code(500);
    echo json_encode($nuit, JSON_UNESCAPED_UNICODE);
    exit;
}
$o = Objets::calculer($cfg, $maintenant, $nuit);

$tz = new DateTimeZone($cfg['fuseau']);
$hm = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('D H:i');
$sortie = ['lieu' => $cfg['lieu'], 'objets' => []];
foreach ($o['rangees'] as $r) {
    $sortie['objets'][] = [
        'nom' => $r['nom'],
        'type' => $r['type'],
        'au_dessus_de_' . (int) ($cfg['hauteur_min_objets'] ?? 25) . '_deg' => array_map(static fn($s) => $hm($s[0]) . ' → ' . $hm($s[1]), $r['segments']),
        'culmination' => $r['culmination'] ? $hm($r['culmination']['ts']) . ' à ' . round($r['culmination']['hauteur']) . '°' : null,
    ];
}
$c = $o['carte'];
$sortie['carte'] = [
    'instant' => $hm($c['instant']),
    'au_zenith' => $c['zenith'],
    'reperes' => array_map(static fn($r) => $r['nom'] . ' : ' . round($r['hauteur']) . '° ' . $r['direction'], $c['reperes']),
    'lune' => $c['lune']['levee'] ? 'levée, ' . round($c['lune']['hauteur']) . '° ' . Objets::direction($c['lune']['azimut']) : 'couchée',
];
echo json_encode($sortie, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
