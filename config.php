<?php
/**
 * Réglages du widget astro « Ce soir sous les étoiles ».
 */
return [
    // Nom affiché dans le widget
    'lieu' => 'La Martyre',

    // Site d'observation : 48°26'12.6"N 4°09'00.2"W
    // (Google Maps : appui long sur le lieu, puis copier les deux nombres ; ouest = négatif)
    'latitude' => 48.436833,
    'longitude' => -4.150056,

    // Fuseau horaire d'affichage
    'fuseau' => 'Europe/Paris',

    // Gêne nuageuse maximale (%) pour qu'une heure compte comme « dégagée »
    // (les voiles hauts comptent pour 60 % de leur couverture)
    'seuil_nuages' => 30,

    // Modèle météo Open-Meteo ('best_match' choisit le meilleur modèle disponible)
    'modele' => 'best_match',

    // Couleurs du widget : 'auto' (rouge la nuit), 'rouge' (toujours) ou 'normal' (jamais rouge)
    'theme' => 'auto',

    // En mode auto, passage au rouge quand le Soleil descend sous cette hauteur (degrés) :
    // -0.833 = coucher du soleil, -6 = fin du crépuscule civil (conseillé),
    // -12 = fin du crépuscule nautique, -18 = début de la nuit noire.
    // Retour aux couleurs normales quand il remonte au-dessus, le matin.
    'rouge_soleil_sous' => -6,

    // ---------- Widgets « programme » et « carte du ciel » ----------

    // Hauteur minimale (degrés) pour qu'un objet compte comme bien placé
    'hauteur_min_objets' => 25,

    // ---------- Alertes du club (notifier.php, via l'application ntfy) ----------

    // Sujet ntfy commun : chaque adhérent s'y abonne dans l'application ntfy.
    // Toute personne qui connaît ce nom reçoit les alertes : ne le diffuser qu'au club.
    'ntfy_sujet' => '',
    'ntfy_serveur' => 'https://ntfy.sh',

    // Clé secrète pour déclencher notifier.php depuis cron-job.org (notifier.php?cle=…)
    'notifier_cle' => '',

    // Annonce « Belle nuit » : à partir de N minutes avant le coucher du soleil (jusqu'au coucher)
    'preavis_coucher_min' => 120,
    // Note du ciel minimale pour l'annonce
    'seuil_alerte' => 70,
    // Après une annonce, « Le ciel se couvre » si la note tombe sous ce seuil
    // (ou s'il ne reste plus de créneau dégagé d'au moins 45 min)
    'seuil_couvert' => 50,

    // Durée de cache HTTP côté KWGT (secondes)
    'cache' => 300,
];
