<?php

declare(strict_types=1);

/*
 * Description des tables créées par database.sql (formulaires et colonnes affichées).
 * Types : text, textarea, money, integer, boolean, relation.
 */
return [
    'produits' => [
        'label' => 'Produits',
        'singular' => 'produit',
        'icon' => 'box',
        'search' => 'nom',
        'fields' => [
            'nom' => ['label' => 'Nom', 'type' => 'text', 'required' => true, 'max' => 120],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
            'prix' => ['label' => 'Prix', 'type' => 'money', 'required' => true],
            'stock' => ['label' => 'Stock', 'type' => 'integer', 'required' => true],
            'categorie_id' => ['label' => 'Catégorie', 'type' => 'relation', 'table' => 'categories'],
            'disponible' => ['label' => 'Disponible à la vente', 'type' => 'boolean'],
        ],
        'columns' => ['nom', 'categorie_id', 'prix', 'stock', 'disponible'],
    ],
    'categories' => [
        'label' => 'Catégories',
        'singular' => 'catégorie',
        'icon' => 'tag',
        'search' => 'nom',
        'fields' => [
            'nom' => ['label' => 'Nom', 'type' => 'text', 'required' => true, 'max' => 80],
            'description' => ['label' => 'Description', 'type' => 'text', 'max' => 255],
        ],
        'columns' => ['nom', 'description'],
    ],
];
