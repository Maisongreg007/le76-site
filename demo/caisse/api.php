<?php
require __DIR__.'/common.php';
if(($_GET['action']??'')==='demo-reset'){require_once __DIR__.'/demo_db.php';demo_db(true);$_SESSION=[];header('Location: ./');exit;}
try {
    $d=db();$action=$_GET['action']??'state';$post=$_SERVER['REQUEST_METHOD']==='POST';$p=$post?body():[];
    if($action==='login'){
        if(!$post)fail('Méthode invalide.',405);
        $name=trim((string)($p['name']??''));$password=(string)($p['password']??'');if(strlen($password)>256||!text_ok($name,80))fail('Identifiants invalides.');
        $d->prepare('DELETE FROM cc_attempts WHERE started<?')->execute([time()-86400]);
        $fp=hash('sha256',($_SERVER['REMOTE_ADDR']??'').':'.strtolower($name));
        $d->beginTransaction();$d->prepare('INSERT IGNORE INTO cc_attempts VALUES (?,0,?)')->execute([$fp,time()]);$q=$d->prepare('SELECT * FROM cc_attempts WHERE fingerprint=? FOR UPDATE');$q->execute([$fp]);$a=$q->fetch(PDO::FETCH_ASSOC);
        if((int)$a['started']<time()-900){$a['attempts']=0;$a['started']=time();}
        if((int)$a['attempts']>=10){$d->commit();fail('Trop de tentatives. Réessayez dans 15 minutes.',429);}
        $d->prepare('UPDATE cc_attempts SET attempts=?,started=? WHERE fingerprint=?')->execute([(int)$a['attempts']+1,$a['started'],$fp]);$d->commit();
        $q=$d->prepare('SELECT * FROM cc_users WHERE name=?');$q->execute([$name]);$u=$q->fetch(PDO::FETCH_ASSOC);
        $hash=$u['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        if(!password_verify($password,$hash)||!$u||!$u['active'])fail('Identifiant ou mot de passe incorrect.',401);
        $d->prepare('DELETE FROM cc_attempts WHERE fingerprint=?')->execute([$fp]);session_regenerate_id(true);$_SESSION=['uid'=>(int)$u['id'],'sv'=>(int)$u['session_version'],'expires'=>time()+43200,'csrf'=>bin2hex(random_bytes(32))];register_access_storage($d);reply(['ok'=>true,'registers'=>allowed_registers($d,$u)]);
    }
    $u=account($d);if($post)check_csrf();
    register_access_storage($d);$register=requested_register($p);$tables=register_tables($register);
    if($action==='session')reply(['registers'=>allowed_registers($d,$u),'user'=>public_user($u),'csrf'=>csrf()]);
    if(in_array($action,['transfer-context','transfers','transfer','cancel-transfer'],true)){
        admin($u);assert_register_access($d,$u,'restaurant');assert_register_access($d,$u,'camping');
        if(in_array($action,['transfer','cancel-transfer'],true)&&!$post)fail('Méthode invalide.',405);
        if(in_array($action,['transfer-context','transfers'],true)&&$post)fail('Méthode invalide.',405);
        history_storage($d);camping_storage($d);transfer_storage($d);
        if($action==='transfer-context')reply(transfer_context($d,$u,$register,(string)($_GET['date']??date('Y-m-d'))));
        if($action==='transfers')reply(['register'=>$register,'transfers'=>$d->query('SELECT id,source,destination,movement_date,cents,note,actor_name,created_at,cancelled,cancel_reason FROM cc_transfers ORDER BY created_at DESC,id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC)]);
        $result=execute_transfer($d,$u,$p,$action==='cancel-transfer');reply(['register'=>$register,'state'=>$result['states'][$register],'revision'=>$result['revisions'][$register],'transfer'=>$result['transfer'],'replayed'=>$result['replayed']]);
    }
    if(in_array($action,['close-month','reopen-month'],true)){
        if(!$post)fail('Méthode invalide.',405);assert_register_access($d,$u,$register);
        if($register==='camping')camping_storage($d);history_storage($d,$tables['changes']);
        $result=$action==='close-month'?close_month($d,$u,$register,$p):reopen_month($d,$u,$register,$p);
        reply(['register'=>$register,'state'=>$result['state'],'revision'=>$result['revision']]);
    }
    if(in_array($action,['state','save','archive','archives'],true)){
        assert_register_access($d,$u,$register);
        if($register==='camping')camping_storage($d);
        else if($action!=='state')history_storage($d,$tables['changes']);
    }
    if(in_array($action,['save','logout','password','users'],true)&&!$post&&$action!=='users')fail('Méthode invalide.',405);
    if($action==='logout'){$_SESSION=[];session_destroy();reply(['ok'=>true]);}
    if($action==='password'){
        if(!text_ok($p['password']??null,256)||strlen($p['password'])<12)fail('Choisissez un mot de passe d’au moins 12 caractères.');
        $q=$d->prepare('SELECT password FROM cc_users WHERE id=?');$q->execute([$u['id']]);if(!password_verify((string)($p['current']??''),$q->fetchColumn()))fail('Mot de passe actuel incorrect.');
        $d->prepare('UPDATE cc_users SET password=?,session_version=session_version+1 WHERE id=?')->execute([password_hash($p['password'],PASSWORD_DEFAULT),$u['id']]);$_SESSION['sv']++;reply(['ok'=>true]);
    }
    if($action==='users'){
        admin($u);
        if($post){$name=trim((string)($p['name']??''));if(!preg_match('/^[a-zA-Z0-9_.-]{2,60}$/D',$name))fail('Identifiant : 2 à 60 lettres, chiffres, points ou tirets.');
            if($p['mode']==='access'){$q=$d->prepare('SELECT id,role FROM cc_users WHERE name=?');$q->execute([$name]);$target=$q->fetch(PDO::FETCH_ASSOC);if(!$target||$target['role']==='admin')fail('Compte d’équipe introuvable.');save_register_access($d,(int)$target['id'],checked_access_list($p['registers']??null));}
            elseif($p['mode']==='create'){if(!in_array($p['role']??'', ['staff','reader'],true)||!text_ok($p['password']??null,256)||strlen($p['password'])<12)fail('Rôle ou mot de passe invalide (12 caractères minimum).');$access=checked_access_list($p['registers']??['restaurant']);$d->beginTransaction();$d->prepare('INSERT INTO cc_users(name,password,role) VALUES (?,?,?)')->execute([$name,password_hash($p['password'],PASSWORD_DEFAULT),$p['role']]);save_register_access($d,(int)$d->lastInsertId(),$access);$d->commit();}
            elseif(in_array($p['mode'],['disable','reset'],true)){$q=$d->prepare('SELECT * FROM cc_users WHERE name=?');$q->execute([$name]);$target=$q->fetch(PDO::FETCH_ASSOC);if(!$target||$target['role']==='admin')fail('Compte d’équipe introuvable.');if($p['mode']==='disable')$d->prepare('UPDATE cc_users SET active=0,session_version=session_version+1 WHERE id=?')->execute([$target['id']]);else{if(!text_ok($p['password']??null,256)||strlen($p['password'])<12)fail('12 caractères minimum.');$d->prepare('UPDATE cc_users SET password=?,active=1,session_version=session_version+1 WHERE id=?')->execute([password_hash($p['password'],PASSWORD_DEFAULT),$target['id']]);}}
            else fail('Action invalide.');$d->prepare('INSERT INTO cc_events(actor,event,created_at) VALUES (?,?,UTC_TIMESTAMP())')->execute([$u['name'],$p['mode'].' : '.$name]);
        }$users=$d->query('SELECT id,name,role,active FROM cc_users ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);foreach($users as&$member)$member['registers']=allowed_registers($d,$member);unset($member);reply(['users'=>$users]);
    }
    if($action==='archive'){
        admin($u);$id=(string)($_GET['id']??'');
        if(preg_match('/^c([0-9]+)$/D',$id,$match)){
            $revision=(int)$match[1];$q=$d->prepare('SELECT revision,actor,created_at FROM '.$tables['changes'].' WHERE revision=?');$q->execute([$revision]);$archive=$q->fetch(PDO::FETCH_ASSOC);if(!$archive)fail('Archive introuvable.',404);$archive['data']=json_encode(archive_revision($d,$revision,$register),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);reply(['register'=>$register,'archive'=>$archive]);
        }
        if(!ctype_digit($id))fail('Archive invalide.');$q=$d->prepare('SELECT revision,actor,created_at,data FROM '.$tables['versions'].' WHERE id=?');$q->execute([(int)$id]);$archive=$q->fetch(PDO::FETCH_ASSOC);if(!$archive)fail('Archive introuvable.',404);$archive['data']=json_encode(bind_register_state(valid_state(json_decode($archive['data'],true,512,JSON_THROW_ON_ERROR)),$register),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);reply(['register'=>$register,'archive'=>$archive]);
    }
    if($action==='archives'){
        admin($u);reply(['register'=>$register,'archives'=>$d->query("SELECT CAST(id AS CHAR) AS id,revision,actor,created_at FROM ".$tables['versions']." UNION ALL SELECT CONCAT('c',revision) AS id,revision,actor,created_at FROM ".$tables['changes']." ORDER BY revision DESC,created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if($action==='save'){
        if($u['role']==='reader')fail('Votre compte permet uniquement la consultation.',403);
        $request=$p['request']??'';if(!is_string($request)||!preg_match('/^[a-zA-Z0-9-]{10,80}$/D',$request))fail('Requête invalide.');$next=bind_register_state(valid_state($p['state']??[]),$register);
        $d->beginTransaction();$q=$d->query('SELECT * FROM '.$tables['state'].' WHERE id=1 FOR UPDATE');$row=$q->fetch(PDO::FETCH_ASSOC);$old=bind_register_state(valid_state(json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)),$register);
        $q=$d->prepare('SELECT user_id FROM '.$tables['requests'].' WHERE request_id=?');$q->execute([$request]);$already=$q->fetchColumn();if($already!==false){if((int)$already!==(int)$u['id'])fail('Requête invalide.',409);$d->commit();reply(['register'=>$register,'state'=>$old,'revision'=>(int)$row['revision']]);}
        if(($p['revision']??null)!==(int)$row['revision']){$d->rollBack();fail('La caisse a changé sur un autre appareil. Actualisez puis vérifiez votre saisie avant de recommencer.',409);}
        $restore=!empty($p['restore']);if($restore)admin($u);
        if(!$restore)$next=authorize_step($old,$next,$u);
        else $next=restore_step($old,$next,$u,(int)$row['revision']);
        $next['updatedAt']=gmdate('c');$next['lastBackupAt']=$old['lastBackupAt'];
        $daily=$d->query('SELECT id FROM '.$tables['versions'].' WHERE created_at>=UTC_DATE() LIMIT 1')->fetchColumn();
        if($restore||$daily===false)$d->prepare('INSERT INTO '.$tables['versions'].'(revision,actor,created_at,data) VALUES (?,?,UTC_TIMESTAMP(),?)')->execute([$row['revision'],$u['name'],$row['data']]);
        $delta=json_encode(make_delta($old,$next,$restore),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $d->prepare('INSERT INTO '.$tables['changes'].'(revision,actor,created_at,data) VALUES (?,?,UTC_TIMESTAMP(),?)')->execute([(int)$row['revision']+1,$u['name'],$delta]);
        $revision=(int)$row['revision']+1;$json=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$d->prepare('UPDATE '.$tables['state'].' SET revision=?,data=? WHERE id=1')->execute([$revision,$json]);$d->prepare('INSERT INTO '.$tables['requests'].' VALUES (?,?,?,UTC_TIMESTAMP())')->execute([$request,$u['id'],$revision]);$d->commit();reply(['register'=>$register,'state'=>$next,'revision'=>$revision]);
    }
    if($action!=='state')fail('Action inconnue.',404);$row=$d->query('SELECT * FROM '.$tables['state'].' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    // Version 2 : conversion unique des anciennes rubriques à l'ouverture
    $current=bind_register_state(valid_state(json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)),$register);
    if($u['role']!=='reader'&&migrate_legacy($current)[1]>0){history_storage($d,$tables['changes']);$migrated=migrate_register_if_needed($d,$u,$register,$current);if($migrated)reply(['register'=>$register,'registers'=>allowed_registers($d,$u),'state'=>$migrated['state'],'revision'=>$migrated['revision'],'user'=>public_user($u),'csrf'=>csrf()]);}reply(['register'=>$register,'registers'=>allowed_registers($d,$u),'state'=>bind_register_state(valid_state(json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)),$register),'revision'=>(int)$row['revision'],'user'=>public_user($u),'csrf'=>csrf()]);
}catch(Throwable $e){if(isset($d)&&$d->inTransaction())$d->rollBack();error_log('Comptacaisse: '.$e->getMessage());fail('Action impossible. Vérifiez la configuration ou les journaux de l’hébergement.',500);}
