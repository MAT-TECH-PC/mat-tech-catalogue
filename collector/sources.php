<?php
declare(strict_types=1);
/* Les identifiants ci-dessous désignent des rubriques marchandes, pas des prix. */
return [
 'alternate' => [
  'name'=>'ALTERNATE', 'origin'=>'https://www.alternate.fr',
  'sellers'=>['ALTERNATE GmbH','ALTERNATE'],
  'categories'=>[
   'cpu'=>['/Processeurs/Processeurs-AMD','/Processeurs/Processeurs-Intel'],
   'gpu'=>['/Cartes-graphiques'], 'motherboard'=>['/Cartes-mères'],
   'ram'=>['/Mémoire-vive/DDR4-RAM','/Mémoire-vive/DDR5-RAM'],
   'ssd'=>['/SSD'], 'psu'=>['/Alimentations'], 'case'=>['/Boîtiers'],
   'cooler'=>['/Refroidisseurs-CPU'],
  ],
  'productPattern'=>'~/html/product/[0-9]+$~',
 ],
 'pccomponentes'=>[
  'name'=>'PcComponentes', 'origin'=>'https://www.pccomponentes.fr',
  'sellers'=>['PcComponentes'],
  'categories'=>[
   'cpu'=>['/processeurs'], 'gpu'=>['/cartes-graphiques'],
   'motherboard'=>['/categories/cartes-meres'], 'ram'=>['/categories/memoires-ram/ddr4','/categories/memoires-ram/ddr5'],
   'ssd'=>['/categories/disques-durs/disque-ssd'], 'psu'=>['/categories/alimentations'],
   'case'=>['/categories/boitiers-pc'], 'cooler'=>['/categories/refrigeration','/categories/refrigeration-liquide/kits-de-refroidissement-liquide'],
  ],
  'productPattern'=>null,
 ],
 'ldlc'=>[
  'name'=>'LDLC', 'origin'=>'https://www.ldlc.com', 'sellers'=>['LDLC'],
  'categories'=>[
   'cpu'=>['/informatique/pieces-informatique/processeur/c4300/'],
   'gpu'=>['/informatique/pieces-informatique/carte-graphique/c4684/'],
   'motherboard'=>['/informatique/pieces-informatique/carte-mere/c4293/'],
   'ram'=>['/informatique/pieces-informatique/memoire-pc/c4703/'],
   'ssd'=>['/informatique/pieces-informatique/disque-ssd/c4698/'],
   'psu'=>['/informatique/pieces-informatique/alimentation-pc/c4289/'],
   'case'=>['/informatique/pieces-informatique/boitier-pc/c4678/'],
   'cooler'=>['/informatique/pieces-informatique/ventilateur-processeur/c4712/'],
  ],
  'productPattern'=>'~^/fiche/PB[0-9]+\.html$~',
 ],
 'materiel'=>[
  'name'=>'Materiel.net', 'origin'=>'https://www.materiel.net', 'sellers'=>['Materiel.net','MATERIEL.NET'],
  'categories'=>[
   'cpu'=>['/processeur/l441/'], 'gpu'=>['/carte-graphique/l426/'],
   'motherboard'=>['/carte-mere/l443/'], 'ram'=>['/memoire/l442/'],
   'ssd'=>['/ssd/l463/'], 'psu'=>['/alimentation-pc/l445/'],
   'case'=>['/boitier-pc/l446/'], 'cooler'=>['/ventilateur-processeur/l458/','/watercooling-aio/l8697/'],
  ],
  'productPattern'=>'~^/produit/[0-9]+\.html$~',
 ],
];
