'use strict';
const $ = (s) => document.querySelector(s);
const categories = {all:'Toutes les pièces',cpu:'Processeurs',gpu:'Cartes graphiques',motherboard:'Cartes mères',ram:'Mémoire RAM',ssd:'SSD',psu:'Alimentations',case:'Boîtiers',cooler:'Refroidissement'};
const query = {category:'all',source:'all',brand:'all',platform:'all',silicon:'all',q:'',sort:'recent',page:1};
const products = new Map();
let localMode=true, connectionFailure='', remoteLoadedAt=0;
let archiveData=globalThis.MATTECH_ARCHIVE||null;
let currentProductId=null;
let session={admin:false,csrf:''}, snapshot=null, tab='catalogue', syncing=false, stop=false, requestNumber=0, previousFocus=null, searchTimer;
const esc=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const euro=(c)=>new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(c/100);
const stamp=(v)=>v?new Date(v).toLocaleString('fr-FR',{dateStyle:'short',timeStyle:'short'}):'Aucun relevé';
function ago(v){const seconds=Math.max(0,(Date.now()-new Date(v).getTime())/1000);return seconds<60?'à l’instant':seconds<3600?`il y a ${Math.floor(seconds/60)} min`:seconds<86400?`il y a ${Math.floor(seconds/3600)} h`:stamp(v);}
function url(v){try{const u=new URL(String(v));return u.protocol==='https:'?esc(u.href):'';}catch{return '';}}
function notice(text,error=false){$('#noticeText').textContent=text;$('#notice').classList.toggle('error',error);}
async function refreshRemote(force=false){
 const address=globalThis.MATTECH_DATA_URL||'';
 if(!address){connectionFailure='Le catalogue attend le lien de ton dépôt GitHub. Ouvre le guide fourni pour terminer cette installation unique.';return;}
 if(!force&&remoteLoadedAt&&Date.now()-remoteLoadedAt<60000)return;
 let target;try{target=new URL(address);}catch{throw Error('Le lien du catalogue n’est pas valide.');}
 if(target.protocol!=='https:'||target.hostname!=='raw.githubusercontent.com'||target.username||target.password)throw Error('Le catalogue doit provenir du lien GitHub public configuré.');
 target.searchParams.set('v',Math.floor(Date.now()/300000));
 const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),15000);
 try{
  const response=await fetch(target,{cache:'no-store',credentials:'omit',signal:controller.signal});
  if(!response.ok)throw Error('Le fichier du catalogue n’est pas accessible (HTTP '+response.status+').');
  const data=await response.json();
  if(data.schema!==1||!Array.isArray(data.products)||!Array.isArray(data.sources))throw Error('Le fichier reçu n’est pas un catalogue MAT-TECH valide.');
  archiveData=data;remoteLoadedAt=Date.now();connectionFailure='';
 }catch(error){connectionFailure='La dernière publication n’est pas accessible. Les relevés enregistrés restent consultables avec leur date ; les prix anciens sont masqués.';if(!archiveData)throw error;}
 finally{clearTimeout(timer);}
}
async function api(action,body,params={}){
 if(action==='session')return {admin:false,csrf:''};
 if(action==='state'){await refreshRemote();return localState(params);}
 throw Error('Les relevés sont exécutés par le programme GitHub Actions.');
}
function localState(params={}){
 if(!archiveData)throw Error('Les relevés enregistrés sont absents de cette page.');
 const all=[];let activeOffers=0;const cutoff=Date.now()/1000-10800;
 for(const raw of archiveData.products){const p=JSON.parse(JSON.stringify(raw));const fresh=p.offers.filter(o=>o.checkedEpoch>=cutoff&&o.checkedEpoch<=Date.now()/1000+60&&o.status==='active');
  p.expired=!fresh.length;p.offers=fresh.length?fresh:p.offers.slice().sort((a,b)=>b.checkedEpoch-a.checkedEpoch);if(!p.offers.length)continue;
  p.offers.sort((a,b)=>p.expired?b.checkedEpoch-a.checkedEpoch:a.priceCents-b.priceCents);p.best=p.offers[0];activeOffers+=fresh.length;all.push(p);
 }
 const counts=Object.fromEntries(Object.keys(categories).filter(k=>k!=='all').map(k=>[k,all.filter(p=>p.category===k).length]));
 const normal=v=>String(v||'').normalize('NFD').replace(/[\u0300-\u036f®™]/g,'').toLowerCase().replace(/[^a-z0-9]/g,'');
 const vendor=p=>p.category==='cpu'?(/amd/i.test(p.brand)?'AMD':/intel/i.test(p.brand)?'Intel':''):p.category==='gpu'?(/GeForce|NVIDIA|RTX|GTX|Quadro/i.test(p.name)?'NVIDIA':/Radeon/i.test(p.name)?'AMD':/Arc/i.test(p.name)?'Intel':''):'';
 let items=all.filter(p=>(!params.id||p.id===params.id)&&(!params.category||params.category==='all'||p.category===params.category)&&(!params.brand||params.brand==='all'||normal(p.brand)===normal(params.brand))&&(!params.platform||params.platform==='all'||Object.values(p.specs||{}).includes(params.platform))&&(!params.silicon||params.silicon==='all'||vendor(p)===params.silicon)&&(!params.q||normal(p.name+' '+p.brand+' '+p.mpn+' '+p.gtin).includes(normal(params.q))));
 if(params.source&&params.source!=='all')items=items.flatMap(p=>{const offers=p.offers.filter(o=>o.source===params.source);return offers.length?[{...p,offers,best:offers[0]}]:[];});
 if(Number(params.drops))items=items.filter(p=>!p.expired&&p.best.variation?.kind==='drop');
 if(params.sort==='price')items.sort((a,b)=>(a.expired?Infinity:a.best.priceCents)-(b.expired?Infinity:b.best.priceCents));
 else if(params.sort==='price_desc')items.sort((a,b)=>(b.expired?-Infinity:b.best.priceCents)-(a.expired?-Infinity:a.best.priceCents));
 else if(params.sort==='drop')items.sort((a,b)=>(a.best.variation?.percent||0)-(b.best.variation?.percent||0));
 else items.sort((a,b)=>b.best.checkedEpoch-a.best.checkedEpoch);
 const page=Math.max(1,Number(params.page)||1),sources=archiveData.sources.map(s=>({...s,status:s.status||'waiting',offers:all.reduce((n,p)=>n+(p.expired?0:p.offers.filter(o=>o.source===s.id).length),0)}));
 return{...archiveData,admin:false,offline:true,total:all.length,knownReferences:all.length,offers:activeOffers,counts,brands:[...new Set(all.map(p=>p.brand).filter(Boolean))].sort(),matched:items.length,items:items.slice((page-1)*36,page*36),page,hasMore:items.length>page*36,sources,events:archiveData.events||[],statistics:{drops:all.filter(p=>!p.expired&&p.best.variation?.kind==='drop').length}};
}
function localNotice(){
 $('#referenceLabel').textContent='références découvertes';$('#sourceLabel').textContent='boutiques connectées';$('#sourceCount').textContent=archiveData?.sources?.length||0;
 notice(connectionFailure||('Dernier passage du programme : '+stamp(archiveData?.lastRun)+'. Chaque offre conserve sa propre date de relevé. Les montants de plus de 3 heures sont masqués.'));
 $('#connection').textContent=connectionFailure?'Dernière publication à vérifier':'Catalogue synchronisé';
 $('#refresh').textContent='↻ Vérifier les dernières offres';$('#refreshNote').textContent='Relevés automatiques par lots · prix datés';
}
function movement(v){if(!v||!Number.isFinite(v.percent))return '';return `<span class="move ${v.kind==='rise'?'up':''}" title="Variation observée le ${esc(stamp(v.at))}">${v.kind==='drop'?'↘':'↗'} ${esc(Math.abs(v.percent).toLocaleString('fr-FR',{maximumFractionDigits:2}))} %</span>`;}
function categoryNavigation(){
 const counts=snapshot?.counts||{};
 $('#catNav').innerHTML=Object.entries(categories).map(([k,v])=>`<button class="${query.category===k?'active':''}" data-cat="${k}">${v}<b>${esc(k==='all'?snapshot?.total??0:counts[k]??0)}</b></button>`).join('');
 $('#chips').innerHTML=Object.entries(categories).map(([k,v])=>`<button class="${query.category===k?'active':''}" data-cat="${k}">${v}</button>`).join('');
 $('#siliconChips').querySelectorAll('button').forEach(b=>b.classList.toggle('active',b.dataset.silicon===query.silicon));
}
function card(p){const b=p.best, photo=url(p.image);return `<article class="card"><div class="visual ${esc(p.category)} ${photo?'has-image':''}" data-open="${esc(p.id)}" role="button" tabindex="0" aria-label="Voir ${esc(p.name)}"><span class="type">${esc(categories[p.category])}</span><span class="new">● NEUF</span>${photo?`<img src="${photo}" alt="${esc(p.name)}" loading="lazy" referrerpolicy="no-referrer">`:''}</div><div class="card-body"><div class="brand-line">${esc(p.brand||b.merchant)}</div><h3 class="name"><button data-open="${esc(p.id)}">${esc(p.name)}</button></h3><div class="specs">${Object.values(p.specs||{}).slice(0,4).map(s=>`<span>${esc(s)}</span>`).join('')||`<span>Réf. ${esc(p.mpn||p.gtin)}</span>`}</div><div class="price-row"><div><div class="price">${p.expired?'Prix à actualiser':esc(euro(b.priceCents))}</div><small class="caption">${p.expired?'Ancien relevé · montant masqué':'TTC · hors livraison'}</small></div>${p.expired?'':movement(b.variation)}</div><div class="seller"><span><i></i>${esc(b.seller)}</span>${p.offers.length>1?`<a href="#" data-open="${esc(p.id)}">${p.offers.length} offres ↗</a>`:`<small>${p.expired?'Stock à recontrôler':'Stock déclaré au relevé'}</small>`}</div><a class="buy" href="${url(b.url)}" target="_blank" rel="noopener noreferrer">Voir chez ${esc(b.merchant)} <span>↗</span></a><div class="meta"><span title="${esc(stamp(b.checkedAt))}">Relevé ${esc(ago(b.checkedAt))}</span><button data-open="${esc(p.id)}">Détails & historique ↗</button></div></div></article>`;}
function fillSelect(node,entries,current,label){node.innerHTML=`<option value="all">${label}</option>`+entries.map(([v,name])=>`<option value="${esc(v)}">${esc(name)}</option>`).join('');node.value=current;}
async function load(append=false){
 const number=++requestNumber;
 try{
  const data=await api('state',null,{...query,drops:tab==='drops'?1:0});if(number!==requestNumber)return;
  snapshot=data;session.admin=data.admin;
  if(!append){products.clear();$('#grid').innerHTML='';}
  data.items.forEach(p=>products.set(p.id,p));
  $('#grid').insertAdjacentHTML('beforeend',data.items.map(card).join(''));
  if(!products.size)$('#grid').innerHTML=`<div class="empty">${data.total?'Aucune offre récente ne correspond à ces filtres.':'Aucune offre récente disponible pour le moment.'}<br><small>${data.knownReferences?'Le catalogue contient des références enregistrées. Elles réapparaîtront après un relevé valide.':'Le programme découvrira les références pendant ses passages de collecte.'} ${session.admin?'Tu peux lancer une actualisation.':'Le programme de collecte poursuit ses passages automatiques.'}</small></div>`;
  $('#referenceCount').textContent=data.total.toLocaleString('fr-FR');$('#sourceCount').textContent=data.sources.filter(s=>s.offers>0).length;
  const drops=data.statistics?.drops??data.events.filter(e=>e.kind==='drop').length;$('#changesCount').textContent=drops;$('#dropCount').textContent=drops;
  $('#lastRun').textContent=data.lastRun?new Date(data.lastRun).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'}):'—';$('#lastRun').title=stamp(data.lastRun);
  $('#count').textContent=`${data.matched.toLocaleString('fr-FR')} référence${data.matched>1?'s':''} ${data.offline?'enregistrée'+(data.matched>1?'s':''):'avec offre récente'} · ${data.offers} offres avec relevé récent`;
  $('#title').textContent=tab==='drops'?'Les baisses observées':categories[query.category];$('#more').hidden=!data.hasMore;
  const metadata=data.brands||[];fillSelect($('#brand'),metadata.map(b=>[b,b]),query.brand,'Toutes les marques');
  fillSelect($('#seller'),data.sources.map(s=>[s.id,s.name]),query.source,'Toutes les boutiques');
  $('#connection').innerHTML=`<i class="dot"></i>${data.total?'Offres datées disponibles':'En attente de relevés'}`;
  if(!localMode&&!syncing&&data.total===0)notice('Les offres sans relevé récent sont masquées. Une actualisation doit être effectuée depuis l’espace Matéo ou par la tâche automatique.');
  categoryNavigation();renderEvents();renderSources();if(localMode)localNotice();
 }catch(e){notice(e.message,true);$('#connection').textContent='Connexion indisponible';if(!snapshot)$('#grid').innerHTML='<div class="empty">Le catalogue ne peut pas être chargé.<br>Vérifie le dernier passage dans GitHub Actions.</div>';}
}
function renderEvents(){const events=snapshot?.events||[];$('#events').innerHTML=events.length?events.map(e=>`<article class="event"><span class="move ${e.kind==='rise'?'up':''}">${e.kind==='drop'?'↘ Baisse':'↗ Hausse'}</span><div><h3>${esc(e.name)}</h3><p>${esc(e.seller)} · relevé le ${esc(stamp(e.at))}</p></div><strong class="${e.kind==='drop'?'down':'up'}">${esc(euro(e.oldCents))} → ${esc(euro(e.newCents))}</strong><a href="${url(e.url)}" target="_blank" rel="noopener noreferrer">Fiche marchand ↗</a></article>`).join(''):'<div class="empty">Aucune variation enregistrée pour le moment.<br><small>Une baisse ou une hausse apparaît après deux relevés différents de la même offre.</small></div>';}
function renderSources(){const labels={ok:'Lecture accessible',waiting:'En attente',blocked:'Collecte suspendue',limited:'Accès limité',saved:'Relevés enregistrés'};$('#sources').innerHTML=(snapshot?.sources||[]).map(s=>`<article class="source-card"><div class="source-head"><h3>${esc(s.name)}</h3><span class="status ${esc(s.status)}">${labels[s.status]||'À vérifier'}</span></div><p>${s.offers} offre${s.offers>1?'s':''} récente${s.offers>1?'s':''} affichable${s.offers>1?'s':''} · vendeur direct uniquement</p><a href="${url(s.url)}" target="_blank" rel="noopener noreferrer">Ouvrir la boutique ↗</a><small>Dernière lecture réussie : ${esc(stamp(s.lastSuccess))}</small></article>`).join('');}
function history(h){if(!Array.isArray(h)||h.length<2)return '<div class="history"><h3>Historique des relevés</h3><p>Le suivi commence au premier relevé réel. La courbe apparaîtra après une seconde observation.</p></div>';const rows=h.filter(x=>Number.isInteger(x.priceCents)&&Number.isFinite(x.epoch));if(rows.length<2)return '';const min=Math.min(...rows.map(x=>x.priceCents)),max=Math.max(...rows.map(x=>x.priceCents)),span=max-min||1;const first=rows[0],last=rows.at(-1),timeSpan=last.epoch-first.epoch;const pts=rows.map((x,i)=>`${(12+(timeSpan>0?(x.epoch-first.epoch)/timeSpan:i/(rows.length-1))*576).toFixed(2)},${(max===min?65:108-(x.priceCents-min)/span*82).toFixed(2)}`).join(' ');return `<div class="history"><h3>Historique réellement relevé</h3><svg class="chart" viewBox="0 0 600 120" role="img" aria-label="Prix relevés de ${esc(euro(min))} à ${esc(euro(max))}"><path class="gridline" d="M10 108H590M10 65H590M10 22H590"/><polyline points="${pts}"/></svg><div class="history-legend"><span>${esc(stamp(first.at))}</span><span>${esc(stamp(last.at))}</span></div><p>Minimum ${esc(euro(min))} · maximum ${esc(euro(max))} · ${rows.length} observations du même vendeur.</p></div>`;}
function openDrawer(id,refresh=false){const p=products.get(id);if(!p)return;currentProductId=id;if(!refresh)previousFocus=document.activeElement;
 $('#drawerContent').innerHTML=`<p class="eyebrow">${esc(categories[p.category])} · ${esc(p.brand)}</p><h2 id="detailTitle">${esc(p.name)}</h2>${url(p.image)?`<img class="detail-image" src="${url(p.image)}" alt="${esc(p.name)}" referrerpolicy="no-referrer">`:''}<p class="ref">${p.mpn?`Référence fabricant : ${esc(p.mpn)}<br>`:''}${p.gtin?`Code GTIN : ${esc(p.gtin)}<br>`:''}Chaque lien ci-dessous correspond à l’offre exacte du vendeur indiqué.</p><div class="specs detail-specs">${Object.values(p.specs||{}).map(s=>`<span>${esc(s)}</span>`).join('')}</div>${p.offers.map(o=>`<div class="offer"><div><strong>${p.expired?'Prix à actualiser':esc(euro(o.priceCents))}</strong><small class="verified">${esc(o.seller)} · neuf · ${p.expired?'stock à recontrôler':'stock déclaré au relevé'}${!p.expired&&Number.isInteger(o.stockQuantity)?` (${o.stockQuantity} unité${o.stockQuantity>1?'s':''})`:''}</small><small>TTC hors livraison · relevé le ${esc(stamp(o.checkedAt))}</small></div><a href="${url(o.url)}" target="_blank" rel="noopener noreferrer">Voir chez ${esc(o.merchant)} ↗</a></div>`).join('')}${history(p.best.history)}<div class="alertbox"><strong>Suivre les variations</strong><br>Les baisses et les hausses réellement observées sont conservées dans l’onglet Activité. Les alertes e-mail ne sont pas connectées dans cette édition.</div>`;
 $('#back').classList.add('open');if(!refresh)$('#close').focus();
}
function closeDrawer(){currentProductId=null;$('#back').classList.remove('open');previousFocus?.focus();}
async function selectTab(value){
 if(!['catalogue','drops','activity','sources'].includes(value))return;
 tab=value;query.page=1;
 for(const [id,v] of [['catalogueView',['catalogue','drops'].includes(tab)],['activityView',tab==='activity'],['sourcesView',tab==='sources']])$('#'+id).hidden=!v;
 document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
 $('#crumb').textContent='/ '+({catalogue:'Catalogue',drops:'Baisses',activity:'Activité',sources:'Boutiques'}[tab]);await load();
}
async function sync(){
 if(syncing)return;syncing=true;$('#refresh').disabled=true;
 try{await refreshRemote(true);query.page=1;await load();}
 catch(error){notice(error.message,true);}
 finally{syncing=false;$('#refresh').disabled=false;}
}
document.addEventListener('click',e=>{const cat=e.target.closest('[data-cat]');if(cat){query.category=cat.dataset.cat;query.page=1;if(!['catalogue','drops'].includes(tab))selectTab('catalogue');else load();}const silicon=e.target.closest('[data-silicon]');if(silicon){query.silicon=silicon.dataset.silicon;query.page=1;load();}const open=e.target.closest('[data-open]');if(open){e.preventDefault();openDrawer(open.dataset.open);}const nav=e.target.closest('[data-tab]');if(nav)selectTab(nav.dataset.tab);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer();if(e.key==='/'&&!/input|textarea|select/i.test(document.activeElement.tagName)){e.preventDefault();$('#search').focus();}if(e.key==='Enter'&&e.target.matches('.visual[data-open]'))openDrawer(e.target.dataset.open);if(e.key==='Tab'&&$('#back').classList.contains('open')){const focus=[...$('#back').querySelectorAll('button,a[href]')],first=focus[0],last=focus.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}});
document.addEventListener('error',e=>{if(e.target.tagName==='IMG'&&e.target.parentElement.classList.contains('visual')){e.target.hidden=true;e.target.parentElement.classList.remove('has-image');}},true);
$('#back').addEventListener('click',e=>{if(e.target===$('#back'))closeDrawer();});$('#close').addEventListener('click',closeDrawer);
$('#search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{query.q=$('#search').value;query.page=1;load();},300);});
for(const [id,key]of[['seller','source'],['sort','sort'],['brand','brand'],['platform','platform']])$('#'+id).addEventListener('change',()=>{query[key]=$('#'+id).value;query.page=1;load();});
$('#more').addEventListener('click',()=>{query.page++;load(true);});$('#refresh').addEventListener('click',sync);$('#stopSync').addEventListener('click',()=>{stop=true;$('#syncText').textContent='Arrêt après la lecture en cours…';});
(async()=>{try{session=await api('session');await load();}catch(e){if(archiveData){connectionFailure=e.message;localNotice();}else{notice(e.message,true);$('#connection').textContent='Connexion indisponible';}}})();
setInterval(async()=>{if(document.hidden||syncing)return;const selected=currentProductId;query.page=1;await load();if(selected){const latest=localState({id:selected}).items[0];if(latest){products.set(selected,latest);openDrawer(selected,true);}else closeDrawer();}},90000);
