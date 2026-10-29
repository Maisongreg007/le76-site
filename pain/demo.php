<?php
/**
 * Mode démo (le76.fr/demo/pain) : base séparée et fictive, renouvelée chaque jour
 * (ou avec le bouton « Réinitialiser »). Aucun paiement réel, aucun code d'accès.
 */
declare(strict_types=1);

/** École : des commandes du jour « à payer », pour s'entraîner à encaisser (espèces / carte) puis remettre. */
function ecole_exercices(): void
{
    $clients = [['Anna', 'Schmidt', '12'], ['Pieter', 'De Vries', '47'], ['Julie', 'Martin', '103'], ['Lukas', 'Weber', '8'],
        ['Emma', 'Janssen', '65'], ['Thomas', 'Dubois', '21'], ['Sophie', 'Fischer', '88'], ['Daan', 'Bakker', '130']];
    $produits = array_values(array_filter(produits(false), fn($p) => $p['active']));
    if (!$produits) {
        return;
    }
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    foreach ($clients as $i => [$prenom, $nom, $empl]) {
        $lignes = [];
        foreach ($produits as $k => $p) {
            $q = ($i + $k) % 3;
            if ($q > 0) {
                $lignes[] = ['produit_id' => $p['id'], 'nom' => $p['name'], 'emoji' => $p['emoji'], 'prix' => $p['price'], 'cout' => $p['cost'], 'quantite' => $q];
            }
        }
        $cree = date('Y-m-d H:i:s', strtotime('yesterday 18:00') + $i * 517);
        $pdo->prepare("INSERT INTO commandes (jeton, code, cle, prenom, nom, emplacement, telephone, remarque, langue, jour, statut, paye, paiement, total, cout, origine, cree_le, modifie_le)
            VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'fr', ?, 'pending', 0, '', 0, 0, 'client', ?, ?)")
            ->execute([jeton(), nouveau_code(), $prenom, $nom, $empl, '06 12 34 56 ' . sprintf('%02d', 10 + $i), $i === 2 ? 'Bien cuite svp' : '', date('Y-m-d'), $cree, $cree]);
        $id = (int) $pdo->lastInsertId();
        [$total, $cout] = enregistrer_lignes($id, $lignes);
        $pdo->prepare('UPDATE commandes SET total = ?, cout = ? WHERE id = ?')->execute([$total, $cout, $id]);
    }
    $pdo->exec('COMMIT');
}

function demo_preparer(bool $forcer = false): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT valeur FROM reglages WHERE cle = 'demo_jour'");
    $st->execute();
    if (!$forcer && $st->fetchColumn() === date('Y-m-d')) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    foreach (['lignes', 'commandes', 'journal', 'tentatives', 'reglages', 'produits', 'impressions'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'produits'");
    $st = $pdo->prepare('INSERT INTO produits (nom, noms, emoji, prix, cout, actif, ordre, cree_le) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
    foreach (P_PRODUITS_DEFAUT as $i => $p) {
        $st->execute([$p['name'], json_encode($p['names'], JSON_UNESCAPED_UNICODE), $p['emoji'], $p['price'], $p['cost'], $i + 1, maintenant()]);
    }
    $pdo->exec('COMMIT');
    generer_exemples();
    if (defined('PAIN_ECOLE')) {
        ecole_exercices();
    }
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('demo_jour', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([date('Y-m-d')]);
}
