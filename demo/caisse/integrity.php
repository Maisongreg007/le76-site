<?php
declare(strict_types=1);

function counted_coin_total(mixed $items): int {
    // Billets de 500 € et toutes les pièces / tous les rouleaux (les billets de 5 à 200 € sont dans "banknotes")
    $allowed=['b500','c200','c100','c50','c20','c10','c5','c2','c1','r200','r100','r50','r20','r10','r5','r2','r1'];
    if(!is_array($items))fail('Détail des pièces invalide.');
    foreach($items as$key=>$quantity)if(!in_array($key,$allowed,true))fail('Pièce ou rouleau de comptage inconnu.');
    return exchange_total($items);
}
function balance_at(array $state,string $date): int {
    $total=$state['opening']['cents'];
    foreach($state['entries']as$entry)if($entry['date']<=$date)$total+=entry_effect($entry);
    if(!amount($total))fail('Solde théorique hors limites.');return $total;
}
function eur(int $cents): string {return number_format($cents/100,2,',',' ').' €';}
function unit_description(array $items): string {
    $labels=['b500'=>'billet de 500 €','b200'=>'billet de 200 €','b100'=>'billet de 100 €','b50'=>'billet de 50 €','b20'=>'billet de 20 €','b10'=>'billet de 10 €','b5'=>'billet de 5 €','c200'=>'pièce de 2 €','c100'=>'pièce de 1 €','c50'=>'pièce de 50 c','c20'=>'pièce de 20 c','c10'=>'pièce de 10 c','c5'=>'pièce de 5 c','c2'=>'pièce de 2 c','c1'=>'pièce de 1 c','r200'=>'rouleau de 2 € (50 €)','r100'=>'rouleau de 1 € (25 €)','r50'=>'rouleau de 50 c (20 €)','r20'=>'rouleau de 20 c (8 €)','r10'=>'rouleau de 10 c (4 €)','r5'=>'rouleau de 5 c (2,50 €)','r2'=>'rouleau de 2 c (1 €)','r1'=>'rouleau de 1 c (0,50 €)'];
    $parts=[];foreach($items as$key=>$quantity)if($quantity>0)$parts[]=$quantity.' × '.($labels[$key]??$key);return implode(' · ',$parts);
}
function operation_description(string $list,array $e): string {
    $prefix=$e['date'].' · Réf. '.$e['id'].' · ';
    if($list==='checks'){
        $detail='Prévu : '.eur($e['expected']).' · Compté : '.eur($e['counted']).' · Écart : '.eur($e['counted']-$e['expected']).' · '.$e['note'];
        if(isset($e['banknotes']))$detail.=' · Billets : '.json_encode($e['banknotes'],JSON_UNESCAPED_UNICODE).' · Pièces/rouleaux : '.unit_description($e['coins']??[]).' · Autres pièces : '.eur($e['otherCoinsCents']??0);
        return $prefix.$detail;
    }
    if($list==='exchanges')return $prefix.eur($e['cents']).' · Donné : '.unit_description($e['given']).' → Reçu : '.unit_description($e['received']).' · '.$e['note'].' · Solde inchangé';
    if(($e['category']??'')==='transfer')return $prefix.'Transfert '.($e['kind']==='out'?'vers ':'depuis ').REGISTERS[$e['transferPeer']].' · '.eur($e['cents']).' · Réf. '.$e['transferId'];
    $bank=($e['category']??'cash')==='bank';
    $kind=$bank?'Dépôt bancaire':($e['kind']==='in'?'Entrée':'Sortie');
    $cat=$bank?'Statut : '.BANK_STATUSES[$e['bankStatus']??'deposited'].(isset($e['bankName'])&&$e['bankName']!==''?' · Banque : '.$e['bankName']:'')
        :($e['kind']==='in'?(INCOME_CATEGORIES[$e['incomeCategory']??'']??''):(EXPENSE_CATEGORIES[$e['expenseCategory']??'']??($e['expenseCategory']??'')));
    return $prefix.(isset($e['time'])?$e['time'].' · ':'').$kind.' '.eur($e['cents']).' · '.$cat.' · '.$e['label'].(isset($e['ref'])&&$e['ref']!==''?' · Réf./justificatif : '.$e['ref']:'').(isset($e['banknotes'])?' · Billets : '.json_encode($e['banknotes'],JSON_UNESCAPED_UNICODE):'');
}
function only_cancellation(array $before,array $after): bool {
    if($before['deleted']||!$after['deleted'])return false;$after['deleted']=$before['deleted'];return $after===$before;
}
/* ---------- Clôtures mensuelles ---------- */
function closed_months(array $state): array {
    $months=[];foreach($state['closures']??[]as$c)if(!$c['reopened'])$months[$c['month']]=$c;return $months;
}
function assert_open(array $state,string $date): void {
    $month=substr($date,0,7);
    if(isset(closed_months($state)[$month]))fail('Le mois de '.month_label($month).' est clôturé. Rouvrez-le (responsable) pour le modifier.',409);
}
function month_label(string $month): string {
    $names=['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    return $names[(int)substr($month,5,2)-1].' '.substr($month,0,4);
}
/** Plus bas solde atteint à partir d'une date (inclus), jour par jour. */
function lowest_balance_from(array $state,string $date): int {
    $balance=balance_at($state,date('Y-m-d',strtotime($date.' -1 day')));
    if($date<=$state['opening']['date'])$balance=$state['opening']['cents'];
    $days=[];foreach($state['entries']as$e)if($e['date']>=$date)$days[$e['date']]=($days[$e['date']]??0)+entry_effect($e);
    ksort($days);$lowest=PHP_INT_MAX;
    foreach($days as$change){$balance+=$change;$lowest=min($lowest,$balance);}
    return $lowest===PHP_INT_MAX?$balance:$lowest;
}
/** Refuse une opération qui ferait passer la caisse sous zéro (sans bloquer un solde déjà négatif auparavant). */
function assert_enough_cash(array $old,array $next,string $date): void {
    $after=lowest_balance_from($next,$date);
    if($after<0&&$after<lowest_balance_from($old,$date))fail('Espèces insuffisantes : cette opération rendrait le solde de caisse négatif.',409);
}
/** Passage d'un dépôt à l'étape suivante (préparé → déposé → crédité, ou préparé → annulé), sans autre changement. */
function bank_status_forward(array $before,array $after): bool {
    if(($before['category']??'')!=='bank'||($after['category']??'')!=='bank')return false;
    $from=$before['bankStatus']??'deposited';$to=$after['bankStatus']??'deposited';
    $allowed=['prepared'=>['deposited','credited','cancelled'],'deposited'=>['credited']];
    if(!in_array($to,$allowed[$from]??[],true))return false;
    $a=$after;$a['bankStatus']=$before['bankStatus']??null;if($a['bankStatus']===null)unset($a['bankStatus']);
    unset($a['date']);$b=$before;unset($b['date']);ksort($a);ksort($b);
    return $a===$b;
}
/** Convertit les anciennes rubriques (versions 1.x) vers celles du cahier des charges. */
function migrate_legacy(array $state): array {
    $changed=0;
    foreach($state['entries']as&$e){
        if(($e['category']??'cash')==='transfer')continue;
        $before=$e;
        if($e['kind']==='out'&&($e['category']??'cash')==='cash'){
            $cat=$e['expenseCategory']??null;
            if($cat===null||!isset(EXPENSE_CATEGORIES[$cat]))$e['expenseCategory']=LEGACY_EXPENSES[$cat]??'other';
        }elseif($e['kind']==='in'&&($e['category']??'cash')==='cash'&&!isset($e['incomeCategory']))$e['incomeCategory']='sales';
        elseif(($e['category']??'')==='bank'&&!isset($e['bankStatus']))$e['bankStatus']='credited';
        if($e!==$before)$changed++;
    }
    unset($e);
    return [$state,$changed];
}

/** Validate one user action and construct its audit event from the actual data. */
function authorize_step(array $old,array $next,array $user): array {
    if($user['role']==='reader')fail('Votre compte permet uniquement la consultation.',403);
    if(count($next['audit'])!==count($old['audit'])+1||array_slice($next['audit'],0,count($old['audit']))!==$old['audit'])fail('L’historique existant doit être conservé.');
    $submitted=$next['audit'][count($next['audit'])-1];$reason=trim((string)($submitted['reason']??''));
    if(!text_ok($reason,200))fail('Motif trop long.');
    if(($next['closures']??[])!==($old['closures']??[]))fail('Utilisez la clôture mensuelle pour clôturer ou rouvrir un mois.');
    $openingChanged=$next['opening']!==$old['opening'];if($openingChanged){admin($user);if(closed_months($old))fail('Rouvrez les mois clôturés avant de changer le point de départ.',409);}
    $statusOnly=false;
    $changes=[];
    foreach(['entries','checks','exchanges']as$list){
        if(count($next[$list])<count($old[$list]))fail('Utilisez une annulation, pas une suppression.');
        foreach($old[$list]as$i=>$before){
            $after=$next[$list][$i]??null;
            if(!$after||$after['id']!==$before['id'])fail('Les opérations existantes doivent être conservées dans leur ordre.');
            if($before===$after)continue;
            if(($before['category']??'')==='transfer'||($after['category']??'')==='transfer')fail('Utilisez la fonction dédiée pour annuler un transfert dans les deux caisses.');
            if($before['deleted'])fail('Une opération annulée ne peut pas être modifiée.');
            if(in_array($list,['checks','exchanges'],true)&&!only_cancellation($before,$after))fail('Seule l’annulation de ce contrôle ou échange est autorisée.');
            if($list==='entries'&&$after['deleted']&&!only_cancellation($before,$after))fail('Une annulation ne peut pas modifier les données de l’opération.');
            assert_open($old,$before['date']);assert_open($old,$after['date']);
            if($list==='entries'){
                if(bank_status_forward($before,$after))$statusOnly=true;
                if(($after['category']??'')==='bank'&&$after['date']>date('Y-m-d'))fail('Une opération réelle ne peut pas être datée dans le futur.');
            }
            $changes[]=[$list,$before,$after];
        }
        foreach(array_slice($next[$list],count($old[$list]))as$offset=>$after){
            if($list!=='entries'){$after['at']=gmdate('c');$next[$list][count($old[$list])+$offset]=$after;}
            if(($after['category']??'')==='transfer'||isset($after['transferId']))fail('Utilisez la fonction dédiée aux transferts entre caisses.');
            if($after['deleted'])fail('Une nouvelle opération doit être active.');
            assert_open($old,$after['date']);
            if($list==='entries'&&isset(LEGACY_EXPENSES[$after['expenseCategory']??'']))fail('Rubrique d’une ancienne version : rechargez l’application.',409);
            if(($list!=='entries'||($after['category']??'cash')==='bank')&&$after['date']>date('Y-m-d'))fail('Une opération réelle ne peut pas être datée dans le futur.');
            if($list==='checks'){
                if(isset($after['banknotes'])&&(!array_key_exists('coins',$after)||!array_key_exists('otherCoinsCents',$after)))fail('Rechargez l’application pour compter aussi les pièces et les rouleaux.',409);
                $expected=balance_at($old,$after['date']);
                if($after['expected']!==$expected)fail('Le solde attendu a changé. Actualisez puis vérifiez le comptage.',409);
            }
            $changes[]=[$list,null,$after];
        }
    }
    if(count($changes)+($openingChanged?1:0)!==1)fail('Enregistrez une seule opération à la fois.');
    if($openingChanged){
        if($reason==='')fail('Un motif est obligatoire.');
        $action='Point de départ';$detail=$old['opening']['date'].' / '.eur($old['opening']['cents']).' → '.$next['opening']['date'].' / '.eur($next['opening']['cents']);
    }else{
        [$list,$before,$after]=$changes[0];
        if($before!==null&&$reason===''&&!$statusOnly)fail('Un motif est obligatoire pour corriger ou annuler.');
        if($list==='entries')assert_enough_cash($old,$next,min($before['date']??$after['date'],$after['date']));
        $action=match($list){'checks'=>$before?'Annulation de contrôle':'Contrôle de caisse','exchanges'=>$before?'Annulation d’échange':'Échange de monnaie',default=>$before?($after['deleted']?'Annulation':($statusOnly?'Dépôt : '.BANK_STATUSES[$before['bankStatus']??'deposited'].' → '.BANK_STATUSES[$after['bankStatus']]:'Correction')):'Création'};
        $detail=($before!==null&&!$after['deleted']?operation_description($list,$before).' → ':'').operation_description($list,$after);
    }
    $next['audit'][count($next['audit'])-1]=['at'=>gmdate('c'),'action'=>$action,'detail'=>$user['name'].' · '.$detail.($reason!==''?' · Motif : '.$reason:''),'reason'=>$reason];
    return $next;
}
/** Restore the imported audit once; the previous state is kept in server archives. */
function restore_step(array $old,array $next,array $user,int $revision): array {
    admin($user);preserve_transfer_entries($old,$next);$submitted=end($next['audit']);
    $reason=is_array($submitted)?trim((string)($submitted['reason']??'')):'';
    if(!is_array($submitted)||($submitted['action']??'')!=='Restauration'||$reason===''||!text_ok($reason,200))fail('Un motif de restauration est obligatoire.');
    array_pop($next['audit']);
    $next['audit'][]=['at'=>gmdate('c'),'action'=>'Restauration serveur','detail'=>$user['name'].' · Révision précédente : '.$revision.' · Motif : '.$reason.' · État précédent conservé dans les archives serveur.','reason'=>$reason];
    return $next;
}
/** One compact change per revision; a restore is an explicit replacement. */
function make_delta(array $old,array $next,bool $restore): array {
    if($restore)return ['replace'=>$next];
    $patch=['auditAppend'=>array_slice($next['audit'],count($old['audit'])),'updatedAt'=>$next['updatedAt'],'lastBackupAt'=>$next['lastBackupAt']];
    if($old['opening']!==$next['opening'])$patch['opening']=$next['opening'];
    foreach(['entries','checks','exchanges']as$list){$patch[$list]=[];foreach($next[$list]as$i=>$entry)if(!isset($old[$list][$i])||$old[$list][$i]!==$entry)$patch[$list][]=$entry;}
    return $patch;
}
function apply_delta(array $state,array $patch): array {
    if(isset($patch['replace']))return $patch['replace'];
    if(isset($patch['opening']))$state['opening']=$patch['opening'];
    foreach(['entries','checks','exchanges']as$list){$state[$list]??=[];$indexes=[];foreach($state[$list]as$i=>$entry)$indexes[$entry['id']]=$i;foreach($patch[$list]??[]as$entry){if(isset($indexes[$entry['id']]))$state[$list][$indexes[$entry['id']]]=$entry;else $state[$list][]=$entry;}}
    $state['audit']=array_merge($state['audit'],$patch['auditAppend']??[]);$state['updatedAt']=$patch['updatedAt'];$state['lastBackupAt']=$patch['lastBackupAt'];return $state;
}
function history_storage(PDO $db,string $table='cc_changes'): void {
    if(!in_array($table,['cc_changes','cc_camping_changes'],true))fail('Table de journal invalide.');
    // Called before any transaction: MySQL DDL must not commit a save in progress.
    $db->exec('CREATE TABLE IF NOT EXISTS '.$table.' (revision BIGINT PRIMARY KEY,actor VARCHAR(80) NOT NULL,created_at DATETIME NOT NULL,data LONGTEXT NOT NULL) ENGINE=InnoDB');
}
function archive_revision(PDO $db,int $revision,string $register='restaurant'): array {
    $tables=register_tables($register);
    $q=$db->prepare('SELECT revision,data FROM '.$tables['versions'].' WHERE revision<=? ORDER BY revision DESC,id DESC LIMIT 1');$q->execute([$revision]);$base=$q->fetch(PDO::FETCH_ASSOC);if(!$base)fail('Point de restauration introuvable.',404);
    $state=bind_register_state(valid_state(json_decode($base['data'],true,512,JSON_THROW_ON_ERROR)),$register);$current=(int)$base['revision'];
    $q=$db->prepare('SELECT revision,data FROM '.$tables['changes'].' WHERE revision>? AND revision<=? ORDER BY revision');$q->execute([$current,$revision]);
    while($change=$q->fetch(PDO::FETCH_ASSOC)){if((int)$change['revision']!==$current+1)fail('Journal incomplet : restauration refusée.',409);$state=apply_delta($state,json_decode($change['data'],true,512,JSON_THROW_ON_ERROR));$current++;}
    if($current!==$revision)fail('Révision introuvable.',404);return bind_register_state(valid_state($state),$register);
}
