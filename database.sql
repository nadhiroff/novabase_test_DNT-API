-- ═══════════════════════════════════════════════════════════════════════════
--  Base de test CRUD pour NovaBase
--
--  1. Console NovaBase → votre projet → onglet « Éditeur SQL »
--  2. Collez TOUT ce fichier, puis cliquez sur « Exécuter » (ou Ctrl + Entrée)
--  3. Les tables « categories » et « produits » apparaissent dans l'API :
--       GET  {NOVABASE_URL}/data/produits
--       POST {NOVABASE_URL}/data/categories …
--
--  Règle NovaBase : chaque table a une clé primaire « id » auto-incrémentée.
--  Les colonnes created_at / updated_at sont gérées automatiquement.
-- ═══════════════════════════════════════════════════════════════════════════

-- Catégories de produits
CREATE TABLE categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nom         VARCHAR(80)  NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Produits, liés à une catégorie (si la catégorie est supprimée, le produit reste sans catégorie)
CREATE TABLE produits (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nom          VARCHAR(120)  NOT NULL,
  description  TEXT          NULL,
  prix         DECIMAL(10,2) NOT NULL DEFAULT 0,
  stock        INT           NOT NULL DEFAULT 0,
  disponible   BOOLEAN       NOT NULL DEFAULT TRUE,
  categorie_id BIGINT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_produits_categorie FOREIGN KEY (categorie_id) REFERENCES categories (id) ON DELETE SET NULL
);

-- Données de départ
INSERT INTO categories (nom, description) VALUES
  ('Boissons', 'Cafés, thés et jus'),
  ('Épicerie', 'Produits secs et conserves'),
  ('Fruits',   'Fruits frais de saison');

INSERT INTO produits (nom, description, prix, stock, disponible, categorie_id) VALUES
  ('Café arabica 250 g',  'Torréfaction artisanale',        4500.00, 24, TRUE,  1),
  ('Thé vert à la menthe', 'Boîte de 20 sachets',            2500.00, 40, TRUE,  1),
  ('Jus de bissap 1 L',   'Fleurs d''hibiscus, peu sucré',   1500.00,  0, FALSE, 1),
  ('Riz parfumé 5 kg',    NULL,                              7500.00, 12, TRUE,  2),
  ('Huile d''olive 1 L',  'Première pression à froid',       6000.00,  8, TRUE,  2),
  ('Mangues (le kilo)',   'Variété Julie',                   1200.00, 30, TRUE,  3);

-- Vérification : doit afficher 6 produits avec leur catégorie
SELECT p.id, p.nom, p.prix, p.stock, c.nom AS categorie
FROM produits p LEFT JOIN categories c ON c.id = p.categorie_id
ORDER BY p.id;


-- ───────────────────────────────────────────────────────────────────────────
--  Pour tout recommencer : exécutez seulement ces deux lignes (sans « -- »),
--  puis ré-exécutez le fichier en entier.
-- ───────────────────────────────────────────────────────────────────────────
-- DROP TABLE produits;
-- DROP TABLE categories;
