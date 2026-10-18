<?php
declare(strict_types=1);
require dirname(__DIR__).'/scripts/bootstrap.php';
$passed=0;
function check(string $name,bool $condition):void{global $passed;if(!$condition)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;}
$state=catalogue_read(catalogue_root().'/data/state.json');$copy=$state;$snapshot=catalogue_snapshot($state);
check('Le snapshot ne modifie pas les données originales',$state===$copy);
$byId=[];foreach($state['offers']??[] as $o)$byId[$o['source'].'|'.$o['url']]=$o;
$unchanged=true;$eligible=true;
foreach($snapshot['products'] as $p)foreach($p['offers'] as $o){$original=$byId[$o['source'].'|'.$o['url']]??[];$unchanged=$unchanged&&$o['checkedAt']===($original['checkedAt']??null)&&$o['checkedEpoch']===($original['checkedEpoch']??null)&&$o['priceCents']===($original['priceCents']??null);$check=$o;$check['checkedEpoch']=time();$eligible=$eligible&&MatTech\publishable($check);}
check('Montants et dates réels sont conservés exactement',$unchanged);
check('Chaque offre exportée garde les critères vendeur, neuf et stock',$eligible);
check('L’export public ne contient ni tâches ni paramètres privés',array_intersect_key($snapshot,array_flip(['tasks','settings','email','passwordHash']))===[]);
check('Un état vide produit un catalogue vide',catalogue_snapshot([])['products']===[]);
$fixture=reset($state['offers']);
if($fixture){
 $fixture['status']='active';$fixture['checkedEpoch']=time()-86400;$fixture['checkedAt']=gmdate('c',$fixture['checkedEpoch']);
 $out=catalogue_snapshot(['offers'=>[$fixture]]);
 check('Un ancien relevé reste daté et n’est jamais antidaté',$out['products']&&$out['products'][0]['offers'][0]['checkedEpoch']===$fixture['checkedEpoch']);
 $fixture['condition']='used';check('Une offre d’occasion est exclue',catalogue_snapshot(['offers'=>[$fixture]])['products']===[]);
 $fixture['condition']='new';$fixture['availability']='out_of_stock';check('Une offre indisponible est exclue',catalogue_snapshot(['offers'=>[$fixture]])['products']===[]);
 $fixture['availability']='in_stock';$fixture['sellerVerified']=false;check('Un vendeur non approuvé est exclu',catalogue_snapshot(['offers'=>[$fixture]])['products']===[]);
}
check('Le lien dépend du vrai nom du dépôt',catalogue_address('proprietaire/mat-tech-catalogue','main')==='https://raw.githubusercontent.com/proprietaire/mat-tech-catalogue/main/public/catalogue.json');
$blocked=false;try{catalogue_address('../depot','main');}catch(RuntimeException){$blocked=true;}check('Un identifiant de dépôt invalide est bloqué',$blocked);
echo $passed.' contrôles d’export réussis.'.PHP_EOL;
