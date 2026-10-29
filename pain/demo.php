<?php
/**
 * Mode démo (le76.fr/demo/pain) : base séparée et fictive, renouvelée chaque jour
 * (ou avec le bouton « Réinitialiser »). Aucun paiement réel, aucun code d'accès.
 */
declare(strict_types=1);

/**
 * École : une vraie journée de saison. Environ 120 baguettes, 100 pains au chocolat et 80 croissants
 * précommandés par jour, une cinquantaine de clients, sur la semaine passée, aujourd'hui et demain :
 * - aujourd'hui : commandes payées à remettre (et quelques-unes déjà retirées) ;
 * - demain : commandes payées, plus une dizaine « à payer » à encaisser au comptoir (espèces ou carte) ;
 * - stock de dépannage de 10 par produit pour s'entraîner à la vente aux retardataires.
 */
function ecole_generer(): void
{
    $pdo = db();
    $pdo->exec('UPDATE produits SET depannage = 10');
    $produits = array_values(array_filter(produits(false), fn($p) => $p['active']));
    if (!$produits) {
        return;
    }
    $cibles = ['Baguette' => 120, 'Pain au chocolat' => 100, 'Croissant' => 80];
    $prenoms = ['Lukas', 'Anna', 'Jan', 'Sophie', 'Thomas', 'Emma', 'Pieter', 'Julia', 'Marc', 'Léa', 'Stefan', 'Claire', 'Daan', 'Laura', 'Hugo', 'Mia',
        'Paul', 'Lotte', 'Nicolas', 'Hannah', 'Felix', 'Sanne', 'Julien', 'Lena', 'Bram', 'Camille', 'Max', 'Noor', 'Antoine', 'Klara', 'Ruben', 'Chloé',
        'Tim', 'Eva', 'Louis', 'Mila', 'Jonas', 'Inès', 'Thijs', 'Marie'];
    $noms = ['Müller', 'Schmidt', 'De Vries', 'Bakker', 'Martin', 'Bernard', 'Weber', 'Janssen', 'Dubois', 'Fischer', 'Peeters', 'Meyer', 'Lefèvre',
        'Wagner', 'Visser', 'Roux', 'Becker', 'Smit', 'Girard', 'Hoffmann', 'Schulz', 'Mulder', 'Lambert', 'Koch', 'De Jong', 'Fontaine', 'Richter',
        'Van Dijk', 'Mercier', 'Klein', 'Bos', 'Blanc', 'Wolf', 'Vermeulen', 'Garnier', 'Schröder', 'Jacobs', 'Faure', 'Neumann', 'Hendriks'];
    $langues = ['de', 'de', 'de', 'nl', 'nl', 'nl', 'fr', 'fr', 'en'];
    mt_srand(crc32(date('Y-m-d')));
    $ins = $pdo->prepare("INSERT INTO commandes (jeton, code, cle, prenom, nom, emplacement, telephone, remarque, langue, jour, statut, paye, paiement, total, cout, origine, cree_le, modifie_le, retire_le)
        VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 'client', ?, ?, ?)");
    $remarques = ['Bien cuite svp', 'Pas trop cuite', 'Merci !', 'On passe vers 9 h'];
    $pdo->exec('BEGIN IMMEDIATE');
    for ($d = -6; $d <= 1; $d++) {
        $jour = date('Y-m-d', strtotime("$d day"));
        $reste = [];
        foreach ($produits as $p) {
            $c = $cibles[$p['name']] ?? 20;
            $reste[$p['id']] = $d < 0 ? (int) round($c * mt_rand(85, 112) / 100) : $c;
        }
        $n = 0;
        while (array_sum($reste) > 0 && $n < 120) {
            $lignes = [];
            foreach ($produits as $p) {
                $max = ['Baguette' => 4, 'Pain au chocolat' => 4, 'Croissant' => 3][$p['name']] ?? 2;
                $proba = ['Baguette' => 85, 'Pain au chocolat' => 65, 'Croissant' => 55][$p['name']] ?? 30;
                $q = mt_rand(1, 100) <= $proba ? min($reste[$p['id']], mt_rand(1, $max)) : 0;
                if ($q > 0) {
                    $reste[$p['id']] -= $q;
                    $lignes[] = ['produit_id' => $p['id'], 'nom' => $p['name'], 'emoji' => $p['emoji'], 'prix' => $p['price'], 'cout' => $p['cost'], 'quantite' => $q];
                }
            }
            if (!$lignes) {
                continue;
            }
            $n++;
            $r = mt_rand(1, 100);
            if ($d < 0) {
                [$statut, $paye] = $r <= 96 ? ['collected', 1] : ['confirmed', 1];
            } elseif ($d === 0) {
                [$statut, $paye] = $r <= 30 ? ['collected', 1] : ['confirmed', 1];
            } else {
                [$statut, $paye] = $r <= 80 ? ['confirmed', 1] : ['pending', 0];
            }
            $m = mt_rand(1, 100);
            $methode = $paye ? ($m <= 55 ? 'en_ligne' : ($m <= 80 ? 'especes' : 'carte')) : '';
            $cree = date('Y-m-d H:i:s', strtotime($jour . ' -1 day') + mt_rand(8 * 3600, 19 * 3600 + 3540));
            $retire = $statut === 'collected' ? $jour . ' ' . sprintf('%02d:%02d:00', mt_rand(8, $d === 0 ? 9 : 11), mt_rand(0, 59)) : null;
            $ins->execute([jeton(), nouveau_code(), $prenoms[mt_rand(0, count($prenoms) - 1)], $noms[mt_rand(0, count($noms) - 1)], (string) mt_rand(1, 240),
                '+' . ['49 17', '31 6', '33 6', '32 47'][mt_rand(0, 3)] . mt_rand(1000000, 9999999), mt_rand(1, 100) <= 6 ? $remarques[mt_rand(0, 3)] : '',
                $langues[mt_rand(0, count($langues) - 1)], $jour, $statut, $paye, $methode, $cree, $cree, $retire]);
            $id = (int) $pdo->lastInsertId();
            [$total, $cout] = enregistrer_lignes($id, $lignes);
            $pdo->prepare('UPDATE commandes SET total = ?, cout = ? WHERE id = ?')->execute([$total, $cout, $id]);
        }
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
    if (defined('PAIN_ECOLE')) {
        ecole_generer();
    } else {
        generer_exemples();
    }
    $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES ('demo_jour', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
        ->execute([date('Y-m-d')]);
}
