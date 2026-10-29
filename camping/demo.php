<?php
/**
 * Démo de CAMPING OS 76 (le76.fr/demo/camping) : base séparée et fictive, renouvelée chaque jour.
 * Responsable connecté d'office ; livreurs : code 1234. Aucun paiement réel.
 */
declare(strict_types=1);

const C_DEMO_CODE = '1234';

function demo_preparer(bool $forcer = false): void
{
    $pdo = c_db();
    $jour = $pdo->query("SELECT valeur FROM kv WHERE cle = 'demo_jour'")->fetchColumn();
    if (!$forcer && $jour === date('Y-m-d')) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $pdo->exec('DELETE FROM docs');
        c_sessions()->exec('DELETE FROM sessions');
        $menu = C_DEFAULT_MENU;
        $menu[] = ['id' => 'glace', 'name' => 'Glace 2 boules', 'description' => 'Vanille, chocolat ou fraise', 'price' => 400, 'station' => 'bar', 'active' => true,
            'tr' => ['de' => ['name' => 'Eis, 2 Kugeln', 'description' => 'Vanille, Schokolade oder Erdbeere'], 'en' => ['name' => 'Ice cream, 2 scoops', 'description' => 'Vanilla, chocolate or strawberry'], 'nl' => ['name' => 'IJs, 2 bolletjes', 'description' => 'Vanille, chocolade of aardbei']]];
        wr('config/menu', ['items' => $menu, 'confirmed' => true]);
        $spots = [];
        foreach ([[101, 110, 'Allée des Pins'], [111, 120, 'Allée des Chênes'], [121, 130, 'Allée du Lac']] as [$a, $b, $allee]) {
            for ($i = $a; $i <= $b; $i++) {
                $spots[] = [(string) $i, $allee];
            }
        }
        wr('config/settings', ['open' => true, 'spots' => $spots]);
        wr('config/staff', [
            'managers' => [['id' => 'sophie', 'name' => 'Sophie'], ['id' => 'marc', 'name' => 'Marc']],
            'drivers' => [['id' => 'lea', 'name' => 'Léa'], ['id' => 'noa', 'name' => 'Noa'], ['id' => 'tom', 'name' => 'Tom']],
        ]);
        $t = fn(int $min) => (new DateTimeImmutable("-$min minutes", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        wr('drivers/lea', ['id' => 'lea', 'state' => 'available', 'since' => $t(150)]);
        wr('drivers/noa', ['id' => 'noa', 'state' => 'busy', 'since' => $t(140)]);
        wr('drivers/tom', ['id' => 'tom', 'state' => 'absent', 'since' => null]);
        $m = [];
        foreach ($menu as $x) {
            $m[$x['id']] = $x;
        }
        $cmd = function (string $id, string $spot, string $name, string $mode, array $qte, int $min, string $etat, ?string $trip = null, ?array $paid = null) use ($m, $t) {
            $items = [];
            $stations = [];
            $total = 0;
            foreach ($qte as $k => $q) {
                $x = $m[$k];
                $items[] = ['id' => $k, 'name' => $x['name'], 'description' => $x['description'], 'price' => $x['price'], 'station' => $x['station'], 'quantity' => $q, 'tr' => $x['tr'] ?? []];
                $stations[$x['station']] = $etat !== 'preparing';
                $total += $x['price'] * $q;
            }
            $ev = ['e0' => ['t' => $t($min), 'a' => 'Commande reçue', 'w' => 'client']];
            if ($etat !== 'preparing') {
                $ev['ready'] = ['t' => $t($min - 8), 'a' => 'Commande prête', 'w' => 'sophie'];
            }
            if ($trip) {
                $ev['e2'] = ['t' => $t($min - 10), 'a' => 'Tournée attribuée', 'w' => 'sophie'];
            }
            if ($paid) {
                $paid['amount'] = $total;
                $ev['e3'] = ['t' => $paid['at'], 'a' => ($mode === 'pickup' ? 'Commande retirée' : 'Commande livrée') . ' · payée en ' . C_METHODS[$paid['method']], 'w' => $paid['confirmedBy']];
            }
            return ['id' => $id, 'spot' => $spot, 'name' => $name, 'mode' => $mode, 'items' => $items, 'total' => $total, 'status' => $etat,
                'stations' => $stations, 'trip' => $trip, 'created' => $t($min), 'events' => $ev, 'paid' => $paid];
        };
        // En cuisine
        $a = $cmd('D3E0A001', '104', 'Julia', 'delivery', ['reine' => 1, 'limonade' => 2], 6, 'preparing');
        $a['stations']['pizza'] = true;
        $a['events']['e1'] = ['t' => $t(2), 'a' => 'Poste pizza prêt', 'w' => 'pizza'];
        wr('orders/D3E0A001', $a);
        wr('orders/D3E0A002', $cmd('D3E0A002', '117', 'Hans', 'delivery', ['burger' => 2, 'frites' => 1, 'eau' => 2], 3, 'preparing'));
        // Prêtes
        wr('orders/D3E0A003', $cmd('D3E0A003', '122', 'Emma', 'delivery', ['margherita' => 1, 'glace' => 1], 18, 'ready'));
        wr('orders/D3E0A004', $cmd('D3E0A004', '126', 'Pieter', 'delivery', ['salade' => 1, 'eau' => 1], 16, 'ready'));
        wr('orders/D3E0A005', $cmd('D3E0A005', '108', 'Lucas', 'pickup', ['frites' => 2, 'limonade' => 2], 14, 'ready'));
        // Tournée en cours de Noa
        wr('trips/D3E07001', ['id' => 'D3E07001', 'driver' => 'noa', 'state' => 'delivering', 'manager' => 'sophie', 'created' => $t(25), 'departed' => $t(20)]);
        wr('orders/D3E0A006', $cmd('D3E0A006', '125', 'Sanne', 'delivery', ['margherita' => 2], 40, 'delivered', 'D3E07001', ['method' => 'cash', 'by' => 'noa', 'at' => $t(12), 'confirmedBy' => 'noa']));
        wr('orders/D3E0A007', $cmd('D3E0A007', '128', 'Peter', 'delivery', ['burger' => 1, 'eau' => 1], 38, 'delivering', 'D3E07001'));
        // Tournée terminée de Léa
        wr('trips/D3E07002', ['id' => 'D3E07002', 'driver' => 'lea', 'state' => 'done', 'manager' => 'marc', 'created' => $t(95), 'departed' => $t(90), 'returned' => $t(70)]);
        wr('orders/D3E0A008', $cmd('D3E0A008', '102', 'Camille', 'delivery', ['reine' => 1, 'frites' => 1], 110, 'delivered', 'D3E07002', ['method' => 'cash', 'by' => 'lea', 'at' => $t(85), 'confirmedBy' => 'lea']));
        wr('orders/D3E0A009', $cmd('D3E0A009', '115', 'Jonas', 'delivery', ['burger' => 2, 'limonade' => 2], 105, 'delivered', 'D3E07002', ['method' => 'card', 'by' => 'lea', 'at' => $t(75), 'confirmedBy' => 'lea']));
        // Retrait déjà fait
        wr('orders/D3E0A010', $cmd('D3E0A010', '111', 'Marie', 'pickup', ['salade' => 2], 80, 'collected', null, ['method' => 'card', 'by' => 'restaurant', 'at' => $t(60), 'confirmedBy' => 'marc']));
        $pdo->prepare("INSERT INTO kv (cle, valeur) VALUES ('code_livreurs', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
            ->execute([password_hash(C_DEMO_CODE, PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO kv (cle, valeur) VALUES ('demo_jour', ?) ON CONFLICT(cle) DO UPDATE SET valeur = excluded.valeur")
            ->execute([date('Y-m-d')]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}
