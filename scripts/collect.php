<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
if(PHP_SAPI!=='cli')throw new RuntimeException('Ce programme doit être lancé en ligne de commande.');
foreach(['curl','dom','iconv'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Extension PHP manquante : '.$extension);
$options=getopt('', ['seconds:','jobs:']);
$seconds=max(1,min(300,(int)($options['seconds']??240)));$jobs=max(1,min(120,(int)($options['jobs']??80)));
$store=catalogue_store();$report=[];$failure=null;
try{$report=(new MatTech\Runner($store))->run($jobs,$seconds,true);}
catch(Throwable $e){$failure=$e;}
finally{
 $state=$store->read('state');
 catalogue_write(catalogue_root().'/data/state.json',$state);
 catalogue_write(catalogue_root().'/data/robots.json',$store->read('robots'));
 $snapshot=catalogue_export($state);
 $fresh=MatTech\publicCatalogue($state);
 $report+=['at'=>MatTech\now(),'referencesKnown'=>count($snapshot['products']),'freshReferences'=>$fresh['total'],'freshOffers'=>$fresh['offers']];
 catalogue_write(catalogue_root().'/public/health.json',$report);
 echo MatTech\json($report).PHP_EOL;
 if(getenv('GITHUB_STEP_SUMMARY')){
  $summary="## Passage réel du catalogue\n\nDate UTC : ".MatTech\now()."\n\n";
  $summary.=(int)($report['processed']??0)." lectures effectuées ; ".(int)($report['accepted']??0)." offres validées pendant ce passage.\n\n";
  $summary.=$fresh['total']." références avec un relevé de moins de trois heures ; ".$fresh['offers']." offres récentes.\n\n";
  if(!($report['accepted']??0))$summary.="**Aucune offre validée pendant ce passage.** Le statut technique vert ne garantit pas que les boutiques aient accepté la collecte.\n\n";
  foreach($state['sources']??[] as $id=>$source)$summary.='- '.($source['name']??$id).' : '.($source['status']??'waiting').(($source['lastError']??null)?' — '.str_replace(["\n","\r"],' ',(string)$source['lastError']):'')."\n";
  $summary.="\nLa découverte progresse par lots. Une référence non contrôlée ne reçoit jamais un prix inventé. Les e-mails de variation ne sont pas connectés.\n";
  file_put_contents(getenv('GITHUB_STEP_SUMMARY'),$summary,FILE_APPEND);
 }
}
if($failure)throw $failure;
