<?php

declare(strict_types=1);

/**
 * Test automatique du CRUD : crée un produit temporaire, le relit, le filtre, le modifie,
 * vérifie la validation, le supprime puis contrôle qu'il a disparu. Rien ne reste en base.
 */
final class AutoTest
{
    /** @var list<array{label: string, ok: bool, detail: string, status: int|null, ms: float|null}> */
    private array $steps = [];

    public function __construct(private readonly NovaBase $api)
    {
    }

    /** @return list<array{label: string, ok: bool, detail: string, status: int|null, ms: float|null}> */
    public function run(): array
    {
        $info = $this->api->info();
        $project = is_array($info['data']['project'] ?? null) ? $info['data']['project'] : [];
        if (!$this->step('Connexion à l\'API', $info, $info['status'] === 200, 'Projet « ' . ($project['name'] ?? '?') . ' », clé ' . ($info['data']['key']['type'] ?? '?'))) {
            return $this->steps;
        }
        $tables = is_array($info['data']['tables'] ?? null) ? $info['data']['tables'] : [];
        $missing = array_diff(['categories', 'produits'], $tables);
        $this->steps[] = ['label' => 'Tables « categories » et « produits » présentes', 'ok' => $missing === [], 'detail' => $missing === [] ? 'Tables du projet : ' . implode(', ', $tables) : 'Manquante(s) : ' . implode(', ', $missing) . ' → exécutez database.sql dans l\'Éditeur SQL.', 'status' => null, 'ms' => null];
        if ($missing !== []) {
            return $this->steps;
        }

        $list = $this->api->list('categories', ['limit' => 5, 'order' => 'id.asc']);
        $this->step('READ : lister les catégories', $list, $list['status'] === 200 && is_array($list['data']), ($list['total'] ?? 0) . ' catégorie(s) au total');

        $name = 'Produit de test ' . date('His') . '-' . random_int(100, 999);
        $created = $this->api->create('produits', ['nom' => $name, 'description' => 'Créé par le test automatique', 'prix' => 9.99, 'stock' => 3, 'disponible' => true]);
        $id = $created['data']['id'] ?? null;
        if (!$this->step('CREATE : ajouter un produit (POST)', $created, $created['status'] === 201 && $id !== null, 'id ' . ($id ?? '—') . ' attribué par la base')) {
            if ($created['status'] === 403) {
                $this->steps[array_key_last($this->steps)]['detail'] = 'Écriture refusée : la clé utilisée est publique. Mettez la clé secrète (sk_live_…) dans config.php.';
            }

            return $this->steps;
        }

        try {
            $read = $this->api->find('produits', $id);
            $this->step('READ : relire le produit par son id (GET)', $read, $read['status'] === 200 && ($read['data']['nom'] ?? '') === $name, 'prix ' . ($read['data']['prix'] ?? '?') . ', stock ' . ($read['data']['stock'] ?? '?'));

            $filtered = $this->api->list('produits', ['nom' => 'eq.' . $name]);
            $this->step('READ : retrouver le produit par un filtre (nom=eq.…)', $filtered, $filtered['status'] === 200 && $filtered['total'] === 1, ($filtered['total'] ?? 0) . ' résultat(s)');

            $updated = $this->api->update('produits', $id, ['stock' => 42, 'prix' => 19.5]);
            $this->step('UPDATE : modifier le stock et le prix (PATCH)', $updated, $updated['status'] === 200 && ($updated['data']['stock'] ?? null) === 42 && ($updated['data']['prix'] ?? null) === '19.50', 'stock ' . ($updated['data']['stock'] ?? '?') . ', prix ' . ($updated['data']['prix'] ?? '?'));

            $reread = $this->api->find('produits', $id);
            $this->step('READ : la modification est bien enregistrée', $reread, ($reread['data']['stock'] ?? null) === 42, 'stock relu : ' . ($reread['data']['stock'] ?? '?'));

            $invalid = $this->api->create('produits', ['nom' => 'Invalide', 'stock' => 'beaucoup']);
            $this->step('Validation : une valeur invalide est refusée (422)', $invalid, $invalid['status'] === 422, $invalid['fields'] !== [] ? implode(' · ', array_map(static fn (string $f, string $m): string => $f . ' : ' . $m, array_keys($invalid['fields']), $invalid['fields'])) : (string) $invalid['error']);
        } finally {
            $deleted = $this->api->delete('produits', $id);
            $this->step('DELETE : supprimer le produit', $deleted, $deleted['status'] === 204, 'produit ' . $id . ' supprimé');
        }

        $gone = $this->api->find('produits', $id);
        $this->step('READ : le produit supprimé n\'existe plus (404)', $gone, $gone['status'] === 404, 'réponse ' . $gone['status']);

        return $this->steps;
    }

    /** @param array{status: int, ms: float, error: string|null} $response */
    private function step(string $label, array $response, bool $ok, string $detail): bool
    {
        $this->steps[] = [
            'label' => $label,
            'ok' => $ok,
            'detail' => $ok || $response['error'] === null ? $detail : $response['error'],
            'status' => $response['status'],
            'ms' => $response['ms'],
        ];

        return $ok;
    }
}
