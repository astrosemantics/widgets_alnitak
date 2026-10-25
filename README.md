# Widgets astro « Ce soir sous les étoiles »

![Widgets Astro Alnitak – La Martyre](images/logo-widgets-astro-alnitak-la-martyre.webp)

Un widget Android (KWGT, format 4×2) qui dit d'un coup d'œil si la nuit vaut la peine
de sortir le télescope : note du ciel, meilleur créneau, nuit noire, Lune, nuages,
stabilité de l'air. Fonctionne partout : seul le point d'observation est à régler.

## Installation

1. Régler `config.php` : nom du lieu, latitude, longitude (ouest et sud en négatif).
2. Copier le dossier `astro` sur un hébergement web PHP 7.4+ avec l'extension GD (FreeType).
3. Ouvrir `http(s)://votre-site/astro/diagnostic.php` : toutes les lignes doivent être [OK].
4. Dans KWGT : widget 4×2, un élément Image, case « formule » de la ligne Bitmap cochée, adresse :

```
http://votre-site/astro/astro-widget.php?v=$df(yyMMddHH)$$mu(floor, df(mm)/15)$
```

**Rouge automatique la nuit** : par défaut (`'theme' => 'auto'`), le widget passe tout seul
en rouge quand le Soleil descend sous `rouge_soleil_sous` (−6°, fin du crépuscule civil),
et revient aux couleurs normales le matin. Comme KWGT recharge l'image tous les quarts d'heure,
la bascule se fait au plus 15 minutes après.

Pour forcer un affichage, ajouter `theme=rouge` ou `theme=normal` dans l'adresse :

```
http://votre-site/astro/astro-widget.php?theme=rouge&v=$df(yyMMddHH)$$mu(floor, df(mm)/15)$
```

**Deux autres widgets 4×2**, à ajouter de la même façon (un élément Image chacun) :

```
http://votre-site/astro/programme-widget.php?v=$df(yyMMddHH)$$mu(floor, df(mm)/15)$
http://votre-site/astro/carte-widget.php?v=$df(yyMMddHH)$$mu(floor, df(mm)/15)$
```

Ils passent eux aussi en rouge à la nuit tombée.

## Contenu

- `astro-widget.php` : l'image du widget (rouge automatique la nuit ; `?theme=rouge` / `?theme=normal` pour forcer).
- `astro.php` : les mêmes données en JSON, heure par heure.
- `programme-widget.php` : programme de la nuit (quand chaque objet est bien placé).
- `carte-widget.php` : carte du ciel avec les objets du programme.
- `objets.php` : les objets retenus, en JSON.
- `notifier.php` : alertes « Belle nuit » du club via ntfy (à appeler toutes les 5 min).
- `diagnostic.php` : vérification de l'installation.
- `lib/Astro.php` : positions du Soleil et de la Lune (Meeus), sans dépendance.
- `lib/Ciel.php` : nuages et courant-jet (Open-Meteo), cache 30 min.
- `lib/Nuit.php` : frise, créneau, note.
- `lib/Objets.php` : choix des objets de la nuit ; `lib/Planetes.php` : positions des planètes.
- `lib/Theme.php` : couleurs normales / rouges.
- `lib/donnees/` : catalogue d'objets, étoiles et constellations (fichiers générés, sans mise à jour).
- `lib/Dessin.php`, `lib/garde.php`, `fonts/` : dessin et police Outfit (OFL).

## Ce que montre le widget

- **Note du ciel /100** sur la nuit sombre : 55 % clarté du ciel, 30 % absence de Lune
  (pondérée par sa phase), 15 % stabilité estimée.
  ≥ 70 « Belle nuit », ≥ 45 « Nuit correcte », ≥ 20 « Nuit médiocre », sinon « Ciel bouché ».
- **Meilleur créneau** : plus longue période de nuit noire, dégagée (gêne nuageuse
  ≤ `seuil_nuages`) et sans Lune (ou Lune éclairée à moins de 15 %). À défaut, le plus long
  créneau dégagé avec la Lune. Au moins 45 minutes ; pendant la nuit, seule la partie
  restante compte.
- **Frise** de 30 min avant le coucher à 30 min après le lever du soleil : jour, crépuscules
  civil, nautique, astronomique, nuit noire ; trait clair = Lune levée ; hachures = nuages ;
  cadre = meilleur créneau ; trait blanc = maintenant ; cases sous la frise = stabilité.
- En été, s'il n'y a pas de nuit noire (Soleil jamais à −18°), la nuit astronomique sert de référence.

## Programme de la nuit et carte du ciel

**Programme** : une ligne par objet, sur la même frise que le widget astro. La barre montre
quand l'objet est à plus de `hauteur_min_objets` degrés (25 par défaut), en plus vif pendant
la nuit noire ; le point marque sa culmination (son passage au plus haut).

Choix des objets (6 lignes au plus) :
- **Planètes** (Mercure à Saturne, 3 au plus) : celles qui passent au moins 30 min au-dessus
  du seuil une fois le crépuscule nautique fini.
- **Ciel profond** : les 110 objets de Messier et une trentaine d'objets NGC remarquables
  (Double amas, Dentelles du Cygne, Hélice, Œil de chat…). Un objet doit monter à 30° au moins
  et rester une heure au-dessus du seuil pendant le créneau (ou la nuit noire). Les plus beaux
  passent en premier, avec un bonus de hauteur. Quand la Lune est levée, les galaxies et
  nébuleuses reculent nettement, les amas beaucoup moins.
- Les lignes sont rangées dans l'ordre d'observation : planètes, puis ciel profond par heure de culmination.

**Carte du ciel** : le ciel vu depuis le site, zénith au centre, nord en haut et **est à gauche**
(comme une carte tenue au-dessus de la tête). Étoiles jusqu'à la magnitude 4,2, tracés des
constellations, Lune, et jusqu'à 4 objets du programme numérotés (au-dessus de 15°), avec leur
hauteur et leur direction. La nuit, la carte montre le ciel du moment ; le jour, celui du milieu
du créneau. En bas : la constellation au zénith et la Lune.

## Précision et limites

- Planètes : éléments orbitaux du JPL (Standish), à quelques minutes d'arc près de 1800 à 2050.
- Soleil et Lune : calcul local, vérifié contre PyEphem à 1–3 minutes près
  (crépuscules, lever et coucher de la Lune) et à 0,1 % près pour la phase.
- Nuages : prévisions Open-Meteo, avec leurs incertitudes. Les voiles hauts comptent
  pour 60 % de leur couverture (un cirrus gêne moins qu'une couche basse).
- **Stabilité (seeing) : estimation seulement**, déduite du vent à 250 hPa (courant-jet) :
  < 30 nd → 5/5, < 50 → 4, < 70 → 3, < 100 → 2, au-delà → 1. La turbulence près du sol,
  le relief, la mise en température du tube et l'instrument ne sont pas pris en compte.

## Alertes du club (notifications)

`notifier.php` prévient tous les adhérents abonnés, via l'application gratuite
[ntfy](https://ntfy.sh), au plus deux fois par nuit :

- **« Belle nuit ce soir à La Martyre »** : envoyée entre `preavis_coucher_min` minutes avant
  le coucher du soleil (2 h par défaut) et le coucher, si la note du ciel atteint `seuil_alerte`
  (70) et qu'il existe un créneau dégagé. Le message donne la note, le meilleur créneau,
  la nuit noire, la Lune et la stabilité estimée.
- **« Le ciel se couvre à La Martyre »** : si, après cette annonce, la note tombe sous
  `seuil_couvert` (50) ou qu'il ne reste plus de créneau dégagé d'au moins 45 min.
  Vérifié jusqu'à 45 min avant la fin du créneau annoncé.

Rien n'est envoyé les autres soirs. Mémoire des envois : `cache/alertes.json`.

### Mise en place (responsable du club)

1. Dans `config.php`, choisir un `ntfy_sujet` difficile à deviner (ex. `astro-monclub-x7k2q9`)
   et une `notifier_cle` secrète.
2. Tester : ouvrir `notifier.php?cle=<notifier_cle>&essai=1`, une notification de test doit arriver.
3. Sur [cron-job.org](https://cron-job.org) (gratuit), créer une tâche qui appelle
   `http(s)://votre-site/astro/notifier.php?cle=<notifier_cle>` **toutes les 5 minutes**.

En ligne de commande, `php notifier.php --simulation --t=<timestamp>` montre ce qui serait
envoyé à un instant donné, sans rien envoyer.

### Pour chaque adhérent

1. Installer **ntfy** (Google Play, F-Droid ou App Store).
2. Appuyer sur **+**, saisir le nom du sujet communiqué par le club, puis **S'abonner**.
3. Android : autoriser les notifications de ntfy quand le téléphone le demande.

Toute personne qui connaît le nom du sujet reçoit les alertes : le diffuser seulement au club.

## Licences

Données météo : Open-Meteo.com (CC BY 4.0).  
Étoiles, constellations et objets Messier : d3-celestial, © 2015 Olaf Frohn (licence BSD-3).  
Objets NGC : catalogue OpenNGC, M. Verga (CC BY-SA 4.0). Police Outfit : SIL Open Font License.  
Algorithmes : J. Meeus, *Astronomical Algorithms* (2e éd.).  
