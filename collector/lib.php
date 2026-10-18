<?php
declare(strict_types=1);
namespace MatTech;

const VERSION = '0.2.1';
const UA = 'MATTECHCatalogue/0.2 (+https://mat-tech-pc.fr/)';
const CATEGORIES = ['cpu'=>'Processeurs','gpu'=>'Cartes graphiques','motherboard'=>'Cartes mères','ram'=>'Mémoire RAM','ssd'=>'SSD','psu'=>'Alimentations','case'=>'Boîtiers','cooler'=>'Refroidissement'];
const MAX_AGE = 10800; // Une offre de plus de trois heures sort du catalogue public.

function sources(): array { static $s; return $s ??= require __DIR__.'/sources.php'; }
function now(): string { return gmdate('c'); }
function norm(string $s): string { return strtolower(preg_replace('/[^a-zA-Z0-9]/','', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',str_replace(['®','™'],'',$s)) ?: $s)); }
function clean(string $s): string { return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($s),ENT_QUOTES|ENT_HTML5,'UTF-8')) ?? ''); }
function valueName(mixed $v): string { return is_array($v) ? clean((string)($v['name'] ?? '')) : (is_string($v) ? clean($v) : ''); }
function fail(string $s): never { throw new \RuntimeException($s); }
function json(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }

/** Conversion décimale textuelle : aucun calcul de prix en virgule flottante. */
function cents(mixed $amount): ?int {
 if (!is_string($amount) && !is_int($amount) && !is_float($amount)) return null;
 $s=trim((string)$amount);
 $s=preg_replace('/(?<=\d)\s*€\s*(\d{2})$/u', '.$1', $s);
 $s=str_replace(["\u{00a0}","\u{202f}",' ','€','EUR'],'',$s);
 if (str_contains($s,',') && str_contains($s,'.')) $s=str_replace('.', '', $s);
 $s=str_replace(',','.',$s);
 if (!preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/D',$s,$m)) return null;
 $v=((int)$m[1])*100+(int)str_pad($m[2] ?? '',2,'0');
 return $v>0 && $v<=10000000 ? $v : null;
}
function gtin(mixed $s): string {
 $s=preg_replace('/\D/','',(string)$s) ?? '';
 if (!in_array(strlen($s),[8,12,13,14],true)) return '';
 $sum=0;for($i=strlen($s)-2,$weight=3;$i>=0;$i--,$weight=4-$weight)$sum+=((int)$s[$i])*$weight;
 return (10-$sum%10)%10 === (int)substr($s,-1) ? str_pad($s,14,'0',STR_PAD_LEFT) : '';
}
function canonicalUrl(string $url, string $source, string $base=''): string {
 $origin=sources()[$source]['origin'] ?? ''; if (!$origin) fail('Boutique non prise en charge.');
 $url=html_entity_decode(trim($url),ENT_QUOTES|ENT_HTML5);
 if (str_starts_with($url,'//')) $url='https:'.$url;
 elseif (str_starts_with($url,'/')) $url=$origin.$url;
 elseif (!preg_match('~^https://~i',$url)) {
  if (!$base) fail('Adresse produit non valide.');
  $url=str_starts_with($url,'?') ? strtok($base,'?').$url : $origin.rtrim(dirname(parse_url($base,PHP_URL_PATH) ?: '/'),'/').'/'.$url;
 }
 $p=parse_url($url); $host=parse_url($origin,PHP_URL_HOST);
 if (!$p || ($p['scheme']??'')!=='https' || strtolower($p['host']??'')!==$host || isset($p['user']) || isset($p['port'])) fail('Adresse en dehors de la boutique autorisée.');
 $path=$p['path']??'/'; if(strlen($path)>700 || preg_match('/[\x00-\x20]/',$path)) fail('Adresse invalide.');
 $path=implode('/',array_map(fn($part)=>rawurlencode(rawurldecode($part)),explode('/',$path)));
 parse_str($p['query']??'',$q); $kept=[];
 foreach(['page','p','pageSize','sort','offer','offerId'] as $k) if(isset($q[$k]) && is_scalar($q[$k]))$kept[$k]=(string)$q[$k];
 return $origin.$path.($kept?'?'.http_build_query($kept):'');
}
function sourceFor(string $url): ?string {foreach(sources() as $id=>$s)if(parse_url($url,PHP_URL_HOST)===parse_url($s['origin'],PHP_URL_HOST))return $id;return null;}

final class Store {
 public string $dir;
 public function __construct(?string $dir=null){$this->dir=$dir??__DIR__.'/data';if(!is_dir($this->dir))mkdir($this->dir,0700,true);}
 public function path(string $name):string{return $this->dir.'/'.$name.'.php';}
 public function read(string $name, array $default=[]):array {
  $p=$this->path($name);if(!is_file($p))return $default;
  $s=file_get_contents($p);if($s===false)return $default;
  $raw=substr($s,strpos($s,"\n")+1);$v=json_decode($raw,true);return is_array($v)?$v:$default;
 }
 public function write(string $name,array $data):void {
  $p=$this->path($name);$tmp=$p.'.'.bin2hex(random_bytes(5)).'.tmp';
  if(file_put_contents($tmp,"<?php http_response_code(404); exit; ?>\n".json($data),LOCK_EX)===false)fail('Le dossier data doit être accessible en écriture.');
  chmod($tmp,0600);if(!rename($tmp,$p))fail('Enregistrement impossible.');
 }
 public function lock(string $name,bool $wait=true):mixed {
  $h=fopen($this->dir.'/'.$name.'.lock','c');if(!$h || !flock($h,LOCK_EX|($wait?0:LOCK_NB))){if($h)fclose($h);return false;}return $h;
 }
 public static function unlock(mixed $h):void{if(is_resource($h)){flock($h,LOCK_UN);fclose($h);}}
 public function initial():array {
  $state=['schema'=>1,'version'=>VERSION,'createdAt'=>now(),'offers'=>[],'tasks'=>[],'events'=>[],'logs'=>[],'sources'=>[],'lastRun'=>null,'lastScheduledRun'=>null,'discoveryTurns'=>0];
  foreach(sources() as $id=>$source){
   $state['sources'][$id]=['name'=>$source['name'],'status'=>'waiting','lastSuccess'=>null,'lastError'=>null,'pauseUntil'=>0,'discovered'=>0,'accepted'=>0,'rejected'=>0];
   foreach($source['categories'] as $cat=>$paths)foreach($paths as $path)Runner::task($state,$id,$source['origin'].$path,$cat,'discovery');
  }
  $first=['alternate:cpu','pccomponentes:gpu','ldlc:motherboard','materiel:ram','alternate:ssd','pccomponentes:psu','ldlc:case','materiel:cooler'];
  uasort($state['tasks'],fn($a,$b)=>(array_search($a['source'].':'.$a['category'],$first,true)===false?99:array_search($a['source'].':'.$a['category'],$first,true))<=>(array_search($b['source'].':'.$b['category'],$first,true)===false?99:array_search($b['source'].':'.$b['category'],$first,true)));
  // Adresses de démarrage, sans aucun prix en dur. La découverte prend ensuite le relais.
  Runner::task($state,'alternate','https://www.alternate.fr/AMD/Ryzen-5-5600-3-5-GHz-4-4-GHz-Turbo-Boost-socket-AM4-processeur/html/product/1831263','cpu','product');
  Runner::task($state,'pccomponentes','https://www.pccomponentes.fr/processeurs-amd-ryzen-5-5600-processeur-3-5-ghz-32-mo-l3-boite','cpu','product');
  Runner::task($state,'ldlc','https://www.ldlc.com/fiche/PB00460057.html','psu','product');
  return $state;
 }
}

final class Net {
 private Store $store; private array $robots=[]; private array $last=[];
 public function __construct(Store $store){$this->store=$store;$this->robots=$store->read('robots');}
 private function request(string $url,string $source):string {
  if(!function_exists('curl_init'))fail('L’extension PHP cURL est nécessaire sur cet hébergement.');
  $url=canonicalUrl($url,$source);$bytes='';$headers=[];
  for($redirect=0;$redirect<4;$redirect++){
   $ch=curl_init($url);if(!$ch)fail('Connexion impossible.');
   curl_setopt_array($ch,[CURLOPT_USERAGENT=>UA,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml','Accept-Language: fr-FR,fr;q=0.9','Cache-Control: no-cache'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_ENCODING=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$headers){$p=explode(':',$line,2);if(count($p)===2)$headers[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$bytes){if(strlen($bytes)+strlen($chunk)>7000000)return 0;$bytes.=$chunk;return strlen($chunk);}]);
   $bytes='';$headers=[];$ok=curl_exec($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);unset($ch);
   if($ok===false)fail('Connexion indisponible : '.substr($error,0,130));
   if(in_array($code,[301,302,303,307,308],true) && isset($headers['location'])){$url=canonicalUrl($headers['location'],$source,$url);continue;}
   if($code===403 || $code===429)fail('Accès automatisé refusé (HTTP '.$code.'). Aucun contournement tenté.');
   if($code===404)fail('Fiche introuvable (HTTP 404).');
   if($code!==200)fail('La boutique répond HTTP '.$code.'.');
   if(preg_match('~(?:cf-chl-|/captcha/|<title>\s*(?:Access Denied|Just a moment))~i',$bytes))fail('Protection anti-robots rencontrée.');
   return $bytes;
  }
  fail('Trop de redirections.');
 }
 public static function robotsAllowed(string $text,string $url):bool {
  $groups=[];$agents=[];$rules=[];$inRules=false;
  foreach(preg_split('/\R/',$text) as $line){
   $line=trim(explode('#',$line,2)[0]);if(!str_contains($line,':'))continue;
   [$key,$v]=array_map('trim',explode(':',$line,2));$key=strtolower($key);
   if($key==='user-agent'){
    if($inRules){$groups[]=['agents'=>$agents,'rules'=>$rules];$agents=[];$rules=[];$inRules=false;}
    $agents[]=strtolower($v);
   }elseif(in_array($key,['allow','disallow'],true)&&$agents){$rules[]=[$key,$v];$inRules=true;}
  }
  if($agents)$groups[]=['agents'=>$agents,'rules'=>$rules];
  $selected=[];$specific=false;
  foreach($groups as $g)foreach($g['agents'] as $agent)if($agent!=='*' && str_contains(strtolower(UA),$agent)){$selected=array_merge($selected,$g['rules']);$specific=true;break;}
  if(!$specific)foreach($groups as $g)if(in_array('*',$g['agents'],true))$selected=array_merge($selected,$g['rules']);
  $path=(parse_url($url,PHP_URL_PATH)?:'/').(parse_url($url,PHP_URL_QUERY)?'?'.parse_url($url,PHP_URL_QUERY):'');$best=-1;$allow=true;
  foreach($selected as [$kind,$pattern]){
   if($pattern==='')continue;
   $end=str_ends_with($pattern,'$');if($end)$pattern=substr($pattern,0,-1);
   $rx='~^'.str_replace('\\*','.*',preg_quote($pattern,'~')).($end?'$':'').'~';
   if(preg_match($rx,$path) && (strlen($pattern)>$best || (strlen($pattern)===$best && $kind==='allow'))){$best=strlen($pattern);$allow=$kind==='allow';}
  }return $allow;
 }
 public function get(string $url,string $source):string {
  $url=canonicalUrl($url,$source);$robot=$this->robots[$source]??null;
  if(!$robot || ($robot['at']??0)<time()-86400){
   try{$text=$this->request(sources()[$source]['origin'].'/robots.txt',$source);}
   catch(\Throwable $e){if(str_contains($e->getMessage(),'HTTP 404'))$text='';else throw $e;}
   $robot=['at'=>time(),'text'=>$text];$this->robots[$source]=$robot;$this->store->write('robots',$this->robots);
  }
  if(!self::robotsAllowed($robot['text'],$url))fail('Cette rubrique refuse la collecte dans robots.txt.');
  $interval=1.8;if(preg_match('/Crawl-delay:\s*(\d+)/i',$robot['text'],$m))$interval=max($interval,min(60,(int)$m[1]));
  $delay=$interval-(microtime(true)-($this->last[$source]??0));if($delay>0)usleep((int)($delay*1000000));
  $this->last[$source]=microtime(true);return $this->request($url,$source);
 }
}

final class Page {
 public \DOMDocument $dom; public \DOMXPath $xp; public array $blocks=[];
 public function __construct(public string $html){
  if(!class_exists('DOMDocument'))fail('L’extension PHP DOM/XML est nécessaire.');
  $this->dom=new \DOMDocument();$old=libxml_use_internal_errors(true);$this->dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($old);$this->xp=new \DOMXPath($this->dom);
  foreach($this->xp->query('//script[contains(@type,"ld+json")]') as $el){$d=json_decode($el->textContent,true);if(is_array($d))$this->blocks[]=$d;}
 }
 public function text(string $path):string{$n=$this->xp->query($path);return $n&&$n->length?clean($n->item(0)->textContent):'';}
 public function attr(string $path,string $attr):string{$n=$this->xp->query($path);return $n&&$n->length?clean($n->item(0)->getAttribute($attr)):'';}
 public static function typed(array $node,string $type):bool{$t=$node['@type']??'';return is_array($t)?in_array(strtolower($type),array_map('strtolower',$t),true):strtolower((string)$t)===strtolower($type);}
 public static function nodes(array $node,string $type):array {
  $out=[];if(self::typed($node,$type))$out[]=$node;
  // Traverser les graphes, jamais les avis, recommandations ou caractéristiques.
  foreach(['@graph','itemListElement'] as $k)if(isset($node[$k])&&is_array($node[$k]))foreach($node[$k] as $v)if(is_array($v))$out=array_merge($out,self::nodes($v,$type));
  if(isset($node['item'])&&is_array($node['item']))$out=array_merge($out,self::nodes($node['item'],$type));
  if(array_is_list($node))foreach($node as $v)if(is_array($v))$out=array_merge($out,self::nodes($v,$type));
  return $out;
 }
 public function products():array{$out=[];foreach($this->blocks as $b)$out=array_merge($out,self::nodes($b,'Product'));return $out;}
 public function primary():array {
  $direct=[];foreach($this->blocks as $b){
   if(self::typed($b,'Product')){$direct[]=$b;}
   elseif(array_is_list($b)){foreach($b as $n)if(is_array($n)&&self::typed($n,'Product'))$direct[]=$n;}
   elseif(isset($b['@graph'])){foreach($b['@graph'] as $n)if(is_array($n)&&self::typed($n,'Product'))$direct[]=$n;}
  }
  if(count($direct)!==1)fail('La fiche ne fournit pas un produit principal identifiable.');return $direct[0];
 }
 public function pccData():array {
  $start=strpos($this->html,'window.__staticRouterHydrationData');if($start===false)return [];
  $start=strpos($this->html,'JSON.parse(',$start);if($start===false)return [];
  $start+=strlen('JSON.parse(');while(isset($this->html[$start])&&str_contains(" \t\r\n",$this->html[$start]))$start++;
  if(($this->html[$start]??'')!=='"')return [];
  $end=$start+1;$escaped=false;
  for($length=strlen($this->html);$end<$length;$end++){
   $c=$this->html[$end];if(!$escaped&&$c==='"')break;
   if(!$escaped&&$c==='\\')$escaped=true;else $escaped=false;
  }
  $str=json_decode(substr($this->html,$start,$end-$start+1),true);if(!is_string($str))return [];$d=json_decode($str,true);
  return is_array($d['loaderData']['dynamicView']['dynamicData']??null)?$d['loaderData']['dynamicView']['dynamicData']:[];
 }
 public function discover(string $url,string $source):array {
  $links=[];foreach($this->products() as $p)if(is_string($p['url']??null))$links[]=$p['url'];
  if(!$links && $source!=='pccomponentes')foreach($this->xp->query('//a[@href]') as $a)if(preg_match(sources()[$source]['productPattern'],parse_url($a->getAttribute('href'),PHP_URL_PATH)?:''))$links[]=$a->getAttribute('href');
  $out=[];foreach($links as $u){try{$u=canonicalUrl($u,$source,$url);$path=parse_url($u,PHP_URL_PATH)?:'';if($source!=='pccomponentes'&&!preg_match(sources()[$source]['productPattern'],$path))continue;$out[$u]=$u;}catch(\Throwable){}}
  $next=$this->attr('//link[@rel="next"]','href')?:$this->attr('//a[@rel="next"]','href');
  if(!$next){
   foreach($this->xp->query('//a[@href and (contains(@class,"next") or contains(@aria-label,"suivante") or contains(@title,"suivante") or contains(translate(@aria-label,"NEXT","next"),"next"))]') as $a){$v=$a->getAttribute('href');if($v && $v!=='#'){$next=$v;break;}}
  }
  if($next){try{$next=canonicalUrl($next,$source,$url);}catch(\Throwable){$next='';}}
  return ['urls'=>array_values($out),'next'=>$next];
 }
}

final class Parser {
 public static function offers(array $offers):array {
  if(array_is_list($offers))return $offers;
  if(Page::typed($offers,'AggregateOffer'))return isset($offers['offers'])&&is_array($offers['offers'])?self::offers($offers['offers']):[];
  return Page::typed($offers,'Offer')?[$offers]:[];
 }
 public static function specs(Page $page,array $p,string $category):array {
  $description=clean((string)($p['description']??''));$name=clean((string)($p['name']??''));$explicit=$description.' '.valueName($p['category']??'').' '.$name;
  $rows=[];foreach($page->xp->query('//tr') as $tr){$td=$tr->getElementsByTagName('td');if($td->length>=2){$label=clean($td->item($td->length-2)->textContent);$value=clean($td->item($td->length-1)->textContent);if(strlen($label)<70 && strlen($value)<180 && $label && $value)$rows[$label]=$value;}}
  $meta=[];
  foreach($rows as $k=>$v)if(preg_match('/socket|support.*processeur|type.*mémoire|interface|capacité|format|puissance|norme.*mémoire/i',$k))$explicit.=' '.$k.' '.$v;
  if(in_array($category,['cpu','motherboard','cooler'],true)&&preg_match('/\b(AM[345]|sTR5|sTRX4|TR4|LGA[ -]?(?:1150|1151|1200|1700|1851|2066|2011))\b/i',$explicit,$m))$meta['platform']=strtoupper(str_replace([' ','-'],'',$m[1]));
  if(!isset($meta['platform'])&&in_array($category,['cpu','motherboard'],true)&&preg_match('/(?:socket|sockel)\s*(1150|1151|1200|1700|1851|2066|2011)\b/i',$explicit,$m))$meta['platform']='LGA'.$m[1];
  if($category==='ram'&&preg_match('/\bDDR([345])\b/i',$explicit,$m))$meta['memory']='DDR'.$m[1];
  if($category==='ssd'){
   if(preg_match('/\b(?:PCI[eE-]?(?:xpress)?|PCI Express)\s*([345])(?:\.0)?/i',$explicit,$m))$meta['interface']='PCIe '.$m[1].'.0';
   elseif(preg_match('/SATA/i',$explicit))$meta['interface']='SATA';
   if(preg_match('/\b(\d+(?:[.,]\d+)?)\s*(To|TB|Go|GB)\b/i',$name,$m))$meta['capacity']=$m[1].' '.str_ireplace(['TB','GB'],['To','Go'],$m[2]);
  }
  if($category==='psu'&&preg_match('/\b(\d{3,4})\s*(?:W|Watts)\b/i',$explicit,$m))$meta['power']=$m[1].' W';
  if(in_array($category,['motherboard','case'],true)&&preg_match('/\b(micro[ -]?ATX|mATX|mini[ -]?ITX|E[ -]?ATX|ATX)\b/i',$explicit,$m))$meta['format']=$m[1];
  if($category==='ram'&&preg_match('/\b(\d+)\s*(Go|GB)\b/i',$name,$m))$meta['capacity']=$m[1].' Go';
  return $meta;
 }
 public static function parse(string $html,string $url,string $source,string $category):array {
  $page=new Page($html);$p=$page->primary();$name=clean((string)($p['name']??''));
  if(!$name || strlen($name)>450)fail('Nom du produit absent.');
  if(preg_match('/occasion|reconditionn|remis.{0,5}neuf|refurb|renewed|open[ -]box|\bused\b|seconde main/i',$name))fail('État exclu : occasion, reconditionné ou équivalent.');
  $brand=trim(str_replace(['®','™'],'',valueName($p['brand']??'')));$mpn=clean((string)($p['mpn']??$p['model']??''));$ean='';
  foreach(['gtin13','gtin14','gtin12','gtin8','gtin'] as $k)if($e=gtin($p[$k]??'')){$ean=$e;break;}
  if(!$ean && (!$mpn || !$brand))fail('Référence fabricant et code produit insuffisants.');
  $specs=self::specs($page,$p,$category);
  if(in_array($category,['cpu','motherboard'],true)&&in_array($specs['platform']??'',['AM3','AM4X','LGA1150','LGA2011'],true))fail('Plateforme antérieure au périmètre retenu.');
  if($category==='cpu' && !$specs && preg_match('/\bFX[- ]|Athlon II|Phenom|Core 2\b/i',$name))fail('Gamme antérieure à la période retenue.');
  if($category==='ram'&&($specs['memory']??'')==='DDR3')fail('Mémoire DDR3 hors périmètre.');
  if($category==='gpu' && preg_match('/\b(?:GTX\s*[4-9]\d{2}|GT\s*[4-9]\d{2}|Radeon\s*(?:HD|R[579])\b)/i',$name))fail('Gamme graphique antérieure au périmètre retenu.');
  $rawOffers=self::offers(is_array($p['offers']??null)?$p['offers']:[]);if(!$rawOffers)fail('Aucune offre individuelle fournie par la boutique.');
  $accepted=[];
  foreach($rawOffers as $offer){
   $currency=$offer['priceCurrency']??$offer['priceSpecification']['priceCurrency']??'';
   if($currency!=='EUR')continue;
   $condition=(string)($offer['itemCondition']??$p['itemCondition']??'');if(!str_ends_with($condition,'NewCondition'))continue;
   if(!str_ends_with((string)($offer['availability']??''),'InStock'))continue;
   if(isset($offer['priceValidUntil']) && strtotime($offer['priceValidUntil'].' 23:59:59 UTC')<time())continue;
   $price=cents($offer['price']??$offer['priceSpecification']['price']??null);if(!$price)continue;
   $seller=valueName($offer['seller']??$offer['offeredBy']??'');
   if(!in_array(norm($seller),array_map(__NAMESPACE__.'\\norm',sources()[$source]['sellers']),true))continue;
   $stock=null;$evidence='Données produit structurées de la boutique';$promotional=false;
   if($source==='pccomponentes'){
    $data=$page->pccData();
    if(($data['isNew']??false)!==true || ($data['condition']??'')!=='new' || ($data['isWinnerPCC']??false)!==true || ($data['unPurchasable']??true)===true)continue;
    if(cents($data['price']??null)!==$price)continue;
    $matched=false;foreach($data['offers']??[] as $o){
     if(norm((string)($o['shopName']??''))===norm($seller) && ($o['condition']??'')==='new' && ($o['isAvailable']??false)===true && ($o['availability']['status']??'')==='inStock' && cents($o['totalPrice']??$o['price']??null)===$price){$matched=true;$stock=is_numeric($o['availability']['realStock']??null)?(int)$o['availability']['realStock']:null;break;}
    }if(!$matched || ($stock!==null && $stock<=0))continue;
    $visible=$page->text('//*[@id="pdp-price-current-container"]');if($visible && cents($visible)!==$price)continue;
    $evidence='Prix et vendeur recoupés avec la fiche visible et son offre principale';
   }
   if($source==='ldlc'){
    if($page->attr('//*[@data-offer-is-marketplace]','data-offer-is-marketplace')!=='0')continue;
    if($page->attr('//*[@data-product-is-secondhand]','data-product-is-secondhand')!=='0')continue;
    if(norm($page->attr('//h1[@data-seller]','data-seller'))!==norm($seller))continue;
    if(cents($page->attr('//*[@id="productPriceStock"]','data-price'))!==$price)continue;
    $evidence='Prix, neuf et vendeur recoupés avec la fiche LDLC';
   }
   if($source==='materiel'){
    $visible=$page->attr('//*[@id="productPriceStock"]','data-price');
    if(!$visible)$visible=$page->text('(//span[contains(concat(" ",normalize-space(@class)," ")," o-product__price ")])[1]');
    if($visible && cents($visible)!==$price)continue;
    $evidence='Prix recoupé avec la fiche visible et offre structurée neuve du vendeur';
   }
   if($source==='alternate'){
    $visible=$page->text('(//span[contains(concat(" ",normalize-space(@class)," ")," price ")])[1]');
    if($visible && cents($visible)!==$price)continue;
    $evidence='Prix recoupé avec la fiche visible et offre structurée neuve du vendeur';
   }
   $offerUrl=canonicalUrl((string)($offer['url']??$p['url']??$url),$source,$url);
   // Ne jamais associer un lien d’une autre variante au prix de cette fiche.
   if(parse_url($offerUrl,PHP_URL_PATH)!==parse_url(canonicalUrl($url,$source),PHP_URL_PATH))continue;
   $image=$p['image']??'';if(is_array($image))$image=$image[0]??$image['url']??'';if(is_array($image))$image=$image['url']??'';
   $image=is_string($image)&&str_starts_with($image,'https://')?$image:'';
   $key=$ean?'gtin:'.$ean:'mpn:'.norm($brand).':'.norm($mpn);
   $accepted[]=['productId'=>$key,'name'=>$name,'brand'=>$brand,'mpn'=>$mpn,'gtin'=>$ean,'category'=>$category,'specs'=>$specs,'source'=>$source,'merchant'=>sources()[$source]['name'],'seller'=>$seller,'sellerVerified'=>true,'condition'=>'new','availability'=>'in_stock','stockQuantity'=>$stock,'priceCents'=>$price,'currency'=>'EUR','url'=>$offerUrl,'image'=>$image,'checkedAt'=>now(),'checkedEpoch'=>time(),'evidence'=>$evidence,'status'=>'active','shipping'=>'excluded','history'=>[]];
  }
  if(!$accepted)fail('Offre écartée : prix, neuf, stock ou vendeur non recoupés.');
  usort($accepted,fn($a,$b)=>$a['priceCents']<=>$b['priceCents']);return $accepted[0];
 }
}

final class Runner {
 public function __construct(private Store $store){}
 public static function orderTasks(array $a,array $b,array $state,bool $wantDiscovery):int {
  $aa=($a['kind']==='discovery')===$wantDiscovery?0:1;$bb=($b['kind']==='discovery')===$wantDiscovery?0:1;
  if($aa!==$bb)return $aa<=>$bb;
  if($a['kind']==='product'&&$b['kind']==='product'){
   // Les nouvelles fiches ne doivent jamais affamer les prix déjà suivis.
   $preferRefresh=($state['discoveryTurns']??0)%4!==0;
   $ao=$state['offers'][$a['id']]??[];$bo=$state['offers'][$b['id']]??[];
   $rank=function($o)use($preferRefresh){if(($o['status']??'')==='quarantined')return -1;$refresh=isset($o['priceCents']);return $refresh===$preferRefresh?0:1;};
   if(($rank($ao)<=>$rank($bo))!==0)return $rank($ao)<=>$rank($bo);
   $ca=$state['processedCategories'][$a['category']]??0;$cb=$state['processedCategories'][$b['category']]??0;if($ca!==$cb)return $ca<=>$cb;
   if($a['category']===$b['category']&&in_array($a['category'],['cpu','gpu'],true)){
    $va=silicon($ao);$vb=silicon($bo);
    if($va&&$vb){$vaCount=$state['processedSilicon'][$a['category'].':'.$va]??0;$vbCount=$state['processedSilicon'][$b['category'].':'.$vb]??0;if($vaCount!==$vbCount)return $vaCount<=>$vbCount;}
   }
   $sa=$state['processedSources'][$a['source']]??0;$sb=$state['processedSources'][$b['source']]??0;if($sa!==$sb)return $sa<=>$sb;
  }elseif($a['kind']==='discovery'&&$b['kind']==='discovery'){
   $ra=$a['last']!==null?0:1;$rb=$b['last']!==null?0:1;if($ra!==$rb)return $ra<=>$rb;
  }
  return $a['next']<=>$b['next'];
 }
 public static function task(array &$state,string $source,string $url,string $category,string $kind,int $depth=0):void {
  try{$url=canonicalUrl($url,$source);}catch(\Throwable){return;}
  $id=hash('sha256',$url);if(isset($state['tasks'][$id])||count($state['tasks'])>=30000)return;
  $state['tasks'][$id]=['id'=>$id,'source'=>$source,'url'=>$url,'category'=>$category,'kind'=>$kind,'depth'=>$depth,'next'=>0,'failures'=>0,'last'=>null];
 }
 public static function log(array &$state,string $source,string $message,string $level='info'):void {
  array_unshift($state['logs'],['at'=>now(),'source'=>$source,'message'=>substr($message,0,230),'level'=>$level]);$state['logs']=array_slice($state['logs'],0,120);
 }
 public static function apply(array &$state,string $taskId,array $offer):bool {
  $old=$state['offers'][$taskId]??null;
  if($old && ($old['productId']??null)!==$offer['productId']){$state['offers'][$taskId]['status']='rejected';fail('La référence de la fiche a changé : offre retirée.');}
  foreach($state['offers'] as $otherId=>$other){
   if($otherId===$taskId || ($other['productId']??'')!==$offer['productId'])continue;
   if(($other['mpn']??'')!=='' && $offer['mpn']!=='' && norm($other['mpn'])!==norm($offer['mpn']))fail('Code produit associé à deux références fabricant différentes : offre écartée.');
   if(($other['brand']??'')!=='' && $offer['brand']!=='' && norm($other['brand'])!==norm($offer['brand']))fail('Marque incohérente pour ce code produit : offre écartée.');
  }
  if($old && norm($old['seller']??'')!==norm($offer['seller']))$old=null;
  // Une forte variation nécessite deux lectures distinctes avant publication.
  if($old && ($old['priceCents']??0)>0 && abs($offer['priceCents']-$old['priceCents'])/$old['priceCents']>0.45){
   $pending=$old['pending']??[];
   if(($pending['priceCents']??null)!==$offer['priceCents'] || time()-($pending['epoch']??time())<120){
    $state['offers'][$taskId]['status']='quarantined';$state['offers'][$taskId]['pending']=['priceCents'=>$offer['priceCents'],'epoch'=>($pending['priceCents']??null)===$offer['priceCents']?($pending['epoch']??time()):time()];
    $state['tasks'][$taskId]['next']=time()+180;self::log($state,$offer['source'],'Variation inhabituelle mise en attente d’un second relevé.','warning');return false;
   }
  }
  $history=$old['history']??[];
  if(!$history || end($history)['priceCents']!==$offer['priceCents'] || end($history)['epoch']<time()-3600)$history[]=['at'=>$offer['checkedAt'],'epoch'=>time(),'priceCents'=>$offer['priceCents']];
  $offer['history']=array_slice($history,-48);
  if($old && ($old['priceCents']??0)>0 && $old['priceCents']!==$offer['priceCents']){
   $delta=$offer['priceCents']-$old['priceCents'];$event=['id'=>bin2hex(random_bytes(8)),'at'=>now(),'epoch'=>time(),'kind'=>$delta<0?'drop':'rise','name'=>$offer['name'],'productId'=>$offer['productId'],'source'=>$offer['source'],'seller'=>$offer['seller'],'oldCents'=>$old['priceCents'],'newCents'=>$offer['priceCents'],'deltaCents'=>$delta,'percent'=>round(100*$delta/$old['priceCents'],2),'url'=>$offer['url']];
   array_unshift($state['events'],$event);$state['events']=array_slice($state['events'],0,1000);
   $offer['variation']=$event;
  }elseif(isset($old['variation']))$offer['variation']=$old['variation'];
  $state['offers'][$taskId]=$offer;return true;
 }
 public function run(int $maxJobs=8,int $maxSeconds=40,bool $scheduled=false):array {
  $lock=$this->store->lock('sync',false);if(!$lock)return ['busy'=>true,'message'=>'Une actualisation est déjà en cours.'];
  $start=microtime(true);$net=new Net($this->store);$state=$this->store->read('state',$this->store->initial());$report=['processed'=>0,'accepted'=>0,'discovered'=>0,'rejected'=>0,'errors'=>0];
  try{
   while($report['processed']<$maxJobs && microtime(true)-$start<$maxSeconds){
    $due=[];foreach($state['tasks'] as $id=>$t)if($t['next']<=time() && ($state['sources'][$t['source']]['pauseUntil']??0)<=time())$due[$id]=$t;
    if(!$due)break;
    $wantDiscovery=($state['discoveryTurns']??0)%7===0;
    uasort($due,fn($a,$b)=>self::orderTasks($a,$b,$state,$wantDiscovery));
    $id=array_key_first($due);$task=$due[$id];$source=$task['source'];$report['processed']++;$state['discoveryTurns']++;$state['tasks'][$id]['last']=now();
    if($task['kind']==='product'){$state['processedCategories'][$task['category']]=($state['processedCategories'][$task['category']]??0)+1;$state['processedSources'][$source]=($state['processedSources'][$source]??0)+1;$vendor=silicon($state['offers'][$id]??[]);if($vendor)$state['processedSilicon'][$task['category'].':'.$vendor]=($state['processedSilicon'][$task['category'].':'.$vendor]??0)+1;}
    try{
     $html=$net->get($task['url'],$source);
     if($task['kind']==='discovery'){
      $page=new Page($html);$found=$page->discover($task['url'],$source);
      if(!$found['urls'])fail('Aucune fiche produit identifiable dans cette rubrique.');
      $before=count($state['tasks']);foreach($found['urls'] as $u)self::task($state,$source,$u,$task['category'],'product');
      if($found['next'] && $task['depth']<80 && $found['next']!==$task['url'])self::task($state,$source,$found['next'],$task['category'],'discovery',$task['depth']+1);
      $new=count($state['tasks'])-$before;$report['discovered']+=$new;$state['sources'][$source]['discovered']+=$new;
      $state['tasks'][$id]['next']=time()+43200;self::log($state,$source,count($found['urls']).' fiches repérées ; '.$new.' nouvelles adresses.');
     }else{
      $offer=Parser::parse($html,$task['url'],$source,$task['category']);$state['tasks'][$id]['next']=time()+3600;
      if(self::apply($state,$id,$offer)){$report['accepted']++;$state['sources'][$source]['accepted']++;}
     }
     $state['tasks'][$id]['failures']=0;$state['sources'][$source]['status']='ok';$state['sources'][$source]['lastSuccess']=now();$state['sources'][$source]['lastError']=null;
    }catch(\Throwable $e){
     $message=$e->getMessage();$state['tasks'][$id]['failures']++;$state['tasks'][$id]['next']=time()+min(86400,3600*(2**min(4,$state['tasks'][$id]['failures']-1)));
     if(isset($state['offers'][$id]))$state['offers'][$id]['status']='unavailable';
     $blocked=str_contains($message,'refusé')||str_contains($message,'anti-robots');
     if($blocked){$state['sources'][$source]['pauseUntil']=time()+21600;$state['sources'][$source]['status']='blocked';$report['errors']++;}
     elseif(str_starts_with($message,'Connexion')){$state['sources'][$source]['pauseUntil']=time()+900;$state['sources'][$source]['status']='limited';$report['errors']++;}
     else{$state['sources'][$source]['rejected']++;$report['rejected']++;}
     $state['sources'][$source]['lastError']=$message;self::log($state,$source,$message,'warning');
    }
    $state['lastRun']=now();if($scheduled)$state['lastScheduledRun']=now();$this->store->write('state',$state);
   }
   $state['lastRun']=now();if($scheduled)$state['lastScheduledRun']=now();$this->store->write('state',$state);
   return $report+['at'=>now(),'remaining'=>count(array_filter($state['tasks'],fn($t)=>$t['next']<=time())),'duration'=>round(microtime(true)-$start,1)];
  }finally{Store::unlock($lock);}
 }
}

function publishable(array $offer):bool {
 $source=$offer['source']??'';
 return isset(sources()[$source]) && ($offer['status']??'')==='active' && ($offer['checkedEpoch']??0)>=time()-MAX_AGE && ($offer['checkedEpoch']??0)<=time()+60 && ($offer['condition']??'')==='new' && ($offer['sellerVerified']??false)===true && ($offer['availability']??'')==='in_stock' && (!isset($offer['stockQuantity']) || $offer['stockQuantity']>0) && is_int($offer['priceCents']??null) && $offer['priceCents']>0 && in_array(norm($offer['seller']??''),array_map(__NAMESPACE__.'\\norm',sources()[$source]['sellers']),true);
}
function silicon(array $product):string {
 $name=$product['name']??'';$brand=$product['brand']??'';$category=$product['category']??'';
 if($category==='cpu'){if(str_contains(norm($brand),'amd'))return 'AMD';if(str_contains(norm($brand),'intel'))return 'Intel';}
 if($category==='gpu'){
  if(preg_match('/GeForce|Quadro|NVIDIA|\bRTX\b|\bGTX\b/i',$name))return 'NVIDIA';
  if(preg_match('/Radeon/i',$name))return 'AMD';
  if(preg_match('/\bArc\b/i',$name))return 'Intel';
 }
 return '';
}
function publicCatalogue(array $state,array $query=[]):array {
 $products=[];$active=0;$cutoff=time()-MAX_AGE;
 foreach($state['offers']??[] as $offer){
  if(!publishable($offer))continue;
  $active++;$id=$offer['productId'];$products[$id]??=['id'=>$id,'name'=>$offer['name'],'brand'=>trim(str_replace(['®','™'],'',$offer['brand'])),'mpn'=>$offer['mpn'],'gtin'=>$offer['gtin'],'category'=>$offer['category'],'specs'=>$offer['specs'],'image'=>$offer['image'],'offers'=>[]];$products[$id]['offers'][]=$offer;
 }
 foreach($products as &$product){usort($product['offers'],fn($a,$b)=>$a['priceCents']<=>$b['priceCents']);$product['best']=$product['offers'][0];}unset($product);
 $totalProducts=count($products);
 $brands=array_values(array_unique(array_filter(array_column($products,'brand'))));natcasesort($brands);$brands=array_values($brands);
 $knownReferences=count(array_unique(array_column($state['offers']??[],'productId')));
 $dropCount=count(array_filter($products,fn($p)=>(($p['best']['variation']['kind']??'')==='drop'&&($p['best']['variation']['epoch']??0)>=time()-604800)));
 $counts=array_fill_keys(array_keys(CATEGORIES),0);foreach($products as $p)$counts[$p['category']]++;
 $q=norm((string)($query['q']??''));$cat=(string)($query['category']??'all');$source=(string)($query['source']??'all');$platform=(string)($query['platform']??'all');
 if($source!=='all')foreach($products as $id=>&$product){
  $product['offers']=array_values(array_filter($product['offers'],fn($o)=>$o['source']===$source));
  if(!$product['offers']){unset($products[$id]);continue;}$product['best']=$product['offers'][0];
 }unset($product);
 $brand=(string)($query['brand']??'all');$silicon=(string)($query['silicon']??'all');$onlyDrops=!empty($query['drops']);
 $items=array_values(array_filter($products,function($p)use($q,$cat,$source,$platform,$onlyDrops,$brand,$silicon){
  if($cat!=='all'&&$p['category']!==$cat)return false;
  if($brand!=='all'&&norm($p['brand'])!==norm($brand))return false;
  if($silicon!=='all'&&silicon($p)!==$silicon)return false;
  if($q&&!str_contains(norm($p['name'].' '.$p['brand'].' '.$p['mpn'].' '.$p['gtin']),$q))return false;
  if($source!=='all'&&!array_filter($p['offers'],fn($o)=>$o['source']===$source))return false;
  if($platform!=='all'&&!in_array($platform,$p['specs'],true))return false;
  if($onlyDrops && (($p['best']['variation']['kind']??'')!=='drop' || ($p['best']['variation']['epoch']??0)<time()-604800))return false;
  return true;
 }));
 $sort=$query['sort']??'recent';usort($items,match($sort){'price'=>fn($a,$b)=>$a['best']['priceCents']<=>$b['best']['priceCents'],'price_desc'=>fn($a,$b)=>$b['best']['priceCents']<=>$a['best']['priceCents'],'drop'=>fn($a,$b)=>($a['best']['variation']['percent']??0)<=>($b['best']['variation']['percent']??0),default=>fn($a,$b)=>$b['best']['checkedEpoch']<=>$a['best']['checkedEpoch']});
 $events=array_values(array_filter($state['events']??[],fn($e)=>isset($products[$e['productId']??''])));
 $sourceStates=[];foreach(sources() as $id=>$s){$st=$state['sources'][$id]??[];$sourceStates[]=['id'=>$id,'name'=>$s['name'],'url'=>$s['origin'],'status'=>$st['status']??'waiting','lastSuccess'=>$st['lastSuccess']??null,'offers'=>count(array_filter($state['offers']??[],fn($o)=>$o['source']===$id&&publishable($o)))];}
 $page=max(1,(int)($query['page']??1));$limit=36;
 return ['version'=>VERSION,'at'=>now(),'lastRun'=>$state['lastRun']??null,'brands'=>$brands,'knownReferences'=>$knownReferences,'statistics'=>['drops'=>$dropCount],'total'=>$totalProducts,'offers'=>$active,'matched'=>count($items),'categories'=>CATEGORIES,'counts'=>$counts,'items'=>array_slice($items,($page-1)*$limit,$limit),'page'=>$page,'hasMore'=>count($items)>$page*$limit,'events'=>array_slice($events,0,80),'sources'=>$sourceStates,'scope'=>'Références découvertes dans les catalogues des boutiques connectées ; couverture non exhaustive.','freshnessSeconds'=>MAX_AGE];
}
