<?php
declare(strict_types=1);
/*
 * Version 2 : clôture mensuelle et conversion des anciennes rubriques.
 * Chaque changement est enregistré comme une saisie : sauvegarde du jour, journal des modifications,
 * trace dans l'historique de la caisse.
 */

/** Verrouille la caisse, applique $change(état) et enregistre le résultat. */
function apply_server_change(PDO $db, array $user, string $register, callable $change): array {
    $tables = register_tables($register);
    $db->beginTransaction();
    try {
        $row = $db->query('SELECT * FROM ' . $tables['state'] . ' WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!$row) fail('Caisse non initialisée.');
        $old = bind_register_state(valid_state(json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR)), $register);
        $next = $change($old);
        if ($next === null) { $db->commit(); return ['state' => $old, 'revision' => (int)$row['revision']]; }
        $next = bind_register_state(valid_state($next), $register);
        $revision = persist_transfer_state($db, $register, $row, $old, $next, $user['name']);
        $db->commit();
        return ['state' => $next, 'revision' => $revision];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/** Conversion unique des rubriques 1.x, faite à l'ouverture de la caisse par un compte autorisé à saisir. */
function migrate_register_if_needed(PDO $db, array $user, string $register, array $state): ?array {
    if ($user['role'] === 'reader') return null;
    [, $count] = migrate_legacy($state);
    if ($count === 0) return null;
    return apply_server_change($db, $user, $register, function (array $old) use ($user) {
        [$next, $count] = migrate_legacy($old);
        if ($count === 0) return null;
        $next['audit'][] = ['at' => gmdate('c'), 'action' => 'Conversion des rubriques',
            'detail' => $user['name'] . ' · ' . $count . ' opération(s) classée(s) avec les nouvelles rubriques (factures et marchandises → Achat fournisseur ; entretien → Petite dépense ; extras et autres → Autres sorties ; entrées → Recettes espèces ; remises en banque → Crédité). Montants et dates inchangés.',
            'reason' => 'Mise à jour version 2'];
        return $next;
    });
}

function close_month(PDO $db, array $user, string $register, array $p): array {
    admin($user);
    $month = $p['month'] ?? null;
    if (!valid_month($month)) fail('Mois invalide.');
    if ($month >= date('Y-m')) fail('On ne peut clôturer qu’un mois terminé.');
    return apply_server_change($db, $user, $register, function (array $old) use ($user, $month) {
        if ($month < substr($old['opening']['date'], 0, 7)) fail('Ce mois précède le point de départ de la caisse.');
        if (isset(closed_months($old)[$month])) fail('Ce mois est déjà clôturé.');
        $last = date('Y-m-t', strtotime($month . '-01'));
        $cents = balance_at($old, $last);
        $next = $old;
        $next['closures'][] = ['month' => $month, 'at' => gmdate('c'), 'by' => $user['name'], 'cents' => $cents, 'reopened' => false];
        $next['audit'][] = ['at' => gmdate('c'), 'action' => 'Clôture mensuelle',
            'detail' => $user['name'] . ' · ' . month_label($month) . ' clôturé · Solde de fin de mois : ' . eur($cents), 'reason' => ''];
        return $next;
    });
}

function reopen_month(PDO $db, array $user, string $register, array $p): array {
    admin($user);
    $month = $p['month'] ?? null;
    $reason = trim((string)($p['reason'] ?? ''));
    if (!valid_month($month)) fail('Mois invalide.');
    if ($reason === '' || !text_ok($reason)) fail('Un motif est obligatoire pour rouvrir un mois.');
    return apply_server_change($db, $user, $register, function (array $old) use ($user, $month, $reason) {
        $next = $old; $found = false;
        foreach ($next['closures'] as &$c) {
            if ($c['month'] === $month && !$c['reopened']) {
                $c['reopened'] = true; $c['reopenedAt'] = gmdate('c'); $c['reopenedBy'] = $user['name']; $c['reopenReason'] = $reason; $found = true;
            }
        }
        unset($c);
        if (!$found) fail('Ce mois n’est pas clôturé.');
        $next['audit'][] = ['at' => gmdate('c'), 'action' => 'Réouverture de mois',
            'detail' => $user['name'] . ' · ' . month_label($month) . ' rouvert · Motif : ' . $reason, 'reason' => $reason];
        return $next;
    });
}
