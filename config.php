<?php

/*
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  À COMPLÉTER : les deux valeurs se trouvent dans la console NovaBase.    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * NOVABASE_URL : console → votre projet → « Vue d'ensemble » → bloc Intégration (ligne NOVABASE_URL).
 *                Exemple : http://localhost/DNT-api/index.php/api/v1/abcdefghij0123456789
 *
 * NOVABASE_KEY : console → votre projet → « Clés API » → copiez la clé SECRÈTE (sk_live_…).
 *                Une clé publique (pk_live_…) permet seulement de lire : l'ajout, la
 *                modification et la suppression seront refusés (403), ce qui est normal.
 *
 * La clé reste sur le serveur : elle n'est jamais envoyée au navigateur.
 */
return [
    'NOVABASE_URL' => '',
    'NOVABASE_KEY' => '',
];