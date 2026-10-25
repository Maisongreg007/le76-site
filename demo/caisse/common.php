<?php
declare(strict_types=1);
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Paris');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
$cc_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if (!$cc_https && !in_array(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0], ['localhost', '127.0.0.1'], true)) {
    http_response_code(400); exit('Ouvrez cette adresse en HTTPS avant de continuer.');
}
// DÉMO (le76.fr/demo/caisse) : base SQLite fictive, connexion automatique, renouvelée chaque jour.
session_name('comptacaisse_demo');
session_set_cookie_params(['secure'=>$cc_https,'httponly'=>true,'samesite'=>'Strict','path'=>'/demo/caisse/']);
ini_set('session.use_strict_mode', '1');
session_start();
function fail(string $message, int $code=400): never { global $d;if(isset($d)&&$d instanceof PDO&&$d->inTransaction())$d->rollBack(); http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$message]); exit; }
function reply(array $value): never { header('Content-Type: application/json; charset=utf-8'); echo json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
function connection(array $c): PDO { return new PDO('mysql:host='.$c['host'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]); }
function private_config(): string { return dirname(__DIR__).'/comptacaisse-private/config.php'; }
function db(): PDO { require_once __DIR__.'/demo_db.php'; return demo_db(); }
function body(): array { if((int)($_SERVER['CONTENT_LENGTH']??0)>20000000) fail('Données trop volumineuses.'); $raw=file_get_contents('php://input'); if(strlen($raw)>20000000) fail('Données trop volumineuses.'); $p=json_decode($raw,true,512,JSON_THROW_ON_ERROR); if(!is_array($p))fail('Requête invalide.'); return $p; }
function csrf(): string { return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
function check_csrf(): void { if(!hash_equals(csrf(),$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Session expirée. Reconnectez-vous.',403); }
function account(PDO $d): array { if(empty($_SESSION['uid'])||($_SESSION['expires']??0)<time()){$_SESSION=['uid'=>1,'sv'=>(int)$d->query('SELECT session_version FROM cc_users WHERE id=1')->fetchColumn(),'expires'=>time()+43200,'csrf'=>$_SESSION['csrf']??bin2hex(random_bytes(32))];} if(empty($_SESSION['uid'])||($_SESSION['expires']??0)<time())fail('Connectez-vous à la caisse.',401); $q=$d->prepare('SELECT id,name,role,active,session_version FROM cc_users WHERE id=?');$q->execute([$_SESSION['uid']]);$u=$q->fetch(PDO::FETCH_ASSOC);if(!$u||!$u['active']||(int)$u['session_version']!==($_SESSION['sv']??-1))fail('Connectez-vous à la caisse.',401);return $u; }
function admin(array $u): void { if($u['role']!=='admin')fail('Action réservée au responsable.',403); }
function public_user(array $u): array {return ['id'=>$u['id'],'name'=>$u['name'],'role'=>$u['role']];}
function valid_date(mixed $s): bool {if(!is_string($s)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$s))return false;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);return $d&&$d->format('Y-m-d')===$s&&$s>='1900-01-01'&&$s<='9999-12-31';}
function amount(mixed $v, bool $positive=false): bool {return is_int($v)&&abs($v)<=99999999999&&(!$positive||$v>0);}
function text_ok(mixed $v,int $length=200): bool {return is_string($v)&&mb_strlen($v)<=$length;}
function notes_total(mixed $v): int {if(!is_array($v)||count($v)!==6)fail('Détail des billets invalide.');$total=0;foreach([200,100,50,20,10,5]as$n){if(!isset($v[$n])||!is_int($v[$n])||$v[$n]<0||$v[$n]>999999)fail('Nombre de billets invalide.');$total+=$v[$n]*$n*100;}return $total;}
/* ---------- Rubriques et statuts (version 2) ---------- */
const INCOME_CATEGORIES=['sales'=>'Recettes espèces du restaurant','float'=>'Apport de fonds de caisse','refund'=>'Remboursement reçu','other'=>'Autres entrées'];
const EXPENSE_CATEGORIES=['supplier'=>'Achat fournisseur','small'=>'Petite dépense','refund'=>'Remboursement','withdrawal'=>'Prélèvement autorisé','other'=>'Autres sorties'];
/** Anciennes rubriques (versions 1.x), encore acceptées dans les archives et converties à l'ouverture. */
const LEGACY_EXPENSES=['invoices'=>'supplier','goods'=>'supplier','extras'=>'other','maintenance'=>'small'];
const BANK_STATUSES=['prepared'=>'Préparé','deposited'=>'Déposé','credited'=>'Crédité','cancelled'=>'Annulé'];

function valid_time(mixed $t): bool { return is_string($t)&&preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D',$t)===1; }
function valid_month(mixed $m): bool { return is_string($m)&&preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$m)===1; }

/** Effet d'une opération sur les espèces présentes dans la caisse (en centimes). */
function entry_effect(array $e): int {
    if(!empty($e['deleted']))return 0;
    if($e['kind']==='in')return $e['cents'];
    if(($e['category']??'')==='bank'&&in_array($e['bankStatus']??'deposited',['prepared','cancelled'],true))return 0;
    return -$e['cents'];
}

function valid_entry(array $e,string $register): void {
    if(!in_array($e['kind']??'',['in','out'],true)||!amount($e['cents']??null,true)||!text_ok($e['label']??null))fail('Opération invalide.');
    if(isset($e['time'])&&!valid_time($e['time']))fail('Heure invalide.');
    if(isset($e['ref'])&&!text_ok($e['ref'],200))fail('Référence trop longue.');
    $category=$e['category']??'cash';
    if(isset($e['category'])&&!in_array($e['category'],['cash','bank','transfer'],true))fail('Remise invalide.');
    if($category==='bank'&&$e['kind']!=='out')fail('Remise invalide.');
    if($category==='transfer'){
        if(!is_string($e['transferId']??null)||!preg_match('/^[a-zA-Z0-9-]{10,80}$/D',$e['transferId'])||!in_array($e['transferPeer']??'',['restaurant','camping'],true)||$e['transferPeer']===$register||$e['id']!==$e['transferId'].($e['kind']==='out'?'-out':'-in')||!text_ok($e['transferNote']??''))fail('Transfert invalide.');
    }elseif(isset($e['transferId'])||isset($e['transferPeer'])||isset($e['transferNote']))fail('Métadonnées de transfert invalides.');
    if(isset($e['expenseCategory'])&&$e['expenseCategory']!==null){
        if($e['kind']!=='out'||$category!=='cash'||(!isset(EXPENSE_CATEGORIES[$e['expenseCategory']])&&!isset(LEGACY_EXPENSES[$e['expenseCategory']])))fail('Rubrique invalide.');
    }
    if(isset($e['incomeCategory'])&&($e['kind']!=='in'||$category!=='cash'||!isset(INCOME_CATEGORIES[$e['incomeCategory']])))fail('Catégorie d’entrée invalide.');
    if(isset($e['bankStatus'])&&($category!=='bank'||!isset(BANK_STATUSES[$e['bankStatus']])))fail('Statut de dépôt invalide.');
    if(isset($e['bankName'])&&($category!=='bank'||!text_ok($e['bankName'],100)))fail('Nom de banque invalide.');
    if(isset($e['banknotes'])&&($e['kind']!=='in'||notes_total($e['banknotes'])!==$e['cents']))fail('Total des billets invalide.');
}

function valid_check(array $e): void {
    if(!amount($e['expected']??null)||!amount($e['counted']??null)||$e['counted']<0||!text_ok($e['note']??null)||!is_string($e['at']??null)||strtotime($e['at'])===false)fail('Contrôle invalide.');
    if((isset($e['coins'])||isset($e['otherCoinsCents']))&&!isset($e['banknotes']))fail('Détail du comptage invalide.');
    if(!amount($e['otherCoinsCents']??0)||($e['otherCoinsCents']??0)<0)fail('Autres pièces invalides.');
    if(isset($e['banknotes'])&&notes_total($e['banknotes'])+counted_coin_total($e['coins']??[])+($e['otherCoinsCents']??0)!==$e['counted'])fail('Total du comptage invalide.');
}

function valid_closures(mixed $list): array {
    if(!is_array($list)||!array_is_list($list)||count($list)>1000)fail('Clôtures invalides.');
    foreach($list as$c){
        if(!is_array($c)||!valid_month($c['month']??null)||!is_string($c['at']??null)||strtotime($c['at'])===false||!text_ok($c['by']??null,80)||!amount($c['cents']??null)||!is_bool($c['reopened']??null))fail('Clôture invalide.');
        if($c['reopened']&&(!is_string($c['reopenedAt']??null)||strtotime($c['reopenedAt'])===false||!text_ok($c['reopenReason']??null)||!text_ok($c['reopenedBy']??null,80)))fail('Réouverture invalide.');
    }
    return $list;
}

function valid_state(array $s): array {
    if(($s['version']??null)!==1||!valid_date($s['opening']['date']??null)||!amount($s['opening']['cents']??null)||!is_array($s['entries']??null)||!is_array($s['audit']??null))fail('Format de caisse invalide.');
    if(isset($s['registerId'])&&(!is_string($s['registerId'])||!in_array($s['registerId'],['restaurant','camping'],true)))fail('Caisse de la sauvegarde invalide.');
    $register=$s['registerId']??'restaurant';
    $s['exchanges']??=[];$s['checks']??=[];$s['closures']??=[];$s['lastBackupAt']??=null;$s['updatedAt']??=null;
    if(!is_array($s['checks'])||!array_is_list($s['entries'])||!array_is_list($s['checks'])||!array_is_list($s['audit'])||count($s['entries'])>50000||count($s['checks'])>50000||count($s['audit'])>100000)fail('Caisse trop volumineuse ou invalide.');
    foreach(['entries','checks']as$list){
        $ids=[];
        foreach($s[$list]as$e){
            if(!is_array($e)||!is_bool($e['deleted']??null)||!text_ok($e['id']??null,100)||empty($e['id'])||isset($ids[$e['id']])||!valid_date($e['date']??null)||$e['date']<$s['opening']['date'])fail('Opération ou contrôle invalide.');
            $ids[$e['id']]=true;
            if($list==='entries')valid_entry($e,$register);else valid_check($e);
        }
    }
    if(!is_array($s['exchanges'])||!array_is_list($s['exchanges'])||count($s['exchanges'])>50000)fail('Liste des échanges invalide.');
    $exchangeIds=[];
    foreach($s['exchanges']as&$e){
        if(is_array($e)&&!array_key_exists('deleted',$e))$e['deleted']=false;
        if(!is_array($e)||!is_bool($e['deleted']??null)||!text_ok($e['id']??null,100)||empty($e['id'])||isset($exchangeIds[$e['id']])||!valid_date($e['date']??null)||$e['date']<$s['opening']['date']||!is_string($e['at']??null)||strtotime($e['at'])===false||!text_ok($e['note']??null)||!amount($e['cents']??null,true)||exchange_total($e['given']??null)!==$e['cents']||exchange_total($e['received']??null)!==$e['cents'])fail('Échange invalide : les totaux doivent être égaux et positifs.');
        $exchangeIds[$e['id']]=true;
    }
    unset($e);
    $s['closures']=valid_closures($s['closures']);
    // Le navigateur range les clés numériques (billets) dans l'ordre croissant : on normalise pour comparer sans faux écart.
    foreach($s['entries']as&$e)if(isset($e['banknotes'])&&is_array($e['banknotes']))ksort($e['banknotes']);
    unset($e);
    foreach($s['checks']as&$e){if(isset($e['banknotes'])&&is_array($e['banknotes']))ksort($e['banknotes']);if(isset($e['coins'])&&is_array($e['coins']))ksort($e['coins']);}
    unset($e);
    foreach($s['exchanges']as&$e){ksort($e['given']);ksort($e['received']);}
    unset($e);
    foreach($s['audit']as$a)if(!is_array($a)||!is_string($a['at']??null)||strtotime($a['at'])===false||!text_ok($a['action']??null,200)||!text_ok($a['detail']??null,10000000))fail('Historique invalide.');
    foreach(['lastBackupAt','updatedAt']as$k)if($s[$k]!==null&&(!is_string($s[$k])||strtotime($s[$k])===false))fail('Horodatage invalide.');
    return $s;
}
function schema(PDO $d): void {
    foreach([
    'CREATE TABLE IF NOT EXISTS cc_users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE,password VARCHAR(255) NOT NULL,role VARCHAR(10) NOT NULL,active TINYINT NOT NULL DEFAULT 1,session_version INT NOT NULL DEFAULT 1) ENGINE=InnoDB',
    'CREATE TABLE IF NOT EXISTS cc_state (id INT PRIMARY KEY,revision BIGINT NOT NULL,data LONGTEXT NOT NULL) ENGINE=InnoDB',
    'CREATE TABLE IF NOT EXISTS cc_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,revision BIGINT NOT NULL,actor VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL,data LONGTEXT NOT NULL) ENGINE=InnoDB',
    'CREATE TABLE IF NOT EXISTS cc_requests (request_id VARCHAR(80) PRIMARY KEY,user_id BIGINT NOT NULL,revision BIGINT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB',
    'CREATE TABLE IF NOT EXISTS cc_attempts (fingerprint CHAR(64) PRIMARY KEY,attempts INT NOT NULL,started BIGINT NOT NULL) ENGINE=InnoDB',
    'CREATE TABLE IF NOT EXISTS cc_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,actor VARCHAR(80) NOT NULL,event VARCHAR(200) NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB'
    ]as$sql)$d->exec($sql);
}

function exchange_total(mixed $items): int {
    $units=["b500"=> 50000, "b200"=> 20000, "b100"=> 10000, "b50"=> 5000, "b20"=> 2000, "b10"=> 1000, "b5"=> 500, "c200"=> 200, "c100"=> 100, "c50"=> 50, "c20"=> 20, "c10"=> 10, "c5"=> 5, "c2"=> 2, "c1"=> 1, "r200"=> 5000, "r100"=> 2500, "r50"=> 2000, "r20"=> 800, "r10"=> 400, "r5"=> 250, "r2"=> 100, "r1"=> 50];
    if(!is_array($items))fail('Détail de monnaie invalide.');
    $total=0;foreach($items as$key=>$quantity){if(!array_key_exists($key,$units)||!is_int($quantity)||$quantity<0||$quantity>999999)fail('Quantité de monnaie invalide.');$total+=$units[$key]*$quantity;}
    if($total>99999999999)fail('Total de monnaie trop élevé.');return $total;
}

require_once __DIR__.'/integrity.php';
require_once __DIR__.'/registers.php';

require_once __DIR__.'/transfers.php';
require_once __DIR__.'/closures.php';
