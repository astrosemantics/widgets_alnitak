<?php
/**
 * Endpoint JSON du widget astro (mêmes données que astro-widget.php).
 * GET astro.php?t=<unix> → la nuit vue à un autre instant.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/garde.php';
require_once __DIR__ . '/lib/Nuit.php';
$cfg = require __DIR__ . '/config.php';

$maintenant = isset($_GET['t']) && ctype_digit((string) $_GET['t']) ? (int) $_GET['t'] : time();
$n = Nuit::calculer($cfg, $maintenant);

$tz = new DateTimeZone($cfg['fuseau']);
$hm = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('D H:i');
foreach (['debut', 'fin', 'maintenant', 'coucher', 'lever'] as $k) {
    if (isset($n[$k])) {
        $n[$k] = $hm($n[$k]);
    }
}
if (!empty($n['creneau'])) {
    $n['creneau'] = ['de' => $hm($n['creneau']['debut']), 'a' => $hm($n['creneau']['fin'])];
}
// Une ligne par heure plutôt que par tranche de 5 min
$heures = [];
foreach ($n['tranches'] ?? [] as $t) {
    if ($t['ts'] % 3600 === 0) {
        $heures[] = [
            'heure' => $hm($t['ts']),
            'obscurite' => $t['obscurite'],
            'lune' => $t['lune'] ? round($t['fraction'] * 100) . ' %' : 'couchée',
            'nuages' => $t['nuages'],
            'gene_nuages' => $t['gene'],
            'seeing' => $t['seeing'],
        ];
    }
}
$n['tranches'] = $heures;
$n['source'] = 'Soleil et Lune : calcul local (Meeus). Nuages et courant-jet : Open-Meteo (CC BY 4.0). Seeing : estimation.';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=' . (int) $cfg['cache']);
echo json_encode($n, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
