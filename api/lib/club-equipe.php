<?php
/**
 * Le 76 · Le Club : actions de l'appli équipe (/equipe/, onglet « Club »).
 * Appelé depuis api/equipe.php une fois la connexion vérifiée : mêmes comptes, même session.
 * Toute l'équipe : fiches, visites, cadeaux, offres reçues. Responsable : offres, réglages, exports.
 */
declare(strict_types=1);

require_once __DIR__ . '/club.php';

function club_membre_equipe(array $m, bool $detail = false): array
{
    $o = [
        'id' => (int) $m['id'], 'carte' => $m['carte'], 'prenom' => $m['prenom'], 'email' => $m['email'],
        'langue' => $m['langue'], 'offres' => (bool) $m['offres'], 'offres_le' => $m['offres_le'],
        'offres_retire_le' => $m['offres_retire_le'], 'origine' => $m['origine'], 'note' => $m['note'],
        'inscrit_le' => $m['inscrit_le'], 'inscrit_par' => $m['inscrit_par'],
        'compteurs' => club_compteurs($m),
    ];
    if ($detail) {
        $id = (int) $m['id'];
        $pdo = club_db();
        $st = $pdo->prepare('SELECT jour, quand, par FROM club_visites WHERE membre_id = ? ORDER BY jour DESC LIMIT 30');
        $st->execute([$id]);
        $o['visites'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT rang, libelle, quand, par FROM club_cadeaux WHERE membre_id = ? ORDER BY rang DESC');
        $st->execute([$id]);
        $o['cadeaux'] = $st->fetchAll();
        $st = $pdo->prepare('SELECT quand, accord, source, par FROM club_accords WHERE membre_id = ? ORDER BY id DESC LIMIT 20');
        $st->execute([$id]);
        $o['accords'] = $st->fetchAll();
        $o['offres_recues'] = club_offres_du_membre($id, true, 'fr');
        $o['lien_carte'] = club_lien_carte($m); // à scanner par le client pour avoir sa carte sur son téléphone
    }
    return $o;
}

function club_liste_membres(string $where, array $p, string $ordre, int $limite = 40): array
{
    $st = club_db()->prepare("SELECT m.*, (SELECT COUNT(*) FROM club_visites v WHERE v.membre_id = m.id) AS nb,
        (SELECT MAX(jour) FROM club_visites v WHERE v.membre_id = m.id) AS der FROM club_membres m WHERE $where ORDER BY $ordre LIMIT $limite");
    $st->execute($p);
    return array_map(fn($m) => club_membre_equipe($m), $st->fetchAll());
}

function club_equipe(string $action, array $q, array $moi): never
{
    langue('fr');
    $qui = (string) $moi['nom'];
    $id = (int) ($q['id'] ?? 0);

    switch ($action) {
        case 'club_accueil':
            club_sauvegarde_auto();
            $lot = club_envoyer_lot(10);
            $pdo = club_db();
            $auj = date('Y-m-d');
            $sem = date('Y-m-d', strtotime('-6 days'));
            $dispo = 0;
            $seuil = (int) club_reglages()['seuil'];
            foreach ($pdo->query('SELECT m.id, (SELECT COUNT(*) FROM club_visites v WHERE v.membre_id = m.id) AS nb, (SELECT COUNT(*) FROM club_cadeaux c WHERE c.membre_id = m.id) AS cad FROM club_membres m') as $x) {
                $dispo += max(0, intdiv((int) $x['nb'], $seuil) - (int) $x['cad']);
            }
            $st = $pdo->prepare('SELECT COUNT(*) FROM club_visites WHERE jour >= ?');
            $st->execute([$sem]);
            $semaine = (int) $st->fetchColumn();
            repondre(['ok' => true,
                'stats' => [
                    'membres' => (int) $pdo->query('SELECT COUNT(*) FROM club_membres')->fetchColumn(),
                    'abonnes' => (int) $pdo->query('SELECT COUNT(*) FROM club_membres WHERE offres = 1')->fetchColumn(),
                    'visites_semaine' => $semaine,
                    'cadeaux_a_offrir' => $dispo,
                ],
                'aujourdhui' => club_liste_membres('m.id IN (SELECT membre_id FROM club_visites WHERE jour = ?)', [$auj], 'm.prenom COLLATE NOCASE', 80),
                'recents' => club_liste_membres('m.id NOT IN (SELECT membre_id FROM club_visites WHERE jour = ?)', [$auj], 'COALESCE(der, substr(m.inscrit_le, 1, 10)) DESC', 12),
                'reglages' => array_intersect_key(club_reglages(), array_flip(['seuil', 'cadeau', 'envoi_reel', 'inscriptions'])),
                'envois' => $lot,
                'base' => defined('DEMO') ? '/demo' : '',
            ]);

        case 'club_chercher':
            $t = nettoyer_texte($q['q'] ?? '', 60);
            if ($c = club_carte_normale($t)) {
                $r = club_liste_membres('m.carte = ?', [$c], 'm.id', 1);
                if ($r) {
                    repondre(['ok' => true, 'resultats' => $r, 'carte_exacte' => true]);
                }
            }
            if (mb_strlen($t) < 2) {
                repondre(['ok' => true, 'resultats' => []]);
            }
            repondre(['ok' => true, 'resultats' => club_liste_membres('(m.prenom LIKE ? OR m.email LIKE ? OR m.carte LIKE ?)', ["%$t%", "%$t%", '%' . strtoupper($t) . '%'], 'm.prenom COLLATE NOCASE', 40)]);

        case 'club_fiche':
            if (!$id && ($c = club_carte_normale((string) ($q['carte'] ?? '')))) {
                $st = club_db()->prepare('SELECT id FROM club_membres WHERE carte = ?');
                $st->execute([$c]);
                $id = (int) ($st->fetchColumn() ?: erreur("Aucune carte « $c ». Vérifie le numéro.", 404));
            }
            repondre(['ok' => true, 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_inscrire':
            $x = club_inscrire((string) ($q['prenom'] ?? ''), (string) ($q['email'] ?? ''), ($q['langue'] ?? '') === 'de' ? 'de' : 'fr', !empty($q['offres']), 'equipe', $qui);
            if (!$x['existe']) {
                club_mail_bienvenue($x['membre']);
            }
            repondre(['ok' => true, 'existe' => $x['existe'], 'membre' => club_membre_equipe($x['membre'], true)]);

        case 'club_modifier':
            $m = club_membre($id);
            $prenom = nettoyer_texte($q['prenom'] ?? $m['prenom'], 40);
            $email = mb_strtolower(nettoyer_texte($q['email'] ?? $m['email'], 120));
            club_valider_prenom_email($prenom, $email);
            try {
                club_db()->prepare('UPDATE club_membres SET prenom = ?, email = ?, langue = ?, note = ? WHERE id = ?')
                    ->execute([$prenom, $email, ($q['langue'] ?? $m['langue']) === 'de' ? 'de' : 'fr', nettoyer_texte($q['note'] ?? $m['note'], 300), $id]);
            } catch (PDOException $e) {
                erreur('Cette adresse e-mail est déjà celle d’une autre carte.', 409);
            }
            club_journal('modification', $id, '', $qui);
            repondre(['ok' => true, 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_visite':
            $r = club_visite($id, $qui);
            repondre(['ok' => true, 'cadeau_gagne' => $r['cadeau_gagne'], 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_visite_annuler':
            club_visite_annuler($id, $qui);
            repondre(['ok' => true, 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_cadeau':
            club_cadeau($id, $qui);
            repondre(['ok' => true, 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_offre_utiliser':
            club_offre_utiliser((int) ($q['envoi_id'] ?? 0), $id, $qui);
            repondre(['ok' => true, 'membre' => club_membre_equipe(club_membre($id), true)]);

        case 'club_accord':
            // L'équipe peut noter un refus à tout moment ; un accord doit venir du client lui-même (case cochée devant lui)
            $m = club_accord(club_membre($id), !empty($q['accord']), 'equipe', $qui);
            repondre(['ok' => true, 'membre' => club_membre_equipe($m, true)]);
    }

    if (!$moi['admin']) {
        erreur('Réservé au responsable.', 403);
    }

    switch ($action) {
        case 'club_offres':
            $lot = club_envoyer_lot(10);
            repondre(['ok' => true, 'offres' => club_offres_liste(), 'envois' => $lot, 'reglages' => club_reglages()]);

        case 'club_offre_enregistrer':
            repondre(['ok' => true, 'offre' => club_offre_enregistrer($q, $qui)]);

        case 'club_offre_apercu':
            $o = club_offre($id);
            repondre(['ok' => true, 'offre' => $o + ['cible_texte' => club_cible_texte($o['cible'])], 'apercu' => club_apercu($o), 'envoi_reel' => club_reglages()['envoi_reel']]);

        case 'club_offre_valider':
            $r = club_offre_valider($id, (int) ($q['attendu'] ?? -1), $qui);
            $lot = $r['simulation'] ? null : club_envoyer_lot(10);
            repondre(['ok' => true, 'resultat' => $r, 'envois' => $lot]);

        case 'club_offre_supprimer':
            $st = club_db()->prepare("DELETE FROM club_offres WHERE id = ? AND statut = 'brouillon'");
            $st->execute([$id]);
            $st->rowCount() ? repondre(['ok' => true]) : erreur('Seul un brouillon peut être supprimé.', 409);

        case 'club_offre_dupliquer':
            repondre(['ok' => true, 'offre' => club_offre_dupliquer($id, $qui)]);

        case 'club_offre_envois':
            $liste = array_values(array_filter(club_offres_liste(), fn($o) => $o['id'] === $id));
            repondre(['ok' => true, 'offre' => $liste[0] ?? club_offre($id), 'envois' => club_offre_envois($id)]);

        case 'club_envoyer':
            repondre(['ok' => true, 'envois' => club_envoyer_lot(15)]);

        case 'club_relancer':
            repondre(['ok' => true, 'relances' => club_relancer($id, $qui), 'envois' => club_envoyer_lot(10)]);

        case 'club_test':
            repondre(['ok' => true, 'adresse' => club_envoyer_test($id, (string) ($q['langue'] ?? 'fr'), $qui), 'demo' => defined('DEMO')]);

        case 'club_reglages':
            repondre(['ok' => true, 'reglages' => club_reglages(), 'demo' => defined('DEMO'), 'smtp' => smtp_public(), 'sauvegardes' => club_sauvegardes()]);

        case 'club_reglages_ecrire':
            repondre(['ok' => true, 'reglages' => club_reglages_enregistrer(is_array($q['reglages'] ?? null) ? $q['reglages'] : [], $qui)]);

        case 'club_supprimer':
            club_supprimer($id, $qui);
            repondre(['ok' => true]);

        case 'club_journal':
            repondre(['ok' => true, 'journal' => club_db()->query('SELECT j.quand, j.qui, j.action, j.detail, m.prenom, m.carte FROM club_journal j LEFT JOIN club_membres m ON m.id = j.membre_id ORDER BY j.id DESC LIMIT 120')->fetchAll()]);

        case 'club_export':
            club_export_csv();
    }
    erreur('Action inconnue.');
}
