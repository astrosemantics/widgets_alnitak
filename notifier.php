<?php
/**
 * Alertes « Belle nuit » pour le club, via ntfy (https://ntfy.sh).
 *
 * À appeler toutes les 5 minutes :
 *   - par un service de cron en ligne : http(s)://votre-site/astro/notifier.php?cle=<notifier_cle>
 *   - ou par le cron de l'hébergement : php /chemin/astro/notifier.php
 *
 * Envoie, au plus une fois par nuit :
 *   - « Belle nuit ce soir à <lieu> » à partir de `preavis_coucher_min` minutes avant le coucher
 *     du soleil (et jusqu'au coucher), si la note du ciel atteint `seuil_alerte` et qu'il y a
 *     un créneau dégagé ;
 *   - « Le ciel se couvre à <lieu> » si, après cette annonce, la prévision se dégrade
 *     (note sous `seuil_couvert` ou plus de créneau dégagé d'au moins 45 min).
 *
 * Tester l'envoi    : notifier.php?cle=<notifier_cle>&essai=1
 * Simuler (terminal) : php notifier.php --simulation --t=<timestamp>   (n'envoie rien)
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/garde.php';
require_once __DIR__ . '/lib/Nuit.php';
$cfg = require __DIR__ . '/config.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}
if (trim((string) ($cfg['ntfy_sujet'] ?? '')) === '' || trim((string) ($cfg['notifier_cle'] ?? '')) === '') {
    http_response_code(503);
    echo "Alertes non configurées : renseigner 'ntfy_sujet' et 'notifier_cle' dans config.php.\n";
    exit;
}

$opts = $cli ? getopt('', ['essai', 'simulation', 't:']) : [];
if (!$cli && !hash_equals((string) $cfg['notifier_cle'], (string) ($_GET['cle'] ?? ''))) {
    http_response_code(403);
    echo "Clé manquante ou incorrecte.\n";
    exit;
}
$essai = $cli ? isset($opts['essai']) : !empty($_GET['essai']);
$simulation = $cli && isset($opts['simulation']);
$maintenant = ($cli && isset($opts['t'])) ? (int) $opts['t'] : time();

$lieu = (string) $cfg['lieu'];
$tz = new DateTimeZone($cfg['fuseau']);
$hm = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('G:i');

/** Envoi d'un message ntfy (JSON). */
$envoyer = static function (string $titre, string $message, array $tags, int $priorite = 3) use ($cfg, $simulation): bool {
    if ($simulation) {
        echo "[SIMULATION] $titre\n             $message\n";
        return true;
    }
    $corps = json_encode([
        'topic' => $cfg['ntfy_sujet'],
        'title' => $titre,
        'message' => $message,
        'tags' => $tags,
        'priority' => $priorite,
    ], JSON_UNESCAPED_UNICODE);
    $url = rtrim((string) ($cfg['ntfy_serveur'] ?? 'https://ntfy.sh'), '/') . '/';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $corps,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        unset($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $corps,
            'timeout' => 10,
        ]]);
        $ok = @file_get_contents($url, false, $ctx) !== false;
    }
    echo ($ok ? 'Envoyé : ' : 'ÉCHEC d\'envoi : ') . "$titre — $message\n";
    return $ok;
};

if ($essai) {
    $envoyer('Test des alertes astro', 'Si tu lis ceci, tu recevras les alertes « Belle nuit » de ' . $lieu . '.', ['milky_way']);
    exit;
}

/* ---------- État mémorisé (une entrée par nuit) ---------- */

$dossier = __DIR__ . '/cache';
if (!is_dir($dossier)) {
    @mkdir($dossier, 0755, true);
}
$fichier = (is_writable($dossier) ? $dossier : sys_get_temp_dir())
    . ($simulation ? '/alertes-simulation.json' : '/alertes.json');
$verrou = fopen($fichier . '.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    echo "Déjà en cours.\n";
    exit;
}
$etat = json_decode((string) @file_get_contents($fichier), true);
$etat = is_array($etat) ? $etat : [];

/* ---------- La nuit qui vient (ou en cours) ---------- */

$nuit = Nuit::calculer($cfg, $maintenant);
if (isset($nuit['erreur']) || $nuit['note'] === null) {
    echo "Prévisions indisponibles : rien à faire pour l'instant.\n";
    flock($verrou, LOCK_UN);
    exit;
}
$cle = (string) $nuit['coucher'];
$memo = $etat[$cle] ?? ['annonce' => false, 'couvert' => false];

$seuilAlerte = (int) ($cfg['seuil_alerte'] ?? 70);
$seuilCouvert = (int) ($cfg['seuil_couvert'] ?? 50);
$preavis = 60 * (int) ($cfg['preavis_coucher_min'] ?? 120);
$creneau = $nuit['creneau'];

/** Stabilité moyenne estimée sur un intervalle, ou null. */
$seeingSur = static function (array $c) use ($nuit): ?int {
    $s = [];
    foreach ($nuit['tranches'] as $t) {
        if ($t['ts'] >= $c['debut'] && $t['ts'] < $c['fin'] && $t['seeing'] !== null) {
            $s[] = $t['seeing'];
        }
    }
    return $s ? (int) round(array_sum($s) / count($s)) : null;
};

echo 'Nuit du ' . (new DateTimeImmutable('@' . $nuit['coucher']))->setTimezone($tz)->format('d/m')
    . ' : ciel ' . $nuit['note'] . '/100 · ' . $nuit['ligne2'] . ' · coucher ' . $hm($nuit['coucher']) . "\n";

// 1. Annonce : entre (coucher − préavis) et le coucher, une seule fois
if (!$memo['annonce'] && $maintenant >= $nuit['coucher'] - $preavis && $maintenant < $nuit['coucher']) {
    if ($nuit['note'] >= $seuilAlerte && $creneau !== null) {
        $message = 'Ciel ' . $nuit['note'] . '/100 · meilleur créneau ' . $nuit['ligne2'] . ".\n"
            . ucfirst($nuit['ligne3']);
        $s = $seeingSur($creneau);
        if ($s !== null) {
            $message .= ' · stabilité ' . $s . '/5 (estimation)';
        }
        if ($envoyer('Belle nuit ce soir à ' . $lieu, $message, ['milky_way'])) {
            $memo['annonce'] = true;
            $memo['creneau'] = $creneau;
        }
    } else {
        echo "Pas d'annonce : la nuit n'atteint pas " . $seuilAlerte . "/100 ou n'a pas de créneau dégagé.\n";
    }
}

// 2. Après l'annonce : la prévision se dégrade-t-elle ?
//    Vérifié jusqu'à 45 min avant la fin du créneau annoncé (au-delà, le créneau
//    « restant » raccourcit de lui-même et ne veut plus rien dire).
if ($memo['annonce'] && !$memo['couvert'] && $maintenant < $memo['creneau']['fin'] - 45 * 60) {
    if ($nuit['note'] < $seuilCouvert || $creneau === null) {
        $message = 'La prévision s\'est dégradée : ciel ' . $nuit['note'] . '/100. ';
        $message .= $creneau === null
            ? 'Plus de créneau dégagé d\'au moins 45 min.'
            : 'Meilleur créneau restant : ' . $nuit['ligne2'] . '.';
        if ($envoyer('Le ciel se couvre à ' . $lieu, $message, ['cloud'])) {
            $memo['couvert'] = true;
        }
    }
}

// Mémoriser, en oubliant les nuits passées
$etat[$cle] = $memo;
foreach (array_keys($etat) as $k) {
    if ((int) $k < $maintenant - 2 * 86400) {
        unset($etat[$k]);
    }
}
file_put_contents($fichier, json_encode($etat), LOCK_EX);
flock($verrou, LOCK_UN);
