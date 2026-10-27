<?php

declare(strict_types=1);

/*
 * Banc d'essai CRUD pour NovaBase : une petite application qui utilise UNIQUEMENT l'API REST
 * de votre projet (aucun accès direct à MySQL). Si tout fonctionne ici, vos applications
 * fonctionneront de la même façon.
 */

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/NovaBase.php';
require __DIR__ . '/lib/AutoTest.php';
require __DIR__ . '/lib/samples.php';

$config = require __DIR__ . '/config.php';
$schema = require __DIR__ . '/lib/schema.php';
$apiUrl = trim((string) ($config['NOVABASE_URL'] ?? ''));
$apiKey = trim((string) ($config['NOVABASE_KEY'] ?? ''));
$configured = $apiUrl !== '' && $apiKey !== '';
$api = $configured ? new NovaBase($apiUrl, $apiKey) : null;

$page = is_string($_GET['page'] ?? null) && in_array($_GET['page'], ['accueil', 'produits', 'categories', 'test'], true) ? $_GET['page'] : 'accueil';
const PAGE_SIZE = 10;

/* ═════════════════════════════ Actions (POST → redirection) ═════════════════════════════ */

/**
 * Transforme le formulaire en données JSON pour l'API. La vraie validation est faite par
 * NovaBase (c'est ce qu'on teste) ; on ne vérifie ici que les champs obligatoires vides.
 *
 * @param array<string, mixed> $definition
 * @param array<string, mixed> $input
 *
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function build_payload(array $definition, array $input): array
{
    $payload = [];
    $errors = [];
    foreach ($definition['fields'] as $name => $field) {
        $raw = is_string($input[$name] ?? null) ? trim($input[$name]) : '';
        switch ($field['type']) {
            case 'boolean':
                $payload[$name] = isset($input[$name]);
                break;
            case 'relation':
                $payload[$name] = $raw === '' ? null : (ctype_digit($raw) ? (int) $raw : $raw);
                break;
            case 'money':
            case 'integer':
                $raw = str_replace([' ', "\u{202F}", "\u{A0}", ','], ['', '', '', '.'], $raw);
                if ($raw !== '') {
                    $payload[$name] = $raw;
                }
                break;
            default:
                $payload[$name] = $raw === '' && empty($field['required']) ? null : $raw;
        }
        if (!empty($field['required']) && $raw === '' && $field['type'] !== 'boolean') {
            $errors[$name] = 'Champ obligatoire.';
        }
    }

    return [$payload, $errors];
}

/** Retour vers la liste en conservant recherche, tri et page. */
function back_to(string $table, array $post, array $extra = []): string
{
    $state = [];
    foreach (['q', 'sort', 'p', 'dispo'] as $k) {
        if (is_string($post['_' . $k] ?? null) && preg_match('/^[\p{L}\p{N} ._\-*\']{0,100}$/u', $post['_' . $k]) === 1) {
            $state[$k] = $post['_' . $k];
        }
    }

    return url(['page' => $table] + $extra + $state);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : '')) {
        flash('error', 'Session expirée : rechargez la page et réessayez.');
        redirect(url(['page' => $page]));
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    if ($action === 'clear_log') {
        NovaBase::clearJournal();
        redirect(url(['page' => $page]));
    }
    if ($api === null) {
        redirect('index.php');
    }
    if ($action === 'autotest') {
        $_SESSION['autotest'] = ['at' => date('d/m/Y H:i:s'), 'steps' => (new AutoTest($api))->run()];
        redirect(url(['page' => 'test']));
    }

    $table = is_string($_POST['table'] ?? null) ? $_POST['table'] : '';
    if (!isset($schema[$table])) {
        redirect('index.php');
    }
    $definition = $schema[$table];
    $id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? $_POST['id'] : '';

    if ($action === 'save') {
        $input = is_array($_POST['f'] ?? null) ? $_POST['f'] : [];
        [$payload, $errors] = build_payload($definition, $input);
        if ($errors === []) {
            $res = $id === '' ? $api->create($table, $payload) : $api->update($table, $id, $payload);
            if (in_array($res['status'], [200, 201], true)) {
                // Preuve d'enregistrement : la ligne est relue depuis la base par une requête séparée.
                $savedId = (string) (is_array($res['data']) ? ($res['data']['id'] ?? $id) : $id);
                $check = $api->find($table, $savedId);
                $verified = $check['status'] === 200 && is_array($check['data']);
                $_SESSION['saved'] = [
                    'table' => $table, 'id' => $savedId, 'created' => $id === '', 'verified' => $verified,
                    'row' => $verified ? $check['data'] : null,
                    'write' => ($id === '' ? 'POST' : 'PATCH') . ' → ' . $res['status'] . ' en ' . $res['ms'] . ' ms',
                    'read' => 'GET /data/' . $table . '/' . $savedId . ' → ' . $check['status'] . ' en ' . $check['ms'] . ' ms',
                ];
                $name = is_array($res['data']) ? (string) ($res['data']['nom'] ?? '') : '';
                $verb = ($id === '' ? 'ajouté' : 'modifié') . ($definition['singular'] === 'catégorie' ? 'e' : '');
                flash($verified ? 'success' : 'error', $verified
                    ? ucfirst($definition['singular']) . ' « ' . $name . ' » ' . $verb . ' et vérifié' . ($definition['singular'] === 'catégorie' ? 'e' : '') . ' dans la base (n° ' . $savedId . ').'
                    : 'Écriture acceptée mais relecture impossible (HTTP ' . $check['status'] . ').');
                redirect(back_to($table, $_POST) . '#ligne-' . rawurlencode($savedId));
            }
            flash('error', ($res['error'] ?? 'Erreur inconnue.') . ' (HTTP ' . $res['status'] . ')');
            $errors = $res['fields'];
        } else {
            flash('error', 'Complétez les champs obligatoires.');
        }
        $_SESSION['form'] = ['table' => $table, 'id' => $id, 'values' => $input, 'errors' => $errors];
        redirect(back_to($table, $_POST, $id === '' ? [] : ['form' => $id]) . '#formulaire');
    }

    if ($action === 'delete_selected') {
        $ids = array_values(array_filter(is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [], static fn (mixed $v): bool => is_string($v) && ctype_digit($v)));
        if ($ids === []) {
            flash('error', 'Cochez au moins une ligne.');
            redirect(back_to($table, $_POST));
        }
        $res = $api->deleteWhere($table, ['id' => 'in.(' . implode(',', array_slice($ids, 0, 100)) . ')']);
        flash($res['status'] === 200 ? 'success' : 'error', $res['status'] === 200
            ? ($res['data']['deleted'] ?? 0) . ' ligne(s) supprimée(s) en une requête — DELETE ?id=in.(…) → 200 en ' . $res['ms'] . ' ms'
            : ($res['error'] ?? 'Suppression impossible.') . ' (HTTP ' . $res['status'] . ')');
        redirect(back_to($table, $_POST));
    }

    if ($action === 'delete_all') {
        $res = $api->deleteWhere($table, ['id' => 'gt.0']);
        flash($res['status'] === 200 ? 'success' : 'error', $res['status'] === 200
            ? 'Toutes les données de « ' . $table . ' » supprimées : ' . ($res['data']['deleted'] ?? 0) . ' ligne(s) — DELETE ?id=gt.0 → 200'
            : ($res['error'] ?? 'Suppression impossible.') . ' (HTTP ' . $res['status'] . ')');
        redirect(url(['page' => $table]));
    }

    if ($action === 'seed') {
        $result = seed_samples($api, $table);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect(url(['page' => $table]));
    }

    if ($action === 'delete' && $id !== '') {
        $res = $api->delete($table, $id);
        if ($res['status'] === 204) {
            flash('success', ucfirst($definition['singular']) . ' n° ' . $id . ' supprimé' . ($definition['singular'] === 'catégorie' ? 'e' : '') . ' — DELETE → 204 en ' . $res['ms'] . ' ms');
        } else {
            flash('error', ($res['error'] ?? 'Suppression impossible.') . ' (HTTP ' . $res['status'] . ')');
        }
        redirect(back_to($table, $_POST));
    }
    redirect(url(['page' => $table]));
}

/* ═════════════════════════════ Données de la page ═════════════════════════════ */

// Contrôle de connexion gardé 30 s en session (le test automatique, lui, interroge toujours l'API).
$cacheKey = hash('sha256', $apiUrl . "\0" . $apiKey);
$cached = $_SESSION['info_cache'] ?? null;
if ($api !== null && is_array($cached) && $cached['key'] === $cacheKey && $cached['at'] > time() - 30 && !isset($_GET['refresh'])) {
    $info = $cached['info'];
} else {
    $info = $api?->info();
    $_SESSION['info_cache'] = $info !== null && $info['status'] === 200 ? ['key' => $cacheKey, 'at' => time(), 'info' => $info] : null;
}
$connected = $info !== null && $info['status'] === 200;
$project = $connected && is_array($info['data']['project'] ?? null) ? $info['data']['project'] : [];
$keyType = $connected ? (string) ($info['data']['key']['type'] ?? '') : '';
$projectTables = $connected && is_array($info['data']['tables'] ?? null) ? $info['data']['tables'] : [];
$tablesReady = $connected && array_diff(array_keys($schema), $projectTables) === [];

$connectionHelp = match (true) {
    !$configured => null,
    $info === null => null,
    $info['status'] === 0 => 'NovaBase ne répond pas à cette adresse. Vérifiez NOVABASE_URL (et que le serveur est démarré).',
    $info['status'] === 401 => 'Clé refusée (401) : copiez à nouveau la clé depuis l\'onglet « Clés API » (elle a peut-être été régénérée).',
    $info['status'] === 404 => 'Adresse introuvable (404) : NOVABASE_URL doit se terminer par /api/v1/<ref du projet>.',
    $info['status'] === 429 => 'Limite de requêtes de votre offre atteinte (par minute) : patientez quelques secondes puis réessayez.',
    $info['status'] === 403 => 'Accès refusé (403) : ' . ($info['error'] ?? 'origine ou clé non autorisée.'),
    $info['status'] !== 200 => ($info['error'] ?? 'Erreur') . ' (HTTP ' . $info['status'] . ')',
    default => null,
};

$view = ['rows' => [], 'total' => 0, 'error' => null, 'missing' => false];
$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$sort = is_string($_GET['sort'] ?? null) && preg_match('/^[a-z_]+\.(asc|desc)$/', $_GET['sort']) === 1 ? $_GET['sort'] : 'id.desc';
$dispo = is_string($_GET['dispo'] ?? null) && in_array($_GET['dispo'], ['oui', 'non'], true) ? $_GET['dispo'] : '';
$p = max(1, (int) ($_GET['p'] ?? 1));
$categories = [];
$editing = null;
$formErrors = [];
$lastSaved = null;

if ($connected && isset($schema[$page])) {
    $definition = $schema[$page];
    $query = ['order' => $sort, 'limit' => PAGE_SIZE, 'offset' => ($p - 1) * PAGE_SIZE];
    if ($q !== '') {
        $query[$definition['search']] = 'ilike.*' . $q . '*';
    }
    if ($page === 'produits' && $dispo !== '') {
        $query['disponible'] = 'eq.' . ($dispo === 'oui' ? 'true' : 'false');
    }
    $res = $api->list($page, $query);
    if ($res['status'] === 200 && is_array($res['data'])) {
        $view = ['rows' => $res['data'], 'total' => (int) $res['total'], 'error' => null, 'missing' => false];
    } else {
        $view['error'] = ($res['error'] ?? 'Erreur') . ' (HTTP ' . $res['status'] . ')';
        $view['missing'] = $res['status'] === 404;
        if ($res['status'] === 429) {
            $view['error'] = 'Limite de requêtes de votre offre atteinte (par minute) : patientez quelques secondes puis rechargez.';
        }
    }
    if ($page === 'produits') {
        $cats = $api->list('categories', ['order' => 'nom.asc', 'limit' => 100, 'select' => 'id,nom']);
        foreach (is_array($cats['data']) && $cats['status'] === 200 ? $cats['data'] : [] as $c) {
            $categories[(int) $c['id']] = (string) $c['nom'];
        }
    }

    // Formulaire ouvert : « ?form=new » ou « ?form=<id> » ; après une erreur, valeurs et messages de la session.
    $form = is_string($_GET['form'] ?? null) ? $_GET['form'] : '';
    $saved = $_SESSION['form'] ?? null;
    unset($_SESSION['form']);
    if ($form === 'new' || ctype_digit($form)) {
        $editing = ['id' => $form === 'new' ? '' : $form, 'values' => []];
        if (is_array($saved) && $saved['table'] === $page && $saved['id'] === $editing['id']) {
            $editing['values'] = $saved['values'];
            $formErrors = $saved['errors'];
        } elseif ($editing['id'] !== '') {
            $found = $api->find($page, $editing['id']);
            if ($found['status'] === 200 && is_array($found['data'])) {
                $editing['values'] = $found['data'];
            } else {
                flash('error', 'Ligne introuvable (HTTP ' . $found['status'] . ').');
                $editing = null;
            }
        } else {
            $editing['values'] = ['disponible' => true];
        }
    } else {
        $editing = ['id' => '', 'values' => ['disponible' => true]];
        if (is_array($saved) && $saved['table'] === $page && $saved['id'] === '') {
            $editing['values'] = $saved['values'];
            $formErrors = $saved['errors'];
        }
    }
    $lastSaved = is_array($_SESSION['saved'] ?? null) && $_SESSION['saved']['table'] === $page ? $_SESSION['saved'] : null;
    unset($_SESSION['saved']);
}

$stats = [];
$latest = [];
if ($connected && $page === 'accueil' && $tablesReady) {
    $count = static fn (string $table, array $filter = []): ?int => $api->list($table, ['limit' => 1, 'select' => 'id'] + $filter)['total'];
    $stats = [
        ['Produits', $count('produits'), 'box'],
        ['Catégories', $count('categories'), 'tag'],
        ['En rupture (stock 0)', $count('produits', ['stock' => 'eq.0']), 'alert'],
    ];
    $last = $api->list('produits', ['order' => 'updated_at.desc', 'limit' => 5, 'select' => 'id,nom,prix,stock,updated_at']);
    $latest = $last['status'] === 200 && is_array($last['data']) ? $last['data'] : [];
}

$flashes = take_flashes();
$journal = NovaBase::journal();
$autotest = $_SESSION['autotest'] ?? null;
$listState = ['q' => $q, 'sort' => $sort === 'id.desc' ? '' : $sort, 'p' => $p > 1 ? (string) $p : '', 'dispo' => $dispo];
$tabs = ['accueil' => ['Accueil', 'home'], 'produits' => ['Produits', 'box'], 'categories' => ['Catégories', 'tag'], 'test' => ['Test automatique', 'flask']];

/** Champs cachés pour conserver l'état de la liste après une action. */
$stateFields = static function () use ($listState): string {
    $out = '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
    foreach ($listState as $k => $v) {
        $out .= '<input type="hidden" name="_' . h($k) . '" value="' . h($v) . '">';
    }

    return $out;
};
?>
<!doctype html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($tabs[$page][0]) ?> · Banc CRUD NovaBase</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php">
        <span class="brand-mark"><?= icon('database') ?></span>
        <span><strong>Banc CRUD</strong><small>pour NovaBase</small></span>
    </a>
    <div class="topbar-right">
        <?php if (!$configured): ?>
        <span class="pill pill-warn"><span class="dot"></span>À configurer</span>
        <?php elseif ($connected): ?>
        <span class="pill pill-ok" title="<?= h($apiUrl) ?>"><span class="dot pulse"></span>Connecté · <?= h($project['name'] ?? '') ?></span>
        <?php else: ?>
        <span class="pill pill-err"><span class="dot"></span>Connexion impossible</span>
        <?php endif; ?>
        <button type="button" class="icon-btn" data-theme-toggle aria-label="Changer de thème"><?= icon('sun', 'icon only-dark') ?><?= icon('moon', 'icon only-light') ?></button>
    </div>
</header>

<div class="shell<?= $configured ? '' : ' shell-single' ?>">
    <div class="main-col">
        <nav class="tabs" aria-label="Sections">
            <?php foreach ($tabs as $key => [$label, $ico]): ?>
            <a href="<?= h(url(['page' => $key])) ?>" class="tab<?= $page === $key ? ' is-active' : '' ?>"<?= $page === $key ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= h($label) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <main>
        <?php if (!$configured): ?>
            <section class="card hero-card">
                <h1>Bienvenue sur le banc d'essai CRUD</h1>
                <p class="muted">Cette application teste votre projet NovaBase <strong>uniquement par l'API</strong>, exactement comme le ferait votre future application. Trois étapes :</p>
                <ol class="steps">
                    <li>
                        <span class="step-n">1</span>
                        <div><h3>Créez les tables</h3><p class="muted">Console NovaBase → votre projet → onglet <strong>Éditeur SQL</strong>. Collez le contenu de <code>database.sql</code> puis cliquez sur <strong>Exécuter</strong>.</p></div>
                    </li>
                    <li>
                        <span class="step-n">2</span>
                        <div><h3>Complétez <code>config.php</code></h3><p class="muted"><code>NOVABASE_URL</code> : bloc « Intégration » de la vue d'ensemble du projet.<br><code>NOVABASE_KEY</code> : la clé <strong>secrète</strong> <code>sk_live_…</code> de l'onglet « Clés API ».</p></div>
                    </li>
                    <li>
                        <span class="step-n">3</span>
                        <div><h3>Rechargez cette page</h3><p class="muted">Le voyant passe au vert. Ajoutez, modifiez, supprimez des produits, ou lancez le test automatique.</p></div>
                    </li>
                </ol>
                <pre class="code"><span class="c">// config.php</span>
return [
    'NOVABASE_URL' => '<span class="s">http://localhost/DNT-api/index.php/api/v1/votre_ref</span>',
    'NOVABASE_KEY' => '<span class="s">sk_live_…</span>',
];</pre>
            </section>

        <?php elseif (!$connected): ?>
            <section class="card notice notice-err">
                <?= icon('alert', 'icon icon-lg') ?>
                <div>
                    <h1>Impossible de joindre votre projet</h1>
                    <p><?= h($connectionHelp) ?></p>
                    <p class="muted small">URL utilisée : <code><?= h($apiUrl) ?></code></p>
                    <a class="btn btn-secondary" href="<?= h(url(['page' => $page, 'refresh' => '1'])) ?>"><?= icon('refresh') ?>Réessayer</a>
                </div>
            </section>

        <?php elseif ($page === 'accueil'): ?>
            <section class="card project-card">
                <div>
                    <p class="eyebrow">Projet connecté</p>
                    <h1><?= h($project['name'] ?? '') ?></h1>
                    <p class="muted mono small"><?= h($project['ref'] ?? '') ?></p>
                </div>
                <div class="project-meta">
                    <span class="pill <?= $keyType === 'secret' ? 'pill-ok' : 'pill-warn' ?>">Clé <?= h($keyType === 'secret' ? 'secrète : lecture et écriture' : 'publique : lecture seule') ?></span>
                    <form method="post" action="<?= h(url(['page' => 'test'])) ?>">
                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                        <button class="btn btn-primary" name="action" value="autotest"><?= icon('play') ?>Lancer le test automatique</button>
                    </form>
                </div>
            </section>

            <?php if ($keyType !== 'secret'): ?>
            <div class="notice notice-warn card"><?= icon('alert') ?><p>Avec une clé publique, l'API refusera l'ajout, la modification et la suppression (403). Pour tester tout le CRUD, mettez la clé <strong>secrète</strong> dans <code>config.php</code>.</p></div>
            <?php endif; ?>

            <?php if (!$tablesReady): ?>
            <section class="card notice notice-warn">
                <?= icon('database', 'icon icon-lg') ?>
                <div>
                    <h2>Tables à créer</h2>
                    <p>Le projet ne contient pas encore <code>categories</code> et <code>produits</code>. Ouvrez l'<strong>Éditeur SQL</strong> de votre projet dans la console NovaBase, collez <code>database.sql</code> et exécutez-le.</p>
                    <p class="muted small">Tables actuelles : <?= $projectTables === [] ? 'aucune' : h(implode(', ', $projectTables)) ?></p>
                </div>
            </section>
            <?php else: ?>
            <div class="stats">
                <?php foreach ($stats as [$label, $value, $ico]): ?>
                <div class="card stat"><span class="stat-icon"><?= icon($ico) ?></span><div><p class="muted small"><?= h($label) ?></p><p class="stat-value"><?= h($value ?? '—') ?></p></div></div>
                <?php endforeach; ?>
            </div>

            <section class="card">
                <div class="card-head"><h2>Derniers produits modifiés</h2><a class="btn btn-ghost" href="<?= h(url(['page' => 'produits'])) ?>">Tout voir</a></div>
                <?php if ($latest === []): ?>
                <p class="empty">Aucun produit pour l'instant.</p>
                <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>id</th><th>Nom</th><th class="num">Prix</th><th class="num">Stock</th><th>Modifié</th></tr></thead>
                        <tbody>
                        <?php foreach ($latest as $row): ?>
                            <tr><td class="mono muted"><?= h($row['id']) ?></td><td><?= h($row['nom']) ?></td><td class="num"><?= h(money($row['prix'])) ?></td><td class="num"><?= h($row['stock']) ?></td><td class="muted small"><?= h($row['updated_at']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        <?php elseif ($page === 'test'): ?>
            <section class="card">
                <div class="card-head">
                    <div>
                        <h1>Test automatique du CRUD</h1>
                        <p class="muted">Crée un produit temporaire, le relit, le filtre, le modifie, vérifie la validation, puis le supprime. Rien ne reste dans votre base.</p>
                    </div>
                    <form method="post" action="<?= h(url(['page' => 'test'])) ?>">
                        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                        <button class="btn btn-primary" name="action" value="autotest"><?= icon('play') ?><?= $autotest === null ? 'Lancer le test' : 'Relancer' ?></button>
                    </form>
                </div>
                <?php if ($autotest === null): ?>
                <p class="empty">Cliquez sur « Lancer le test » : une dizaine d'appels API sont faits en quelques secondes.</p>
                <?php else: ?>
                <?php $passed = count(array_filter($autotest['steps'], static fn (array $s): bool => $s['ok'])); $all = count($autotest['steps']); ?>
                <div class="verdict <?= $passed === $all ? 'verdict-ok' : 'verdict-err' ?>">
                    <?= icon($passed === $all ? 'check' : 'alert', 'icon icon-lg') ?>
                    <div><strong><?= $passed === $all ? 'Tout fonctionne' : 'Des étapes ont échoué' ?></strong><span><?= h($passed) ?> / <?= h($all) ?> étapes réussies · <?= h($autotest['at']) ?></span></div>
                </div>
                <ol class="checklist">
                    <?php foreach ($autotest['steps'] as $s): ?>
                    <li class="<?= $s['ok'] ? 'is-ok' : 'is-err' ?>">
                        <span class="check-icon"><?= icon($s['ok'] ? 'check' : 'x') ?></span>
                        <div class="check-body"><strong><?= h($s['label']) ?></strong><span class="muted small"><?= h($s['detail']) ?></span></div>
                        <?php if ($s['status'] !== null): ?><span class="code-badge <?= h(status_class($s['status'])) ?>"><?= h($s['status']) ?></span><span class="muted small mono ms"><?= h($s['ms']) ?> ms</span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <?php
                $definition = $schema[$page];
                $pages = max(1, (int) ceil($view['total'] / PAGE_SIZE));
                $isEdit = $editing !== null && $editing['id'] !== '';
                $values = $editing['values'] ?? [];
                $fem = $definition['singular'] === 'catégorie';
                $display = static function (array $field, mixed $value) use ($categories): string {
                    return match (true) {
                        $value === null || $value === '' => '—',
                        $field['type'] === 'boolean' => $value ? 'Oui' : 'Non',
                        $field['type'] === 'money' => money($value),
                        $field['type'] === 'relation' => $categories[(int) $value] ?? ('n° ' . $value),
                        default => is_scalar($value) ? (string) $value : '',
                    };
                };
            ?>

            <?php // ───── Formulaire CRUD, toujours visible : ajout, ou modification avec ?form=<id> ───── ?>
            <section class="card form-card<?= $isEdit ? ' is-editing' : '' ?>" id="formulaire">
                <div class="card-head">
                    <div>
                        <p class="eyebrow"><?= $isEdit ? 'Modification' : 'Nouvel enregistrement' ?></p>
                        <h1><?= $isEdit ? 'Modifier « ' . h($values['nom'] ?? '') . ' »' : 'Ajouter un' . ($fem ? 'e ' : ' ') . h($definition['singular']) ?></h1>
                        <p class="muted small mono"><?= $isEdit ? 'PATCH /data/' . h($page) . '/' . h($editing['id']) : 'POST /data/' . h($page) ?></p>
                    </div>
                    <?php if ($isEdit): ?><a class="btn btn-ghost" href="<?= h(url(['page' => $page] + $listState)) ?>"><?= icon('x') ?>Annuler la modification</a><?php endif; ?>
                </div>
                <?php if ($view['missing']): ?>
                <div class="notice notice-warn"><?= icon('database') ?><p>La table <code><?= h($page) ?></code> n'existe pas encore : exécutez <code>database.sql</code> dans l'<strong>Éditeur SQL</strong> de votre projet NovaBase.</p></div>
                <?php else: ?>
                <form method="post" action="<?= h(url(['page' => $page])) ?>" novalidate>
                    <?= $stateFields() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="table" value="<?= h($page) ?>">
                    <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
                    <div class="form-grid">
                        <?php foreach ($definition['fields'] as $name => $field): ?>
                        <?php $value = $values[$name] ?? null; $error = $formErrors[$name] ?? null; $idAttr = 'f-' . $name; ?>
                        <?php if ($field['type'] === 'boolean'): ?>
                        <label class="switch field-switch"><input type="checkbox" name="f[<?= h($name) ?>]" value="1"<?= $value === true || $value === '1' || $value === 1 ? ' checked' : '' ?>><span class="switch-ui" aria-hidden="true"></span><span><?= h($field['label']) ?></span></label>
                        <?php else: ?>
                        <div class="field<?= in_array($field['type'], ['textarea', 'text'], true) ? ' field-wide' : '' ?><?= $error !== null ? ' has-error' : '' ?>">
                            <label for="<?= h($idAttr) ?>"><?= h($field['label']) ?><?= !empty($field['required']) ? ' <span class="req">*</span>' : '' ?></label>
                            <?php if ($field['type'] === 'textarea'): ?>
                            <textarea id="<?= h($idAttr) ?>" name="f[<?= h($name) ?>]" rows="2"><?= h($value) ?></textarea>
                            <?php elseif ($field['type'] === 'relation'): ?>
                            <select id="<?= h($idAttr) ?>" name="f[<?= h($name) ?>]">
                                <option value="">— Aucune —</option>
                                <?php foreach ($categories as $cid => $cname): ?>
                                <option value="<?= h($cid) ?>"<?= (string) $value === (string) $cid ? ' selected' : '' ?>><?= h($cname) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input id="<?= h($idAttr) ?>" name="f[<?= h($name) ?>]" value="<?= h($value) ?>" autocomplete="off"
                                <?= in_array($field['type'], ['money', 'integer'], true) ? 'inputmode="' . ($field['type'] === 'money' ? 'decimal' : 'numeric') . '" placeholder="' . ($field['type'] === 'money' ? 'ex. 2500' : 'ex. 10') . '"' : '' ?>
                                <?= isset($field['max']) ? 'maxlength="' . h($field['max']) . '"' : '' ?>>
                            <?php endif; ?>
                            <?php if ($error !== null): ?><p class="field-error"><?= icon('alert', 'icon icon-xs') ?><?= h($error) ?></p><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary"><?= icon($isEdit ? 'check' : 'plus') ?><?= $isEdit ? 'Enregistrer les modifications' : 'Ajouter' ?></button>
                        <button type="reset" class="btn btn-ghost"><?= $isEdit ? 'Rétablir' : 'Effacer' ?></button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <?php if ($lastSaved !== null): ?>
            <section class="card proof <?= $lastSaved['verified'] ? 'proof-ok' : 'proof-err' ?>" aria-live="polite">
                <span class="proof-icon"><?= icon($lastSaved['verified'] ? 'check' : 'alert') ?></span>
                <div class="proof-body">
                    <h2><?= $lastSaved['verified'] ? 'Enregistré et vérifié dans la base' : 'Écriture non confirmée' ?></h2>
                    <p class="muted small mono">1. <?= h($lastSaved['write']) ?> &nbsp;·&nbsp; 2. relecture <?= h($lastSaved['read']) ?></p>
                    <?php if (is_array($lastSaved['row'])): ?>
                    <dl class="proof-values">
                        <div><dt>id</dt><dd class="mono"><?= h($lastSaved['row']['id']) ?></dd></div>
                        <?php foreach ($definition['fields'] as $name => $field): ?>
                        <div><dt><?= h($field['label']) ?></dt><dd><?= h($display($field, $lastSaved['row'][$name] ?? null)) ?></dd></div>
                        <?php endforeach; ?>
                        <div><dt>Enregistré le (UTC)</dt><dd class="mono"><?= h($lastSaved['row']['updated_at'] ?? '') ?></dd></div>
                    </dl>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php // ───── Liste des lignes réellement présentes dans la base ───── ?>
            <section class="card">
                <div class="card-head">
                    <div><h2><?= h($definition['label']) ?> enregistré<?= $fem ? 'e' : '' ?>s</h2><p class="muted small" data-live-region="count">Lu par l'API : <code>GET /data/<?= h($page) ?></code> · <strong><?= h($view['total']) ?></strong> ligne<?= $view['total'] > 1 ? 's' : '' ?><?= $q !== '' || $dispo !== '' ? ' trouvée' . ($view['total'] > 1 ? 's' : '') : ' dans la base' ?></p></div>
                    <div class="list-tools">
                        <a class="btn btn-primary" href="<?= h(url(['page' => $page] + $listState)) ?>#formulaire" data-focus-form><?= icon('plus') ?>Insérer</a>
                        <a class="btn btn-ghost" href="<?= h(url(['page' => $page] + $listState)) ?>" title="Relire depuis la base"><?= icon('refresh') ?>Actualiser</a>
                        <form method="post" action="<?= h(url(['page' => $page])) ?>">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="table" value="<?= h($page) ?>">
                            <button class="btn btn-secondary" name="action" value="seed"><?= icon('database') ?>Insérer des données d'exemple</button>
                        </form>
                        <?php if ($view['total'] > 0): ?>
                        <button type="button" class="btn btn-danger-soft" data-dialog="all-dialog"><?= icon('trash') ?>Supprimer toutes les données</button>
                        <?php endif; ?>
                    </div>
                </div>

                <form class="toolbar" method="get" action="index.php" role="search" data-live-search>
                    <input type="hidden" name="page" value="<?= h($page) ?>">
                    <label class="search"><?= icon('search') ?><span class="sr-only">Rechercher</span><input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher par nom… (résultats pendant la frappe)" autocomplete="off" spellcheck="false"><span class="spinner" data-live-spinner hidden></span></label>
                    <?php if ($page === 'produits'): ?>
                    <label class="sr-only" for="dispo">Disponibilité</label>
                    <select id="dispo" name="dispo">
                        <option value="">Tous les produits</option>
                        <option value="oui"<?= $dispo === 'oui' ? ' selected' : '' ?>>Disponibles</option>
                        <option value="non"<?= $dispo === 'non' ? ' selected' : '' ?>>Indisponibles</option>
                    </select>
                    <?php endif; ?>
                    <?php if ($sort !== 'id.desc'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
                </form>

                <div data-live-region="list">

                <?php if ($view['missing']): ?>
                <div class="notice notice-warn"><?= icon('database') ?><p>Table absente du projet : exécutez <code>database.sql</code> dans l'<strong>Éditeur SQL</strong>.</p></div>
                <?php elseif ($view['error'] !== null): ?>
                <div class="notice notice-err"><?= icon('alert') ?><p><?= h($view['error']) ?></p></div>
                <?php elseif ($view['rows'] === []): ?>
                <div class="empty">
                    <p><?= $q !== '' || $dispo !== '' ? 'Aucun résultat pour ces filtres.' : 'Aucune donnée dans cette table pour l\'instant.' ?></p>
                    <?php if ($q === '' && $dispo === ''): ?><p class="small">Utilisez le formulaire ci-dessus, ou « Insérer des données d'exemple ».</p><?php endif; ?>
                </div>
                <?php else: ?>
                <div class="bulkbar">
                    <span class="muted small"><span data-selected-count>0</span> ligne(s) cochée(s)</span>
                    <button type="button" class="btn btn-ghost btn-xs text-err" data-dialog="bulk-dialog" data-bulk-button disabled><?= icon('trash', 'icon icon-xs') ?>Supprimer la sélection</button>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th class="check-col"><input type="checkbox" data-select-all aria-label="Tout cocher"></th>
                                <?php foreach (array_merge(['id'], $definition['columns']) as $col): ?>
                                <?php
                                    $label = $col === 'id' ? 'id' : $definition['fields'][$col]['label'];
                                    $type = $col === 'id' ? 'integer' : $definition['fields'][$col]['type'];
                                    $next = $sort === $col . '.asc' ? $col . '.desc' : $col . '.asc';
                                ?>
                                <th class="<?= in_array($type, ['money', 'integer'], true) ? 'num' : '' ?>">
                                    <a href="<?= h(url(['page' => $page, 'sort' => $next, 'q' => $q, 'dispo' => $dispo])) ?>" class="sort<?= str_starts_with($sort, $col . '.') ? ' is-sorted' : '' ?>"><?= h($label) ?><?php if ($sort === $col . '.asc'): ?><?= icon('arrow-up', 'icon icon-xs') ?><?php elseif ($sort === $col . '.desc'): ?><?= icon('arrow-down', 'icon icon-xs') ?><?php endif; ?></a>
                                </th>
                                <?php endforeach; ?>
                                <th>Enregistré</th>
                                <th class="actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($view['rows'] as $row): ?>
                            <?php $isSaved = $lastSaved !== null && (string) $lastSaved['id'] === (string) $row['id']; $isEditing = $isEdit && (string) $editing['id'] === (string) $row['id']; ?>
                            <tr id="ligne-<?= h($row['id']) ?>" class="<?= $isSaved ? 'is-saved' : '' ?><?= $isEditing ? ' is-editing-row' : '' ?>">
                                <td class="check-col"><input type="checkbox" name="ids[]" value="<?= h($row['id']) ?>" form="bulk-form" data-row-check aria-label="Cocher <?= h($row['nom'] ?? '') ?>"></td>
                                <td class="mono muted"><?= h($row['id']) ?></td>
                                <?php foreach ($definition['columns'] as $col): ?>
                                <?php $field = $definition['fields'][$col]; $value = $row[$col] ?? null; ?>
                                <?php if ($field['type'] === 'boolean'): ?>
                                <td><span class="pill <?= $value ? 'pill-ok' : 'pill-muted' ?>"><?= $value ? 'Oui' : 'Non' ?></span></td>
                                <?php elseif ($field['type'] === 'money'): ?>
                                <td class="num"><?= h(money($value)) ?></td>
                                <?php elseif ($field['type'] === 'integer'): ?>
                                <td class="num<?= $col === 'stock' && (int) $value === 0 ? ' text-err' : '' ?>"><?= h($value) ?></td>
                                <?php else: ?>
                                <td class="<?= $col === 'nom' ? 'strong' : 'muted' ?> clip"><?= $value === null || $value === '' ? '<span class="muted">—</span>' : h($display($field, $value)) ?></td>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <td class="muted small"><?= h(time_since($row['updated_at'] ?? null)) ?></td>
                                <td class="actions"><div class="row-actions">
                                    <a class="icon-btn" href="<?= h(url(['page' => $page, 'form' => (string) $row['id']] + $listState)) ?>#formulaire" aria-label="Modifier <?= h($row['nom'] ?? '') ?>" title="Modifier"><?= icon('pencil') ?></a>
                                    <button type="button" class="icon-btn danger" data-delete="<?= h($row['id']) ?>" data-name="<?= h($row['nom'] ?? '') ?>" aria-label="Supprimer <?= h($row['nom'] ?? '') ?>" title="Supprimer"><?= icon('trash') ?></button>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pages > 1): ?>
                <nav class="pager" aria-label="Pagination">
                    <?php $link = static fn (int $n): string => url(['page' => $page, 'p' => (string) $n, 'q' => $q, 'sort' => $sort === 'id.desc' ? '' : $sort, 'dispo' => $dispo]); ?>
                    <a class="btn btn-ghost<?= $p <= 1 ? ' is-disabled' : '' ?>" href="<?= h($link(max(1, $p - 1))) ?>">Précédent</a>
                    <span class="muted small">Page <?= h($p) ?> sur <?= h($pages) ?> · <code>limit=<?= PAGE_SIZE ?>&amp;offset=<?= h(($p - 1) * PAGE_SIZE) ?></code></span>
                    <a class="btn btn-ghost<?= $p >= $pages ? ' is-disabled' : '' ?>" href="<?= h($link(min($pages, $p + 1))) ?>">Suivant</a>
                </nav>
                <?php endif; ?>
                <?php endif; ?>
                </div>
            </section>

            <?php // ───── Confirmations (jamais de confirm() natif) ───── ?>
            <dialog class="modal modal-sm" id="delete-dialog" aria-labelledby="delete-title">
                <form method="post" action="<?= h(url(['page' => $page])) ?>">
                    <?= $stateFields() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="table" value="<?= h($page) ?>">
                    <input type="hidden" name="id" value="" data-delete-id>
                    <div class="modal-body confirm">
                        <span class="confirm-icon"><?= icon('trash') ?></span>
                        <div>
                            <h2 id="delete-title">Supprimer « <span data-delete-name></span> » ?</h2>
                            <p class="muted small">Requête envoyée : <code class="mono">DELETE /data/<?= h($page) ?>/<span data-delete-label></span></code>. Cette action est définitive.</p>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" data-close>Annuler</button>
                        <button class="btn btn-danger"><?= icon('trash') ?>Supprimer</button>
                    </div>
                </form>
            </dialog>

            <dialog class="modal modal-sm" id="bulk-dialog" aria-labelledby="bulk-title">
                <form method="post" action="<?= h(url(['page' => $page])) ?>" id="bulk-form">
                    <?= $stateFields() ?>
                    <input type="hidden" name="action" value="delete_selected">
                    <input type="hidden" name="table" value="<?= h($page) ?>">
                    <div class="modal-body confirm">
                        <span class="confirm-icon"><?= icon('trash') ?></span>
                        <div>
                            <h2 id="bulk-title">Supprimer <span data-selected-count>0</span> ligne(s) ?</h2>
                            <p class="muted small">Une seule requête : <code class="mono">DELETE /data/<?= h($page) ?>?id=in.(…)</code>. Cette action est définitive.</p>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" data-close>Annuler</button>
                        <button class="btn btn-danger"><?= icon('trash') ?>Supprimer la sélection</button>
                    </div>
                </form>
            </dialog>

            <dialog class="modal modal-sm" id="all-dialog" aria-labelledby="all-title">
                <form method="post" action="<?= h(url(['page' => $page])) ?>">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_all">
                    <input type="hidden" name="table" value="<?= h($page) ?>">
                    <div class="modal-body confirm">
                        <span class="confirm-icon"><?= icon('alert') ?></span>
                        <div>
                            <h2 id="all-title">Supprimer toutes les données de « <?= h($page) ?> » ?</h2>
                            <p class="muted small">Les <?= h($view['total']) ?> ligne(s) seront effacées (<code class="mono">DELETE /data/<?= h($page) ?>?id=gt.0</code>). La table reste en place : vous pourrez réinsérer des données.<?= $page === 'categories' ? ' Les produits liés resteront, sans catégorie.' : '' ?></p>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn btn-ghost" data-close>Annuler</button>
                        <button class="btn btn-danger"><?= icon('trash') ?>Tout supprimer</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
        </main>
    </div>

    <?php if ($configured): ?>
    <aside class="journal card" aria-labelledby="journal-title">
        <div class="card-head">
            <h2 id="journal-title"><?= icon('activity') ?>Appels API</h2>
            <?php if ($journal !== []): ?>
            <form method="post" action="<?= h(url(['page' => $page])) ?>"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><button class="btn btn-ghost btn-xs" name="action" value="clear_log">Vider</button></form>
            <?php endif; ?>
        </div>
        <p class="muted small">Chaque requête réellement envoyée à NovaBase, avec son code et sa durée.</p>
        <?php if ($journal === []): ?>
        <p class="empty small">Aucun appel pour l'instant.</p>
        <?php else: ?>
        <ul class="log">
            <?php foreach ($journal as $entry): ?>
            <li>
                <span class="method m-<?= h(strtolower($entry['method'])) ?>"><?= h($entry['method']) ?></span>
                <span class="log-path mono" title="<?= h($entry['path']) ?>"><?= h($entry['path']) ?></span>
                <span class="code-badge <?= h(status_class($entry['status'])) ?>"><?= h($entry['status'] === 0 ? '—' : $entry['status']) ?></span>
                <span class="log-ms mono muted"><?= h($entry['ms']) ?> ms</span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </aside>
    <?php endif; ?>
</div>

<div class="toasts" aria-live="polite">
    <?php foreach ($flashes as $flash): ?>
    <div class="toast toast-<?= h($flash['type']) ?>" role="status" data-toast>
        <?= icon($flash['type'] === 'success' ? 'check' : 'alert') ?>
        <p><?= h($flash['message']) ?></p>
        <button type="button" class="icon-btn" data-toast-close aria-label="Fermer"><?= icon('x') ?></button>
    </div>
    <?php endforeach; ?>
</div>
</body>
</html>
