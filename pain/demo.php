<?php
/**
 * Mode démo (le76.fr/demo/pain) : base séparée et fictive, renouvelée chaque jour
 * (ou avec le bouton « Réinitialiser »). Aucun paiement réel, aucun code d'accès.
 */
declare(strict_types=1);

function demo_preparer(bool $forcer = false): void
{
    $pdo = db();
    $st = $pdo->prepare("SELECT valeur FROM reglages WHERE cle = 'demo_jour'");
    $st->execute();
    if (!$forcer && $st->fetchColumn() === date('Y-m-d')) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    foreach (['lignes', 'commandes', 'journal', 'tentatives', 'reglages', 'produits'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'produits'");
    $st = $pdo->prepare('INSERT INTO produits (nom, noms, emoji, prix, cout, actif, ordre, cree_le) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
    foreach (P_PRODUITS_DEFAUT as $i => $p) {
        $st->execute([$p['name'], json_encode($p['names'], JSON_UNESCAPED_UNICODE), $p['emoji'], $p['price'], $p['cost'], $i + 1, maintenant()]);
    }
    $pdo->exec('COMMIT');
    generer_exemples();
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('demo_jour', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([date('Y-m-d')]);
}
