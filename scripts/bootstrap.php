<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/collector/lib.php';

function catalogue_root(): string { return dirname(__DIR__); }
function catalogue_read(string $path,array $fallback=[]):array {
 if(!is_file($path))return $fallback;
 $data=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
 if(!is_array($data))throw new RuntimeException('Format de données incorrect : '.basename($path));
 return $data;
}
function catalogue_write(string $path,array $data):void {
 if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
 $temp=$path.'.tmp';
 if(file_put_contents($temp,MatTech\json($data).PHP_EOL,LOCK_EX)===false||!rename($temp,$path))throw new RuntimeException('Enregistrement impossible : '.basename($path));
}
function catalogue_store():MatTech\Store {
 $store=new MatTech\Store(catalogue_root().'/runtime');
 $store->write('state',catalogue_read(catalogue_root().'/data/state.json',$store->initial()));
 $store->write('robots',catalogue_read(catalogue_root().'/data/robots.json'));
 return $store;
}
function catalogue_snapshot(array $state):array {
 $products=[];
 foreach($state['offers']??[] as $offer){
  // Validate source, seller, condition, stock and integer price as in the API.
  // Only the eligibility check ignores age; the actual stored dates stay intact.
  $check=$offer;$check['checkedEpoch']=time();
  if(!MatTech\publishable($check)||!is_int($offer['checkedEpoch']??null)||$offer['checkedEpoch']>time()+60)continue;
  $id=$offer['productId'];
  $products[$id]??=['id'=>$id,'name'=>$offer['name'],'brand'=>trim(str_replace(['®','™'],'',$offer['brand'])),'mpn'=>$offer['mpn'],'gtin'=>$offer['gtin'],'category'=>$offer['category'],'specs'=>$offer['specs'],'image'=>$offer['image'],'offers'=>[]];
  $products[$id]['offers'][]=$offer;
 }
 foreach($products as &$p)usort($p['offers'],fn($a,$b)=>$a['priceCents']<=>$b['priceCents']);unset($p);
 $sourceStates=[];
 foreach(MatTech\sources() as $id=>$s){$st=$state['sources'][$id]??[];$sourceStates[]=['id'=>$id,'name'=>$s['name'],'url'=>$s['origin'],'status'=>$st['status']??'waiting','lastSuccess'=>$st['lastSuccess']??null];}
 return ['schema'=>1,'version'=>'github-1.0','publishedAt'=>MatTech\now(),'lastRun'=>$state['lastRun']??null,'lastScheduledRun'=>$state['lastScheduledRun']??null,'freshnessSeconds'=>MatTech\MAX_AGE,'products'=>array_values($products),'sources'=>$sourceStates,'events'=>array_slice($state['events']??[],0,80),'scope'=>'Découverte progressive dans quatre boutiques. Couverture historique non exhaustive.'];
}
function catalogue_address(string $repository,string $branch):string {
 if(!preg_match('~^[A-Za-z0-9-]+/[A-Za-z0-9_.-]+$~D',$repository)||!preg_match('~^[A-Za-z0-9_.-]+$~D',$branch))throw new RuntimeException('Identifiant de dépôt ou de branche invalide.');
 return 'https://raw.githubusercontent.com/'.$repository.'/'.$branch.'/public/catalogue.json';
}
function catalogue_export(array $state,?string $repository=null,?string $branch=null):array {
 $repository??=getenv('GITHUB_REPOSITORY')?:'';$branch??=getenv('CATALOGUE_BRANCH')?:'main';
 $address=$repository?catalogue_address($repository,$branch):'';
 $root=catalogue_root();$snapshot=catalogue_snapshot($state);
 catalogue_write($root.'/public/catalogue.json',$snapshot);
 if(file_put_contents($root.'/site/archive.js','globalThis.MATTECH_ARCHIVE = '.MatTech\json($snapshot).';'.PHP_EOL)===false)throw new RuntimeException('Archive du site non enregistrée.');
 if($address){
  if(file_put_contents($root.'/site/config.js','globalThis.MATTECH_DATA_URL = '.json_encode($address,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).';'.PHP_EOL)===false)throw new RuntimeException('Lien du site non enregistré.');
 }
 return $snapshot;
}
