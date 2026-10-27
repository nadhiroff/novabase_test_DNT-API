<?php

declare(strict_types=1);

/** Données d'exemple insérées par le bouton « Insérer des données d'exemple ». */
const SAMPLE_CATEGORIES = [
    ['nom' => 'Boissons', 'description' => 'Cafés, thés et jus'],
    ['nom' => 'Épicerie', 'description' => 'Produits secs et conserves'],
    ['nom' => 'Fruits', 'description' => 'Fruits frais de saison'],
];

const SAMPLE_PRODUCTS = [
    ['nom' => 'Café arabica 250 g', 'description' => 'Torréfaction artisanale', 'prix' => 4500, 'stock' => 24, 'disponible' => true, 'categorie' => 'Boissons'],
    ['nom' => 'Thé vert à la menthe', 'description' => 'Boîte de 20 sachets', 'prix' => 2500, 'stock' => 40, 'disponible' => true, 'categorie' => 'Boissons'],
    ['nom' => 'Jus de bissap 1 L', 'description' => 'Fleurs d\'hibiscus, peu sucré', 'prix' => 1500, 'stock' => 0, 'disponible' => false, 'categorie' => 'Boissons'],
    ['nom' => 'Riz parfumé 5 kg', 'description' => null, 'prix' => 7500, 'stock' => 12, 'disponible' => true, 'categorie' => 'Épicerie'],
    ['nom' => 'Huile d\'olive 1 L', 'description' => 'Première pression à froid', 'prix' => 6000, 'stock' => 8, 'disponible' => true, 'categorie' => 'Épicerie'],
    ['nom' => 'Mangues (le kilo)', 'description' => 'Variété Julie', 'prix' => 1200, 'stock' => 30, 'disponible' => true, 'categorie' => 'Fruits'],
];

/**
 * Insère les données d'exemple par l'API : les catégories manquantes (le nom est unique), puis,
 * pour « produits », les six produits reliés à leur catégorie. Un seul POST par table.
 *
 * @return array{ok: bool, message: string}
 */
function seed_samples(NovaBase $api, string $table): array
{
    $existing = $api->list('categories', ['select' => 'id,nom', 'limit' => 100]);
    if ($existing['status'] !== 200 || !is_array($existing['data'])) {
        return ['ok' => false, 'message' => 'Lecture des catégories impossible : ' . ($existing['error'] ?? 'HTTP ' . $existing['status'])];
    }
    $ids = array_column($existing['data'], 'id', 'nom');
    $missing = array_values(array_filter(SAMPLE_CATEGORIES, static fn (array $c): bool => !isset($ids[$c['nom']])));
    $done = [];
    if ($missing !== []) {
        $created = $api->create('categories', $missing);
        if ($created['status'] !== 201 || !is_array($created['data'])) {
            return ['ok' => false, 'message' => 'Insertion des catégories refusée : ' . ($created['error'] ?? 'HTTP ' . $created['status'])];
        }
        $ids += array_column($created['data'], 'id', 'nom');
        $done[] = count($missing) . ' catégorie(s)';
    }
    if ($table === 'produits') {
        $rows = array_map(static function (array $p) use ($ids): array {
            $p['categorie_id'] = $ids[$p['categorie']] ?? null;
            unset($p['categorie']);

            return $p;
        }, SAMPLE_PRODUCTS);
        $created = $api->create('produits', $rows);
        if ($created['status'] !== 201) {
            return ['ok' => false, 'message' => 'Insertion des produits refusée : ' . ($created['error'] ?? 'HTTP ' . $created['status'])];
        }
        $done[] = count($rows) . ' produit(s)';
    }

    return $done === []
        ? ['ok' => true, 'message' => 'Les catégories d\'exemple existent déjà : rien à ajouter.']
        : ['ok' => true, 'message' => implode(' et ', $done) . ' inséré(s) en une requête POST par table.'];
}
