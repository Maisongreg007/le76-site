<?php
/**
 * Démo de ComptaCaisse : la vraie application tourne sur MySQL (comptacaisse.fr).
 * Ici, une base SQLite fictive, traduite à la volée, recréée chaque jour avec des données d'exemple.
 */
declare(strict_types=1);

final class DemoPDO extends PDO
{
    public static function sql(string $q): string
    {
        $q = str_replace([' ENGINE=InnoDB', 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INSERT IGNORE', ' FOR UPDATE', 'UTC_TIMESTAMP()', 'UTC_DATE()', "CONCAT('c',revision)", 'CAST(id AS CHAR)'],
            ['', 'INTEGER PRIMARY KEY AUTOINCREMENT', 'INSERT OR IGNORE', '', "datetime('now')", "date('now')", "('c'||revision)", 'CAST(id AS TEXT)'], $q);
        return preg_replace('/ON DUPLICATE KEY UPDATE restaurant=VALUES\(restaurant\),camping=VALUES\(camping\)/',
            'ON CONFLICT(user_id) DO UPDATE SET restaurant=excluded.restaurant,camping=excluded.camping', $q) ?? $q;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(self::sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return $fetchMode === null ? parent::query(self::sql($query)) : parent::query(self::sql($query), $fetchMode, ...$args); }
    public function exec(string $statement): int|false { return parent::exec(self::sql($statement)); }
}

function demo_dossier(): string
{
    foreach ([getenv('CAISSE_DEMO_DONNEES') ?: '', dirname(__DIR__, 3) . '/le76-caisse-demo', __DIR__ . '/donnees-demo'] as $c) {
        if ($c === '') continue;
        if (!is_dir($c)) @mkdir($c, 0750, true);
        if (is_dir($c) && is_writable($c)) {
            if (str_starts_with($c, __DIR__) && !is_file("$c/.htaccess")) file_put_contents("$c/.htaccess", "Require all denied\n");
            return $c;
        }
    }
    throw new RuntimeException('Dossier de démo indisponible.');
}

function demo_db(bool $forcer = false): PDO
{
    static $d = null;
    if ($d && !$forcer) return $d;
    $f = demo_dossier() . '/caisse-demo.sqlite';
    $jour = is_file($f . '.jour') ? trim((string) file_get_contents($f . '.jour')) : '';
    if ($forcer || $jour !== date('Y-m-d') || !is_file($f)) {
        $d = null;
        @unlink($f);
        $n = new DemoPDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        demo_remplir($n);
        file_put_contents($f . '.jour', date('Y-m-d'));
        $n = null;
    }
    $d = new DemoPDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $d->exec('PRAGMA busy_timeout = 5000');
    return $d;
}

function demo_remplir(PDO $d): void
{
    schema($d); history_storage($d); register_access_storage($d); camping_storage($d); history_storage($d, 'cc_camping_changes'); transfer_storage($d);
    $d->prepare('INSERT INTO cc_users(name,password,role) VALUES (?,?,?)')->execute(['demo', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'admin']);
    $d->prepare('INSERT INTO cc_register_access(user_id,restaurant,camping) VALUES (1,1,1)')->execute();
    mt_srand(76);
    $debut = date('Y-m-01', strtotime('first day of last month'));
    foreach (['restaurant' => [50000, [18000, 65000]], 'camping' => [20000, [6000, 30000]]] as $reg => [$fond, [$min, $max]]) {
        $e = [];
        $i = 0;
        $solde = $fond;
        $id = function () use (&$i, $reg) { return 'demo-' . $reg . '-' . (++$i); };
        for ($t = strtotime($debut); $t <= strtotime('today'); $t += 86400) {
            $j = date('Y-m-d', $t);
            $dow = (int) date('N', $t);
            if ($reg === 'restaurant' && $dow === 1) continue;
            $c = mt_rand($min, $max);
            $c -= $c % 50;
            $solde += $c;
            $e[] = ['id' => $id(), 'deleted' => false, 'date' => $j, 'kind' => 'in', 'category' => 'cash', 'cents' => $c, 'label' => 'Recette du jour', 'time' => '23:' . sprintf('%02d', mt_rand(0, 40)), 'incomeCategory' => 'sales'];
            if (mt_rand(1, 100) <= 60) {
                [$lib, $cat, $a, $b] = [['Marché : légumes', 'supplier', 2500, 9000], ['Glaçons et citrons', 'small', 600, 2500], ['Boucherie (facture)', 'supplier', 8000, 22000], ['Pourboire extra', 'other', 2000, 5000], ['Produits d’entretien', 'small', 900, 3500]][mt_rand(0, 4)];
                $s = intdiv(mt_rand($a, $b), 10) * 10;
                $solde -= $s;
                $e[] = ['id' => $id(), 'deleted' => false, 'date' => $j, 'kind' => 'out', 'category' => 'cash', 'cents' => $s, 'label' => $lib, 'time' => sprintf('%02d:%02d', mt_rand(9, 17), mt_rand(0, 59)), 'expenseCategory' => $cat];
            }
            $depot = intdiv(max(0, $solde - $fond), 10000) * 10000;
            if ($dow === 5 && $depot > 0) {
                $recent = $t > strtotime('-7 days');
                $solde -= $depot;
                $e[] = ['id' => $id(), 'deleted' => false, 'date' => $j, 'kind' => 'out', 'category' => 'bank', 'cents' => $depot, 'label' => 'Dépôt bancaire', 'bankStatus' => $recent ? 'deposited' : 'credited', 'bankName' => 'Banque (démo)'];
            }
        }
        $state = ['registerId' => $reg, 'version' => 1, 'opening' => ['date' => $debut, 'cents' => $fond], 'entries' => $e, 'checks' => [], 'exchanges' => [], 'closures' => [],
            'audit' => [['at' => gmdate('c'), 'action' => 'Saisie', 'detail' => 'Données de démonstration', 'reason' => '']], 'lastBackupAt' => null, 'updatedAt' => gmdate('c')];
        $state = valid_state($state);
        $t = $reg === 'restaurant' ? 'cc_state' : 'cc_camping_state';
        $d->exec("DELETE FROM $t");
        $d->prepare("INSERT INTO $t(id,revision,data) VALUES (1,0,?)")->execute([json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }
}
