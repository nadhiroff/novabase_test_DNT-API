# Banc CRUD pour NovaBase

Petite application PHP (sans framework ni dépendance) qui teste votre projet NovaBase
**uniquement par l'API REST**, comme le ferait votre vraie application.

Ouvrir : <http://localhost/novabase-e2e/>

## Mise en route

1. **Créer les tables** : console NovaBase → votre projet → onglet **Éditeur SQL** →
   collez tout le contenu de `database.sql` → **Exécuter** (ou Ctrl + Entrée).
   Les tables `categories` et `produits` sont créées, remplies et disponibles dans l'API.
2. **Compléter `config.php`** :
   - `NOVABASE_URL` : vue d'ensemble du projet → bloc « Intégration » ;
   - `NOVABASE_KEY` : onglet « Clés API » → la clé **secrète** `sk_live_…`
     (une clé publique ne permet que la lecture).
3. **Recharger la page** : le voyant passe au vert « Connecté ».

## Ce que vous pouvez tester

| Écran | Appels API |
|---|---|
| Formulaire toujours visible en haut de Produits / Catégories (ajout, ou modification avec le crayon) | `POST /data/produits` · `PATCH /data/produits/{id}` |
| **Preuve d'enregistrement** : après chaque ajout ou modification, la ligne est relue depuis la base et affichée (encadré vert + ligne en surbrillance) | `GET /data/produits/{id}` |
| Liste des lignes enregistrées : recherche, tri, pagination, filtre, colonne « Enregistré » | `GET /data/produits?nom=ilike.*…*&order=prix.desc&limit=10&offset=…` |
| Supprimer une ligne (corbeille) | `DELETE /data/produits/{id}` |
| Cocher plusieurs lignes → « Supprimer la sélection » | `DELETE /data/produits?id=in.(3,7,9)` (une seule requête) |
| « Supprimer toutes les données » (la table reste) | `DELETE /data/produits?id=gt.0` |
| « Insérer des données d'exemple » | `POST /data/categories` et `POST /data/produits` avec un tableau |
| Test automatique | crée, relit, filtre, modifie, valide (422), supprime puis vérifie le 404 |

Le panneau **Appels API** montre chaque requête réellement envoyée, son code HTTP et sa durée.
Les erreurs de NovaBase (422 valeur invalide, 409 doublon, 401 clé refusée, 429 limite atteinte…)
s'affichent telles quelles, sous le champ concerné quand c'est possible.

Dans la console NovaBase, la page **Tables** montre aussi le nombre exact de lignes et les
dernières lignes enregistrées de chaque table ; la grille d'une table permet de cocher des lignes,
de les supprimer, de vider la table et d'actualiser.

## Fichiers

- `config.php` : les deux valeurs à compléter.
- `database.sql` : création des tables et données de départ (à coller dans l'Éditeur SQL).
- `requetes-exemples.sql` : requêtes CRUD à essayer dans l'Éditeur SQL.
- `index.php` : l'interface ; `lib/NovaBase.php` : le client API (réutilisable dans vos projets) ;
  `lib/AutoTest.php` : le test automatique ; `lib/schema.php` : les champs des formulaires ;
  `lib/samples.php` : les données d'exemple.

La clé reste côté serveur : elle n'est jamais envoyée au navigateur.
# novabase_test_DNT-API
