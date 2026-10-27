-- ═══════════════════════════════════════════════════════════════════════════
--  Requêtes CRUD à essayer dans l'Éditeur SQL de NovaBase (une par une ou toutes).
--  Après chaque requête, rechargez l'interface de test : le changement y apparaît,
--  car elle lit les données via l'API.
-- ═══════════════════════════════════════════════════════════════════════════

-- CREATE : ajouter un produit
INSERT INTO produits (nom, prix, stock, categorie_id) VALUES ('Sucre roux 1 kg', 1800, 15, 2);

-- READ : lister, filtrer, trier
SELECT * FROM produits ORDER BY id DESC;
SELECT nom, prix FROM produits WHERE disponible = TRUE AND prix < 5000 ORDER BY prix;
SELECT c.nom AS categorie, COUNT(p.id) AS nb_produits, SUM(p.stock) AS stock_total
FROM categories c LEFT JOIN produits p ON p.categorie_id = c.id
GROUP BY c.id, c.nom;

-- UPDATE : modifier
UPDATE produits SET stock = stock + 10 WHERE nom = 'Sucre roux 1 kg';
UPDATE produits SET disponible = FALSE WHERE stock = 0;

-- DELETE : supprimer
DELETE FROM produits WHERE nom = 'Sucre roux 1 kg';

-- Structure
SHOW TABLES;
DESCRIBE produits;

-- Ajouter une colonne : l'API et l'interface la prennent en compte aussitôt
-- ALTER TABLE produits ADD COLUMN reference VARCHAR(30) NULL;
