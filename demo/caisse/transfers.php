<?php
declare(strict_types=1);
function transfer_storage(PDO $db):void {
    $db->exec('CREATE TABLE IF NOT EXISTS cc_transfers (id VARCHAR(80) PRIMARY KEY,source VARCHAR(20) NOT NULL,destination VARCHAR(20) NOT NULL,movement_date DATE NOT NULL,cents BIGINT NOT NULL,note VARCHAR(200) NOT NULL,actor_id BIGINT NOT NULL,actor_name VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL,request_hash CHAR(64) NOT NULL,cancelled TINYINT NOT NULL DEFAULT 0,cancel_request VARCHAR(80) UNIQUE,cancelled_at DATETIME,cancelled_by BIGINT,cancel_reason VARCHAR(200)) ENGINE=InnoDB');
}
function transfer_request_id(mixed $id):string {
    if(!is_string($id)||!preg_match('/^[a-zA-Z0-9-]{10,80}$/D',$id))fail('Référence de demande invalide.');return $id;
}
function transfer_input(array $p):array {
    $source=$p['source']??null;$destination=$p['destination']??null;
    if(!is_string($source)||!is_string($destination)||!isset(REGISTERS[$source],REGISTERS[$destination])||$source===$destination)fail('Choisissez deux caisses différentes.');
    if(!valid_date($p['date']??null)||$p['date']>date('Y-m-d'))fail('La date du transfert doit être valide et ne peut pas être future.');
    if(!amount($p['cents']??null,true))fail('Le montant doit être positif et en centimes entiers.');
    if(!text_ok($p['note']??null))fail('La remarque ne peut pas dépasser 200 caractères.');
    return ['source'=>$source,'destination'=>$destination,'date'=>$p['date'],'cents'=>$p['cents'],'note'=>trim($p['note'])];
}
function transfer_entry(array $movement,string $register):array {
    $out=$register===$movement['source'];$peer=$out?$movement['destination']:$movement['source'];
    return ['id'=>$movement['id'].($out?'-out':'-in'),'date'=>$movement['movement_date'],'kind'=>$out?'out':'in','category'=>'transfer','cents'=>(int)$movement['cents'],'label'=>'Transfert '.($out?'vers ':'depuis ').REGISTERS[$peer],'transferId'=>$movement['id'],'transferPeer'=>$peer,'transferNote'=>$movement['note'],'deleted'=>(bool)$movement['cancelled']];
}
function same_transfer_entry(array $a,array $b):bool {ksort($a);ksort($b);return $a===$b;}
function preserve_transfer_entries(array $old,array $next):void {
    $before=[];$after=[];
    foreach($old['entries']as$e)if(($e['category']??'')==='transfer')$before[$e['id']]=$e;
    foreach($next['entries']as$e)if(($e['category']??'')==='transfer')$after[$e['id']]=$e;
    if(count($before)!==count($after))fail('Cette restauration modifierait un transfert entre caisses. Utilisez une sauvegarde qui conserve ces transferts ou une restauration complète de la base.');
    foreach($before as$id=>$entry)if(!isset($after[$id])||!same_transfer_entry($entry,$after[$id]))fail('Les transferts existants doivent rester identiques dans les deux caisses.');
}
function transfer_funds(array $state,string $date,int $cents):void {
    if($date<$state['opening']['date'])fail('La date précède le point de départ d’une caisse.');
    $balance=balance_at($state,$date);if($balance<$cents)fail('Espèces insuffisantes dans la caisse à débiter.');
    $days=[];foreach($state['entries']as$e)if($e['date']>$date)$days[$e['date']]=($days[$e['date']]??0)+entry_effect($e);
    ksort($days);foreach($days as$change){$balance+=$change;if($balance<$cents)fail('Ce mouvement rendrait un solde ultérieur négatif. Choisissez la bonne date ou vérifiez les opérations.');}
}
/** Always lock restaurant then camping, regardless of transfer direction. */
function lock_transfer_states(PDO $db):array {
    $result=[];$lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    foreach(array_keys(REGISTERS)as$register){$tables=register_tables($register);$row=$db->query('SELECT * FROM '.$tables['state'].' WHERE id=1'.$lock)->fetch(PDO::FETCH_ASSOC);if(!$row)fail('Caisse non initialisée.');$result[$register]=['row'=>$row,'state'=>bind_register_state(valid_state(json_decode($row['data'],true,512,JSON_THROW_ON_ERROR)),$register)];}return $result;
}
function transfer_revisions(array $rows,array $p):void {
    if(!is_array($p['revisions']??null))fail('Actualisez les soldes avant de transférer.');
    foreach(array_keys(REGISTERS)as$id)if(($p['revisions'][$id]??null)!==(int)$rows[$id]['row']['revision'])fail('Une caisse a changé. Actualisez les soldes et vérifiez le transfert avant de recommencer.',409);
}
function persist_transfer_state(PDO $db,string $register,array $oldRow,array $old,array &$next,string $actor):int {
    $tables=register_tables($register);$now=gmdate('Y-m-d H:i:s');$next['updatedAt']=gmdate('c');$next['lastBackupAt']=$old['lastBackupAt'];
    $q=$db->prepare('SELECT id FROM '.$tables['versions'].' WHERE created_at>=? LIMIT 1');$q->execute([gmdate('Y-m-d 00:00:00')]);
    if($q->fetchColumn()===false)$db->prepare('INSERT INTO '.$tables['versions'].'(revision,actor,created_at,data) VALUES (?,?,?,?)')->execute([(int)$oldRow['revision'],$actor,$now,$oldRow['data']]);
    $revision=(int)$oldRow['revision']+1;
    $db->prepare('INSERT INTO '.$tables['changes'].'(revision,actor,created_at,data) VALUES (?,?,?,?)')->execute([$revision,$actor,$now,json_encode(make_delta($old,$next,false),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $q=$db->prepare('UPDATE '.$tables['state'].' SET revision=?,data=? WHERE id=1 AND revision=?');$q->execute([$revision,json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),(int)$oldRow['revision']]);if($q->rowCount()!==1)fail('La caisse a changé.',409);return $revision;
}
function transfer_context(PDO $db,array $user,string $active,string $date):array {
    admin($user);if(!valid_date($date)||$date>date('Y-m-d'))fail('Choisissez une date valide, au plus tard aujourd’hui.');
    $db->beginTransaction();try{$rows=lock_transfer_states($db);$balances=[];$revisions=[];foreach($rows as$id=>$cash){if($date<$cash['state']['opening']['date'])fail('La date précède le point de départ de '.REGISTERS[$id].'.');$balances[$id]=balance_at($cash['state'],$date);$revisions[$id]=(int)$cash['row']['revision'];}$db->commit();return ['register'=>$active,'state'=>$rows[$active]['state'],'revision'=>$revisions[$active],'balances'=>$balances,'revisions'=>$revisions,'date'=>$date];}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function transfer_result(array $rows,array $movement,bool $replayed):array {
    $states=[];$revisions=[];foreach($rows as$id=>$cash){$states[$id]=$cash['state'];$revisions[$id]=(int)$cash['row']['revision'];}return ['states'=>$states,'revisions'=>$revisions,'transfer'=>$movement,'replayed'=>$replayed];
}
/** Both cash states, both archives and the movement record commit together. */
function execute_transfer(PDO $db,array $user,array $p,bool $cancel=false):array {
    admin($user);$request=transfer_request_id($p['request']??null);
    $input=$cancel?null:transfer_input($p);$fingerprint=$input?hash('sha256',json_encode($input,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)):null;
    $id=$cancel?transfer_request_id($p['transferId']??null):$request;
    $reason=$p['reason']??'';if($cancel&&(!text_ok($reason)||trim($reason)===''))fail('Un motif d’annulation est obligatoire.');
    $db->beginTransaction();
    try{
        $rows=lock_transfer_states($db);$q=$db->prepare('SELECT * FROM cc_transfers WHERE id=?');$q->execute([$id]);$movement=$q->fetch(PDO::FETCH_ASSOC);
        if(!$cancel&&$movement){if((int)$movement['actor_id']!==(int)$user['id']||!hash_equals($movement['request_hash'],$fingerprint))fail('Cette référence appartient déjà à une autre demande.',409);$db->commit();return transfer_result($rows,$movement,true);}
        if($cancel){
            if(!$movement)fail('Transfert introuvable.',404);
            if((int)$movement['cancelled']===1){if($movement['cancel_request']===$request&&(int)$movement['cancelled_by']===(int)$user['id']){$db->commit();return transfer_result($rows,$movement,true);}fail('Ce transfert est déjà annulé.',409);}
        }
        transfer_revisions($rows,$p);
        if(!$cancel){
            foreach($rows as$cash){if($input['date']<$cash['state']['opening']['date'])fail('La date précède le point de départ d’une caisse.');assert_open($cash['state'],$input['date']);}
            transfer_funds($rows[$input['source']]['state'],$input['date'],$input['cents']);
            $movement=['id'=>$id,'source'=>$input['source'],'destination'=>$input['destination'],'movement_date'=>$input['date'],'cents'=>$input['cents'],'note'=>$input['note'],'actor_id'=>(int)$user['id'],'actor_name'=>$user['name'],'created_at'=>gmdate('Y-m-d H:i:s'),'request_hash'=>$fingerprint,'cancelled'=>0,'cancel_request'=>null,'cancelled_at'=>null,'cancelled_by'=>null,'cancel_reason'=>null];
        }else{foreach($rows as$cash)assert_open($cash['state'],$movement['movement_date']);transfer_funds($rows[$movement['destination']]['state'],$movement['movement_date'],(int)$movement['cents']);}
        foreach($rows as$register=>&$cash){
            $next=$cash['state'];$entry=transfer_entry($movement,$register);
            if($cancel){
                $found=false;foreach($next['entries']as&$existing)if($existing['id']===$entry['id']){if(!same_transfer_entry($existing,$entry))fail('Les deux côtés du transfert ne correspondent plus. Annulation refusée.');$existing['deleted']=true;$found=true;break;}unset($existing);if(!$found)fail('Un côté du transfert est absent. Annulation refusée.');
            }else $next['entries'][]=$entry;
            $detail=$user['name'].' · Réf. '.$id.' · '.REGISTERS[$movement['source']].' → '.REGISTERS[$movement['destination']].' · '.eur((int)$movement['cents']).' · Date : '.$movement['movement_date'].' · '.$movement['note'];
            if($cancel)$detail.=' · Motif : '.trim($reason);
            $next['audit'][]=['at'=>gmdate('c'),'action'=>$cancel?'Annulation de transfert':'Transfert interne','detail'=>$detail,'reason'=>$cancel?trim($reason):''];
            $next['updatedAt']=gmdate('c');$next=valid_state($next);
            $cash['row']['revision']=persist_transfer_state($db,$register,$cash['row'],$cash['state'],$next,$user['name']);$cash['state']=$next;
        }unset($cash);
        if(!$cancel){$db->prepare('INSERT INTO cc_transfers(id,source,destination,movement_date,cents,note,actor_id,actor_name,created_at,request_hash,cancelled) VALUES (?,?,?,?,?,?,?,?,?,?,0)')->execute([$id,$movement['source'],$movement['destination'],$movement['movement_date'],$movement['cents'],$movement['note'],$user['id'],$user['name'],$movement['created_at'],$fingerprint]);}
        else{$db->prepare('UPDATE cc_transfers SET cancelled=1,cancel_request=?,cancelled_at=?,cancelled_by=?,cancel_reason=? WHERE id=? AND cancelled=0')->execute([$request,gmdate('Y-m-d H:i:s'),$user['id'],trim($reason),$id]);$movement['cancelled']=1;$movement['cancel_request']=$request;$movement['cancelled_at']=gmdate('Y-m-d H:i:s');$movement['cancelled_by']=(int)$user['id'];$movement['cancel_reason']=trim($reason);}
        $db->commit();return transfer_result($rows,$movement,false);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
