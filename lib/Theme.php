<?php
/**
 * Couleurs des widgets astro : normales le jour, rouges la nuit (vision de nuit).
 *
 * ?theme=rouge ou ?theme=normal forcent l'affichage ; sinon `theme` de config.php :
 * 'auto' = rouge dès que le Soleil passe sous `rouge_soleil_sous` degrés.
 */

declare(strict_types=1);

require_once __DIR__ . '/Astro.php';

final class Theme
{
    public static function choisir(array $cfg, int $maintenant): array
    {
        $theme = (string) ($_GET['theme'] ?? ($cfg['theme'] ?? 'auto'));
        if ($theme === 'rouge') {
            $rouge = true;
        } elseif ($theme === 'normal') {
            $rouge = false;
        } else {
            $soleil = (new Astro((float) $cfg['latitude'], (float) $cfg['longitude']))->hauteurSoleil($maintenant);
            $rouge = $soleil < (float) ($cfg['rouge_soleil_sous'] ?? -6);
        }

        return $rouge
            ? [
                'rouge' => true,
                'couleurs' => ['accent' => 'd9534a', 'fond' => '2b1513', 'texte' => 'e6b3ad', 'discret' => 'a8645c', 'alerte' => 'd9534a'],
                'obscurite' => ['jour' => '6e3530', 'civil' => '532622', 'nautique' => '3d1c19', 'astronomique' => '2b1412', 'noire' => '170b0a'],
                'lune' => 'e6b3ad',
                'nuages' => 'e6b3ad',
                'liseré' => '6e3530',
                'etoiles' => 'e6b3ad',
                'ciel' => '1c0d0c',
            ]
            : [
                'rouge' => false,
                'couleurs' => [],
                'obscurite' => ['jour' => '6b7287', 'civil' => '4d546b', 'nautique' => '383e54', 'astronomique' => '272c3d', 'noire' => '141722'],
                'lune' => 'd9dce6',
                'nuages' => 'eef0f6',
                'liseré' => '5b6276',
                'etoiles' => 'eef0f6',
                'ciel' => '2a3042',
            ];
    }
}
