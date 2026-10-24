<?php
/*
 * Données d'exemple de la démo des réservations (le76.fr/demo). Clients fictifs uniquement.
 * Les dates sont calculées par rapport à aujourd'hui.
 */
declare(strict_types=1);

function demo_resa_seed(PDO $pdo, bool $reset = false): void
{
    if ($reset) {
        foreach (['reservations', 'utilisateurs', 'sessions', 'jours', 'push', 'abonnes', 'journal_mails', 'tentatives', 'reinit_mdp'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $pdo->exec("DELETE FROM reglages");
    }
    $now = date('Y-m-d H:i:s');
    $u = $pdo->prepare('INSERT INTO utilisateurs (nom, identifiant, mdp, admin, actif, cree_le) VALUES (?,?,?,?,1,?)');
    $u->execute(['Démo', 'demo', password_hash('demo1234', PASSWORD_DEFAULT), 1, $now]);
    $u->execute(['Sophie', 'sophie', password_hash('demo1234', PASSWORD_DEFAULT), 0, $now]);

    // Réglages : alertes vers une adresse fictive, rien n'est envoyé en démo
    $pdo->prepare('INSERT OR REPLACE INTO reglages (cle, valeur) VALUES (?, ?)')->execute(['alertes_email', json_encode(['equipe@exemple.fr'])]);

    $noms = ['Muller', 'Schmitt', 'Meyer', 'Weber', 'Klein', 'Wagner', 'Fischer', 'Roth', 'Hoffmann', 'Bauer', 'Lang', 'Keller', 'Martin', 'Bernard', 'Dubois', 'Laurent', 'Simon', 'Michel', 'Lefebvre', 'Moreau', 'Becker', 'Schneider', 'Koch', 'Richter'];
    $prenoms = ['Marc', 'Julie', 'Thomas', 'Anne', 'Pierre', 'Claire', 'Nicolas', 'Sandrine', 'Paul', 'Isabelle', 'Luc', 'Céline', 'Jean', 'Laura', 'Michel', 'Sarah'];
    $remarques = ['', '', '', '', 'Anniversaire, gâteau prévu', 'Chaise haute', 'Allergie aux fruits à coque', 'Plutôt en terrasse', 'Repas d\'affaires', 'Un fauteuil roulant', ''];
    $ins = $pdo->prepare('INSERT INTO reservations (date, service, heure, couverts, nom, telephone, email, remarque, note_interne, statut, source, arrive, jeton, cree_le, cree_par, traite_par, langue) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    mt_srand((int)date('Ymd'));
    $n = 0;
    for ($d = -7; $d <= 21; $d++) {
        $date = date('Y-m-d', strtotime("$d days"));
        $dow = (int)date('N', strtotime($date));
        $services = [];
        if (in_array($dow, [2, 3, 4, 5, 7], true)) $services[] = ['midi', ['11:45', '12:00', '12:15', '12:30', '12:45', '13:00']];
        if (in_array($dow, [4, 5, 6, 7], true)) $services[] = ['soir', ['18:30', '19:00', '19:15', '19:30', '20:00', '20:15']];
        foreach ($services as [$service, $heures]) {
            $nb = $d < 0 ? mt_rand(5, 11) : ($d <= 7 ? mt_rand(3, 9) : mt_rand(0, 4));
            if ($dow === 6) $nb += 4; // samedi soir : soirée à thème
            for ($k = 0; $k < $nb; $k++) {
                $n++;
                $nom = $prenoms[mt_rand(0, count($prenoms) - 1)] . ' ' . $noms[mt_rand(0, count($noms) - 1)];
                $cv = [2, 2, 2, 3, 4, 4, 5, 6, 8, 12][mt_rand(0, 9)];
                $site = mt_rand(1, 100) <= 55;
                $statut = 'confirmee';
                if ($d >= 0 && $site && mt_rand(1, 100) <= 25) $statut = 'attente';
                if (mt_rand(1, 100) <= 4) $statut = 'annulee';
                if ($d >= 0 && $statut === 'attente' && mt_rand(1, 100) <= 10) $statut = 'refusee';
                $arrive = ($d < 0 && $statut === 'confirmee') ? 1 : 0;
                $tel = '06 ' . sprintf('%02d %02d %02d %02d', mt_rand(10, 99), mt_rand(10, 99), mt_rand(10, 99), mt_rand(10, 99));
                $mail = $site ? strtolower(preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $nom)[0]))) . '.' . strtolower(explode(' ', $nom)[1]) . '@exemple.fr' : '';
                $cree = date('Y-m-d H:i:s', strtotime($date . ' -' . mt_rand(1, 9) . ' days ' . mt_rand(9, 21) . ':' . sprintf('%02d', mt_rand(0, 59))));
                if ($cree > $now) $cree = date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 20) . ' hours'));
                $ins->execute([
                    $date, $service, $heures[mt_rand(0, count($heures) - 1)], $cv, $nom, $tel, $mail,
                    $remarques[mt_rand(0, count($remarques) - 1)], $cv >= 8 ? 'Grande table au fond' : '',
                    $statut, $site ? 'site' : 'telephone', $arrive, bin2hex(random_bytes(16)), $cree,
                    $site ? '' : ['Démo', 'Sophie'][mt_rand(0, 1)], $statut === 'attente' ? '' : 'Démo', mt_rand(1, 100) <= 15 ? 'de' : 'fr',
                ]);
            }
        }
    }
    // Un samedi complet en ligne (exemple de fermeture de la réservation en ligne)
    $samedi = date('Y-m-d', strtotime('next saturday +7 days'));
    $pdo->prepare('INSERT OR REPLACE INTO jours (date, service, ouvert, ferme_en_ligne, capacite, menu_impose, note) VALUES (?,?,?,?,?,?,?)')
        ->execute([$samedi, 'soir', 1, 1, null, null, 'Privatisation partielle, réservation par téléphone uniquement']);
}
