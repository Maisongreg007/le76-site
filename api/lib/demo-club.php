<?php
/** Démo (le76.fr/demo) : clients fictifs du Club, adresses en example.test (aucun e-mail ne part). */
declare(strict_types=1);

function demo_club_seed(PDO $pdo, bool $vider = false): void
{
    if ($vider) {
        foreach (['club_envois', 'club_offres', 'club_cadeaux', 'club_visites', 'club_accords', 'club_journal', 'club_membres', 'club_reglages'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
    }
    $auj = date('Y-m-d');
    // prénom, langue, accepte les offres, nb de visites, jours depuis la dernière, intervalle, cadeaux déjà remis
    $clients = [
        ['Camille', 'fr', 1, 11, 2, 9, 1], ['Thomas', 'fr', 1, 5, 6, 12, 0], ['Julia', 'de', 1, 7, 15, 10, 1],
        ['Sophie', 'fr', 1, 3, 41, 20, 0], ['Marc', 'fr', 0, 4, 9, 14, 0], ['Lena', 'de', 1, 2, 60, 30, 0],
        ['Nicolas', 'fr', 1, 6, 1, 7, 0], ['Inès', 'fr', 1, 1, 3, 0, 0], ['Paul', 'fr', 0, 0, 0, 0, 0],
        ['Élodie', 'fr', 1, 9, 35, 11, 1],
    ];
    $insM = $pdo->prepare('INSERT INTO club_membres (carte, jeton, prenom, email, langue, offres, offres_le, origine, inscrit_le, inscrit_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insA = $pdo->prepare("INSERT INTO club_accords (membre_id, quand, accord, source, version) VALUES (?, ?, 1, 'inscription', ?)");
    $insV = $pdo->prepare("INSERT INTO club_visites (membre_id, jour, quand, par) VALUES (?, ?, ?, 'Démo')");
    $insC = $pdo->prepare("INSERT INTO club_cadeaux (membre_id, rang, libelle, quand, par) VALUES (?, ?, 'Un dessert offert', ?, 'Démo')");
    $ids = [];
    foreach ($clients as [$prenom, $l, $offres, $nb, $depuis, $pas, $cadeaux]) {
        $email = strtolower(strtr($prenom, ['É' => 'e', 'è' => 'e', 'é' => 'e'])) . '@example.test';
        $premiere = date('Y-m-d', strtotime("-" . ($depuis + max(0, $nb - 1) * $pas + 3) . ' days'));
        $insM->execute([club_nouvelle_carte($pdo), jeton(), $prenom, $email, $l, $offres, $offres ? "$premiere 12:00:00" : null, $nb ? 'table' : 'site', "$premiere 12:00:00", '']);
        $id = (int) $pdo->lastInsertId();
        $ids[$prenom] = $id;
        if ($offres) {
            $insA->execute([$id, "$premiere 12:00:00", CLUB_VERSION_ACCORD]);
        }
        for ($i = 0; $i < $nb; $i++) {
            $j = date('Y-m-d', strtotime("-" . ($depuis + $i * $pas) . ' days'));
            $insV->execute([$id, $j, "$j 12:30:00"]);
        }
        for ($c = 1; $c <= $cadeaux; $c++) {
            $insC->execute([$id, $c, date('Y-m-d H:i:s', strtotime('-20 days'))]);
        }
    }
    // Une campagne déjà simulée, valable maintenant, et un brouillon
    $fin = date('Y-m-d', strtotime('+12 days'));
    $pdo->prepare("INSERT INTO club_offres (titre, objet, objet_de, message, message_de, debut, fin, jours, services, cible, statut, simulation, nb_dest, cree_le, cree_par, valide_le, valide_par)
        VALUES (?, ?, ?, ?, ?, ?, ?, '1234567', 'midi,soir', 'absents', 'simulee', 1, 0, ?, 'Greg', ?, 'Greg')")
        ->execute(['On vous a gardé une place', 'On vous a gardé une place au 76', 'Wir haben Ihnen einen Platz freigehalten',
            "Bonjour {prenom},\n\nÇa fait un moment ! Pour votre retour au 76, le café gourmand vous est offert avec votre repas.\n\nÀ très vite,\nGreg, Vanessa et toute l'équipe",
            "Hallo {prenom},\n\nschon eine Weile her! Zu Ihrer Rückkehr ins Le 76 schenken wir Ihnen zum Essen einen Café gourmand.\n\nBis bald,\nGreg, Vanessa und das ganze Team",
            date('Y-m-d', strtotime('-3 days')), $fin, date('Y-m-d H:i:s', strtotime('-4 days')), date('Y-m-d H:i:s', strtotime('-3 days'))]);
    $oid = (int) $pdo->lastInsertId();
    $n = 0;
    foreach (['Sophie', 'Lena', 'Élodie'] as $p) {
        $pdo->prepare("INSERT INTO club_envois (offre_id, membre_id, statut) VALUES (?, ?, 'simule')")->execute([$oid, $ids[$p]]);
        $n++;
    }
    $pdo->prepare('UPDATE club_offres SET nb_dest = ? WHERE id = ?')->execute([$n, $oid]);
    $pdo->prepare("INSERT INTO club_offres (titre, objet, message, debut, fin, jours, services, cible, cree_le, cree_par)
        VALUES (?, ?, ?, ?, ?, '234', 'midi', 'habitues', ?, 'Greg')")
        ->execute(['Midi en semaine', 'Un petit plaisir vous attend ce midi au 76',
            "Bonjour {prenom},\n\nMerci d'être un fidèle du 76 ! Du mardi au jeudi midi, votre dessert est offert avec le plat du jour.\n\nRéservez au 03 88 86 31 87 ou sur le76.fr.\n\nÀ bientôt !",
            $auj, date('Y-m-d', strtotime('+21 days')), maintenant()]);
}
