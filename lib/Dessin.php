<?php
/**
 * Dessin des widgets « pastille + trois lignes » (marées, vent…).
 *
 * Image transparente de 334 × 72 dp, dessinée à ×6 puis réduite à ×3
 * pour des bords lisses. Toutes les coordonnées sont en dp.
 */

declare(strict_types=1);

final class Dessin
{
    public const LARGEUR = 334;
    public const HAUTEUR = 72;      // hauteur par défaut (widget 4×1)
    private const SORTIE = 3; // px par dp dans l'image finale
    private const K = 6;      // px par dp pendant le dessin

    /** Pastille : centre et rayon de l'anneau. */
    public const CX = 34;
    public const CY = 36;
    public const RAYON = 29;

    /** @var resource|\GdImage */
    private $im;
    /** @var array<string, string> couleurs hex sans # */
    public array $hex;
    /** @var array<string, int> couleurs allouées */
    private array $c = [];
    private array $police;
    private int $hauteur;

    public function __construct(array $surcharges = [], int $hauteur = self::HAUTEUR)
    {
        $this->hauteur = $hauteur;
        $this->hex = [
            'accent'  => 'b8c6ee', // lavande des icônes
            'fond'    => '2a3042', // gris-bleu des cercles d'icônes
            'texte'   => 'eef0f6',
            'discret' => 'a9afc2',
            'alerte'  => 'f2b880', // orange clair : seuil dépassé
        ];
        foreach ($this->hex as $nom => $defaut) {
            $v = strtolower(ltrim((string) ($surcharges[$nom] ?? ''), '#'));
            if (preg_match('/^[0-9a-f]{6}$/', $v)) {
                $this->hex[$nom] = $v;
            }
        }

        $this->im = imagecreatetruecolor(self::LARGEUR * self::K, $this->hauteur * self::K);
        imagesavealpha($this->im, true);
        imagealphablending($this->im, false);
        imagefill($this->im, 0, 0, imagecolorallocatealpha($this->im, 0, 0, 0, 127));
        imagealphablending($this->im, true);

        foreach ($this->hex as $nom => $hex) {
            $this->c[$nom] = $this->allouer($this->rgb($hex));
        }
        foreach (['accent', 'alerte'] as $nom) {
            $this->c['rail_' . $nom] = $this->allouer($this->melange($this->hex[$nom], $this->hex['fond'], 0.18));
            $this->c['label_' . $nom] = $this->allouer($this->melange($this->hex[$nom], $this->hex['fond'], 0.8));
        }

        $dir = dirname(__DIR__) . '/fonts/';
        $this->police = [
            'normal' => $dir . 'Outfit_400Regular.ttf',
            'medium' => $dir . 'Outfit_500Medium.ttf',
            'gras'   => $dir . 'Outfit_600SemiBold.ttf',
        ];
    }

    /** Couleurs passées dans l'URL (?accent=…&fond=…). */
    public static function couleursDepuisRequete(): array
    {
        $r = [];
        foreach (['accent', 'fond', 'texte', 'discret', 'alerte'] as $nom) {
            if (isset($_GET[$nom])) {
                $r[$nom] = (string) $_GET[$nom];
            }
        }
        return $r;
    }

    private function rgb(string $hex): array
    {
        return array_map('hexdec', str_split($hex, 2));
    }

    private function melange(string $a, string $b, float $t): array
    {
        [$r1, $g1, $b1] = $this->rgb($a);
        [$r2, $g2, $b2] = $this->rgb($b);
        return [
            (int) round($r1 * $t + $r2 * (1 - $t)),
            (int) round($g1 * $t + $g2 * (1 - $t)),
            (int) round($b1 * $t + $b2 * (1 - $t)),
        ];
    }

    private function allouer(array $c): int
    {
        return imagecolorallocatealpha($this->im, $c[0], $c[1], $c[2], 0);
    }

    public function couleur(string $nom): int
    {
        return $this->c[$nom];
    }

    /** Écrit un texte ; $taille en dp, $y = ligne de base. */
    public function texte(string $txt, float $taille, string $fonte, string $couleur, float $x, float $y, bool $centre = false): void
    {
        $pt = $taille * self::K * 0.75; // GD : points à 96 dpi
        if ($centre) {
            $b = imagettfbbox($pt, 0, $this->police[$fonte], $txt);
            $x = $x * self::K - ($b[2] - $b[0]) / 2 - $b[0];
        } else {
            $x *= self::K;
        }
        imagettftext($this->im, $pt, 0, (int) round($x), (int) round($y * self::K), $this->c[$couleur], $this->police[$fonte], $txt);
    }

    /** Disque de fond de la pastille (64 dp). */
    public function pastille(): void
    {
        imagefilledellipse($this->im, self::CX * self::K, self::CY * self::K, 64 * self::K, 64 * self::K, $this->c['fond']);
    }

    /** Arc de l'anneau, de $de à $a degrés (0 = haut, sens horaire). */
    public function arc(float $de, float $a, string $couleur, float $epaisseur = 3): void
    {
        $ep = (int) round($epaisseur * self::K);
        for ($d = $de; $d <= $a; $d += 0.5) {
            $r = deg2rad($d - 90);
            imagefilledellipse(
                $this->im,
                (int) round((self::CX + self::RAYON * cos($r)) * self::K),
                (int) round((self::CY + self::RAYON * sin($r)) * self::K),
                $ep,
                $ep,
                $this->c[$couleur]
            );
        }
    }

    /** Anneau complet (rail) + progression 0–100. */
    public function anneau(int $progression, string $couleur = 'accent'): void
    {
        for ($d = 0; $d < 360; $d += 0.5) {
            $this->arc($d, $d, 'rail_' . $couleur);
        }
        $p = max(0, min(100, $progression));
        $this->arc(0, 3.6 * $p, $couleur);
    }

    /** Rail seul + petit trait pour le nord, à l'intérieur de l'anneau. */
    public function rose(string $couleur = 'accent'): void
    {
        for ($d = 0; $d < 360; $d += 0.5) {
            $this->arc($d, $d, 'rail_' . $couleur);
        }
        $x = self::CX * self::K;
        for ($r = 23.5; $r <= 26.5; $r += 0.25) {
            imagefilledellipse($this->im, $x, (int) round((self::CY - $r) * self::K), (int) (1.5 * self::K), (int) (1.5 * self::K), $this->c['label_' . $couleur]);
        }
    }

    /**
     * Triangle sur l'anneau, pointé vers le centre : d'où vient le vent.
     * $cap en degrés (0 = nord, sens horaire).
     */
    public function repere(float $cap, string $couleur): void
    {
        $a = deg2rad($cap);
        $ux = sin($a);
        $uy = -cos($a);   // vers l'extérieur, dans le repère écran
        $px = cos($a);
        $py = sin($a);    // perpendiculaire
        $pointe = 24;     // rayon de la pointe (vers l'intérieur)
        $base = 32.5;     // rayon de la base (au bord de la pastille)
        $demi = 4.5;
        $pts = [
            self::CX + $ux * $pointe, self::CY + $uy * $pointe,
            self::CX + $ux * $base + $px * $demi, self::CY + $uy * $base + $py * $demi,
            self::CX + $ux * $base - $px * $demi, self::CY + $uy * $base - $py * $demi,
        ];
        $pts = array_map(fn($v) => (int) round($v * self::K), $pts);
        $this->polygone($pts, $this->c[$couleur]);
    }

    /* ---------- Primitives pour les graphiques ---------- */

    /** Couleur hexadécimale quelconque (sans #), avec opacité 0–1. */
    public function hex(string $hex, float $opacite = 1.0): int
    {
        [$r, $g, $b] = $this->rgb(ltrim($hex, '#'));
        return imagecolorallocatealpha($this->im, $r, $g, $b, (int) round(127 * (1 - $opacite)));
    }

    /** Couleur nommée atténuée vers le fond ($t = 1 : couleur pure, 0 : fond). */
    public function nuance(string $nom, float $t): int
    {
        return $this->allouer($this->melange($this->hex[$nom], $this->hex['fond'], $t));
    }

    /** Largeur d'un texte en dp. */
    public function largeur(string $txt, float $taille, string $fonte): float
    {
        $b = imagettfbbox($taille * self::K * 0.75, 0, $this->police[$fonte], $txt);
        return ($b[2] - $b[0]) / self::K;
    }

    /** Texte aligné à droite sur $x. */
    public function texteDroite(string $txt, float $taille, string $fonte, string $couleur, float $x, float $y): void
    {
        $this->texte($txt, $taille, $fonte, $couleur, $x - $this->largeur($txt, $taille, $fonte), $y);
    }

    /** Rectangle aux coins arrondis ($couleur : index GD). */
    public function rectangle(float $x, float $y, float $l, float $h, float $rayon, int $couleur): void
    {
        $k = self::K;
        $r = min($rayon, $l / 2, $h / 2);
        [$x1, $y1, $x2, $y2] = [(int) round($x * $k), (int) round($y * $k), (int) round(($x + $l) * $k), (int) round(($y + $h) * $k)];
        $rr = (int) round($r * $k);
        if ($rr < 1) {
            imagefilledrectangle($this->im, $x1, $y1, $x2 - 1, $y2 - 1, $couleur);
            return;
        }
        imagefilledrectangle($this->im, $x1 + $rr, $y1, $x2 - $rr - 1, $y2 - 1, $couleur);
        imagefilledrectangle($this->im, $x1, $y1 + $rr, $x1 + $rr - 1, $y2 - $rr - 1, $couleur);
        imagefilledrectangle($this->im, $x2 - $rr, $y1 + $rr, $x2 - 1, $y2 - $rr - 1, $couleur);
        foreach ([[$x1 + $rr, $y1 + $rr, 180], [$x2 - $rr - 1, $y1 + $rr, 270], [$x2 - $rr - 1, $y2 - $rr - 1, 0], [$x1 + $rr, $y2 - $rr - 1, 90]] as [$cx, $cy, $deb]) {
            imagefilledarc($this->im, $cx, $cy, 2 * $rr, 2 * $rr, $deb, $deb + 90, $couleur, IMG_ARC_PIE);
        }
    }

    /** Trait épais à bouts ronds. */
    public function trait(float $x1, float $y1, float $x2, float $y2, float $epaisseur, int $couleur): void
    {
        $k = self::K;
        $ep = max(1, (int) round($epaisseur * $k));
        $n = (int) max(1, ceil(hypot($x2 - $x1, $y2 - $y1) * 4));
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n;
            imagefilledellipse($this->im, (int) round(($x1 + ($x2 - $x1) * $t) * $k), (int) round(($y1 + ($y2 - $y1) * $t) * $k), $ep, $ep, $couleur);
        }
    }

    /** Ligne horizontale en pointillés (sans recouvrement). */
    public function pointilles(float $x1, float $x2, float $y, float $epaisseur, float $tiret, float $espace, int $couleur): void
    {
        $k = self::K;
        $h = max(1, (int) round($epaisseur * $k));
        $yy = (int) round($y * $k - $h / 2);
        for ($x = $x1; $x < $x2; $x += $tiret + $espace) {
            imagefilledrectangle($this->im, (int) round($x * $k), $yy, (int) round(min($x + $tiret, $x2) * $k) - 1, $yy + $h - 1, $couleur);
        }
    }

    /** Rectangle simple (sans arrondi). */
    public function bloc(float $x, float $y, float $l, float $h, int $couleur): void
    {
        $k = self::K;
        imagefilledrectangle($this->im, (int) round($x * $k), (int) round($y * $k), (int) round(($x + $l) * $k) - 1, (int) round(($y + $h) * $k) - 1, $couleur);
    }

    /** Hachures diagonales (traits de $couleur) limitées à un rectangle. */
    public function hachures(float $x, float $y, float $l, float $h, int $couleur, float $pas = 5, float $epaisseur = 1.6): void
    {
        $k = self::K;
        [$x1, $y1, $x2, $y2] = [(int) round($x * $k), (int) round($y * $k), (int) round(($x + $l) * $k) - 1, (int) round(($y + $h) * $k) - 1];
        if ($x2 <= $x1 || $y2 <= $y1) {
            return;
        }
        imagesetclip($this->im, $x1, $y1, $x2, $y2);
        $p = $pas * $k;
        $e = $epaisseur * $k / 2;
        $hh = $y2 - $y1;
        for ($d = $x1 - $hh; $d < $x2 + $p; $d += $p) {
            // Trait montant vers la droite à 45°
            $this->polygone([
                (int) round($d - $e), $y2,
                (int) round($d + $e), $y2,
                (int) round($d + $hh + $e), $y1,
                (int) round($d + $hh - $e), $y1,
            ], $couleur);
        }
        imagesetclip($this->im, 0, 0, imagesx($this->im) - 1, imagesy($this->im) - 1);
    }

    /** Rend transparents les coins d'un rectangle, pour un arrondi de $rayon. */
    public function arrondirCoins(float $x, float $y, float $l, float $h, float $rayon): void
    {
        $k = self::K;
        [$x1, $y1, $x2, $y2] = [(int) round($x * $k), (int) round($y * $k), (int) round(($x + $l) * $k) - 1, (int) round(($y + $h) * $k) - 1];
        $r = (int) round($rayon * $k);
        $transparent = imagecolorallocatealpha($this->im, 0, 0, 0, 127);
        imagealphablending($this->im, false);
        foreach ([[$x1, $y1, 1, 1], [$x2, $y1, -1, 1], [$x1, $y2, 1, -1], [$x2, $y2, -1, -1]] as [$cx, $cy, $sx, $sy]) {
            $ox = $cx + $sx * $r;
            $oy = $cy + $sy * $r;
            for ($i = 0; $i < $r; $i++) {
                for ($j = 0; $j < $r; $j++) {
                    $px = $cx + $sx * $i;
                    $py = $cy + $sy * $j;
                    if (hypot($px - $ox, $py - $oy) > $r) {
                        imagesetpixel($this->im, $px, $py, $transparent);
                    }
                }
            }
        }
        imagealphablending($this->im, true);
    }

    /** Petite flèche orientée vers $cap (0 = haut / nord, sens horaire). */
    public function fleche(float $x, float $y, float $cap, string $couleur, float $taille = 1.0): void
    {
        $forme = [[0, -5.5], [2.6, 3.5], [0, 1.2], [-2.6, 3.5]];
        $a = deg2rad($cap);
        $pts = [];
        foreach ($forme as [$px, $py]) {
            $px *= $taille;
            $py *= $taille;
            $pts[] = (int) round(($x + $px * cos($a) - $py * sin($a)) * self::K);
            $pts[] = (int) round(($y + $px * sin($a) + $py * cos($a)) * self::K);
        }
        $this->polygone($pts, $this->c[$couleur]);
    }

    /** Polygone plein, compatible PHP 7.4 à 8.x. */
    private function polygone(array $pts, int $couleur): void
    {
        if (PHP_VERSION_ID >= 80000) {
            imagefilledpolygon($this->im, $pts, $couleur);
        } else {
            imagefilledpolygon($this->im, $pts, intdiv(count($pts), 2), $couleur);
        }
    }

    /** Disque plein de rayon $r (dp). */
    public function disque(float $x, float $y, float $r, int $couleur): void
    {
        $k = self::K;
        $dd = max(1, (int) round(2 * $r * $k));
        imagefilledellipse($this->im, (int) round($x * $k), (int) round($y * $k), $dd, $dd, $couleur);
    }

    /** Cercle (contour) de rayon $r ; $tiret > 0 : en pointillés (longueurs en dp). */
    public function cercle(float $x, float $y, float $r, float $epaisseur, int $couleur, float $tiret = 0, float $espace = 0): void
    {
        $k = self::K;
        $ep = max(1, (int) round($epaisseur * $k));
        $tour = 2 * M_PI * $r;
        $n = (int) max(12, ceil($tour * 4));
        for ($i = 0; $i < $n; $i++) {
            $l = $tour * $i / $n;
            if ($tiret > 0 && fmod($l, $tiret + $espace) > $tiret) {
                continue;
            }
            $a = 2 * M_PI * $i / $n;
            imagefilledellipse($this->im, (int) round(($x + $r * cos($a)) * $k), (int) round(($y + $r * sin($a)) * $k), $ep, $ep, $couleur);
        }
    }

    /** Envoie l'image PNG. */
    public function envoyer(int $cache): void
    {
        $l = self::LARGEUR * self::SORTIE;
        $h = $this->hauteur * self::SORTIE;
        $sortie = imagecreatetruecolor($l, $h);
        imagesavealpha($sortie, true);
        imagealphablending($sortie, false);
        imagefill($sortie, 0, 0, imagecolorallocatealpha($sortie, 0, 0, 0, 127));
        imagecopyresampled($sortie, $this->im, 0, 0, 0, 0, $l, $h, self::LARGEUR * self::K, $this->hauteur * self::K);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=' . $cache);
        imagepng($sortie, null, 9);
    }
}
