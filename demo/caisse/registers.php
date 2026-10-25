<?php
declare(strict_types=1);
const REGISTERS=['restaurant'=>'Le 76','camping'=>'Camping'];
function register_tables(string $register):array {
    if(!array_key_exists($register,REGISTERS))fail('Caisse inconnue.');
    $prefix=$register==='restaurant'?'cc_':'cc_camping_';
    return ['state'=>$prefix.'state','versions'=>$prefix.'versions','changes'=>$prefix.'changes','requests'=>$prefix.'requests'];
}
function requested_register(array $payload):string {
    $query=$_GET['register']??'restaurant';$body=$payload['register']??$query;
    if(!is_string($query)||!is_string($body)||$body!==$query||!array_key_exists($query,REGISTERS))fail('Sélection de caisse invalide.');return $query;
}
function bind_register_state(array $state,string $register):array {
    register_tables($register);
    $source=$state['registerId']??'restaurant';
    if($source!==$register)fail('Cette sauvegarde ou cette saisie appartient à une autre caisse.');
    $state['registerId']=$register;return $state;
}
function empty_register_state(string $register):array {
    register_tables($register);
    return ['version'=>1,'registerId'=>$register,'opening'=>['date'=>date('Y-m-d'),'cents'=>0],'entries'=>[],'checks'=>[],'exchanges'=>[],'audit'=>[],'lastBackupAt'=>null,'updatedAt'=>null];
}
function register_access_storage(PDO $db):void {
    // Additive migration only. Existing restaurant tables and data are not changed.
    $db->exec('CREATE TABLE IF NOT EXISTS cc_register_access (user_id BIGINT PRIMARY KEY,restaurant TINYINT NOT NULL DEFAULT 1,camping TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
}
function allowed_registers(PDO $db,array $user):array {
    if($user['role']==='admin')return array_keys(REGISTERS);
    $q=$db->prepare('SELECT restaurant,camping FROM cc_register_access WHERE user_id=?');$q->execute([$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)return ['restaurant'];
    return array_values(array_filter(array_keys(REGISTERS),fn($id)=>(int)$row[$id]===1));
}
function assert_register_access(PDO $db,array $user,string $register):void {
    if(!in_array($register,allowed_registers($db,$user),true))fail('Ce compte n’a pas accès à cette caisse.',403);
}
function camping_storage(PDO $db):void {
    foreach([
        'CREATE TABLE IF NOT EXISTS cc_camping_state (id INT PRIMARY KEY,revision BIGINT NOT NULL,data LONGTEXT NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS cc_camping_versions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,revision BIGINT NOT NULL,actor VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL,data LONGTEXT NOT NULL) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS cc_camping_requests (request_id VARCHAR(80) PRIMARY KEY,user_id BIGINT NOT NULL,revision BIGINT NOT NULL,created_at DATETIME NOT NULL) ENGINE=InnoDB'
    ]as$sql)$db->exec($sql);
    history_storage($db,'cc_camping_changes');
    $db->prepare('INSERT IGNORE INTO cc_camping_state(id,revision,data) VALUES (1,0,?)')->execute([json_encode(empty_register_state('camping'),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
}
function checked_access_list(mixed $values):array {
    if(!is_array($values)||!array_is_list($values)||count($values)<1||count($values)>2)fail('Choisissez au moins une caisse.');
    foreach($values as$id)if(!is_string($id)||!array_key_exists($id,REGISTERS))fail('Droits de caisse invalides.');
    if(count(array_unique($values))!==count($values))fail('Droits de caisse invalides.');return $values;
}
function save_register_access(PDO $db,int $userId,array $values):void {
    $values=checked_access_list($values);
    $db->prepare('INSERT INTO cc_register_access(user_id,restaurant,camping) VALUES (?,?,?) ON DUPLICATE KEY UPDATE restaurant=VALUES(restaurant),camping=VALUES(camping)')->execute([$userId,(int)in_array('restaurant',$values,true),(int)in_array('camping',$values,true)]);
}
