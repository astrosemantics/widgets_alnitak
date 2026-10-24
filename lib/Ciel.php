<?php
/**
 * Prévisions de nuages et de courant-jet via Open-Meteo (gratuit, sans clé).
 *
 * - Nuages : couverture totale, basse, moyenne et haute (%), heure par heure.
 * - Stabilité de l'air (« seeing ») : ESTIMATION à partir du vent à 250 hPa
 *   (altitude du courant-jet, ~10 km). Un courant-jet fort brasse l'air en
 *   altitude et dégrade les images. Ce n'est pas une mesure du seeing : la
 *   turbulence près du sol, la topographie et l'instrument comptent aussi.
 *
 * Réponse gardée 30 min en cache ; en cas de panne, le dernier cache sert
 * encore 6 h.
 */

declare(strict_types=1);

final class Ciel
{
    private const API = 'https://api.open-meteo.com/v1/forecast';
    private const CACHE_FRAIS = 30 * 60;
    private const CACHE_MAX = 6 * 3600;

    private float $lat;
    private float $lon;
    private string $modele;
    private string $fichierCache;

    public function __construct(float $lat, float $lon, string $modele, string $fichierCache)
    {
        $this->lat = $lat;
        $this->lon = $lon;
        $this->modele = $modele;
        $this->fichierCache = $fichierCache;
    }

    /**
     * Prévisions heure par heure, ou null si indisponibles.
     * @return array<int, array{total:?float, basse:?float, moyenne:?float, haute:?float, jet:?float}>|null
     *         clé = timestamp du début de l'heure (UTC)
     */
    public function horaire(): ?array
    {
        $d = $this->donnees();
        if ($d === null) {
            return null;
        }
        $h = $d['hourly'];
        $val = static fn(string $cle, int $i): ?float => isset($h[$cle][$i]) && $h[$cle][$i] !== null ? (float) $h[$cle][$i] : null;
        $res = [];
        foreach ($h['time'] as $i => $ts) {
            $res[(int) $ts] = [
                'total' => $val('cloud_cover', $i),
                'basse' => $val('cloud_cover_low', $i),
                'moyenne' => $val('cloud_cover_mid', $i),
                'haute' => $val('cloud_cover_high', $i),
                'jet' => $val('wind_speed_250hPa', $i),
            ];
        }
        return $res;
    }

    /**
     * Gêne effective des nuages (0–100) : les voiles hauts (cirrus) gênent
     * moins qu'une couche basse ou moyenne.
     */
    public static function geneNuages(array $h): ?float
    {
        if ($h['basse'] === null && $h['moyenne'] === null && $h['haute'] === null) {
            return $h['total'];
        }
        return max((float) $h['basse'], (float) $h['moyenne'], 0.6 * (float) $h['haute']);
    }

    /**
     * Stabilité estimée de 1 (mauvaise) à 5 (excellente) d'après le vent à 250 hPa (nœuds).
     */
    public static function seeing(?float $jetNoeuds): ?int
    {
        if ($jetNoeuds === null) {
            return null;
        }
        if ($jetNoeuds < 30) {
            return 5;
        }
        if ($jetNoeuds < 50) {
            return 4;
        }
        if ($jetNoeuds < 70) {
            return 3;
        }
        if ($jetNoeuds < 100) {
            return 2;
        }
        return 1;
    }

    private function donnees(): ?array
    {
        $cache = null;
        if (is_file($this->fichierCache)) {
            $c = json_decode((string) @file_get_contents($this->fichierCache), true);
            $cache = is_array($c) && isset($c['recu'], $c['data']) ? $c : null;
        }
        if ($cache !== null && time() - $cache['recu'] < self::CACHE_FRAIS) {
            return $cache['data'];
        }
        $frais = $this->telecharger();
        if ($frais !== null) {
            @file_put_contents($this->fichierCache, json_encode(['recu' => time(), 'data' => $frais]), LOCK_EX);
            return $frais;
        }
        if ($cache !== null && time() - $cache['recu'] < self::CACHE_MAX) {
            return $cache['data'];
        }
        return null;
    }

    private function telecharger(): ?array
    {
        $url = self::API . '?' . http_build_query([
            'latitude' => $this->lat,
            'longitude' => $this->lon,
            'hourly' => 'cloud_cover,cloud_cover_low,cloud_cover_mid,cloud_cover_high,wind_speed_250hPa',
            'wind_speed_unit' => 'kn',
            'timeformat' => 'unixtime',
            'timezone' => 'UTC',
            'past_hours' => 18,
            'forecast_hours' => 48,
            'models' => $this->modele,
        ]);
        $brut = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'widget-astro/1.0']);
            $r = curl_exec($ch);
            if (is_string($r) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
                $brut = $r;
            }
            unset($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'widget-astro/1.0']]);
            $r = @file_get_contents($url, false, $ctx);
            $brut = is_string($r) ? $r : null;
        }
        if ($brut === null) {
            return null;
        }
        $d = json_decode($brut, true);
        return is_array($d) && isset($d['hourly']['time']) ? $d : null;
    }
}
