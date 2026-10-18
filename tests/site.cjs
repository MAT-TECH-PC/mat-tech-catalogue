/* Contract tests in Node VM. No browser and no merchant request is simulated as a live read. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.join(__dirname,'..'),source=fs.readFileSync(path.join(root,'site/assets/app.js'),'utf8'),html=fs.readFileSync(path.join(root,'site/index.html'),'utf8');
const real=JSON.parse(fs.readFileSync(path.join(root,'public/catalogue.json'),'utf8'));
function make(data=real,{fail=false,remote=true,age=0}={}){
 const nodes=new Map(),calls=[];let offset=age,interval;const document={hidden:false,activeElement:{tagName:'BODY'},querySelector:node,querySelectorAll:()=>[],addEventListener(){}};
 function node(k){if(nodes.has(k))return nodes.get(k);const classes=new Set();const n={innerHTML:'',textContent:'',value:'',disabled:false,hidden:false,tagName:'DIV',listeners:{},classList:{add:c=>classes.add(c),remove:c=>classes.delete(c),contains:c=>classes.has(c),toggle(c,v){v?classes.add(c):classes.delete(c);}},querySelectorAll:()=>[],addEventListener(t,fn){this.listeners[t]=fn;},insertAdjacentHTML(w,t){this.innerHTML+=t;},focus(){document.activeElement=this;}};nodes.set(k,n);return n;}
 class Clock extends Date{static now(){return Date.now()+offset;}}
 const context=vm.createContext({document,console,location:{href:'https://mat-tech-pc.fr/catalogue-auto/',protocol:'https:'},URL,Intl,Date:Clock,Map,Number,String,Math,JSON,Array,Object,Error,Promise,setTimeout,clearTimeout,AbortController,setInterval(fn){interval=fn;},MATTECH_ARCHIVE:data,MATTECH_DATA_URL:remote?'https://raw.githubusercontent.com/proprietaire/mat-tech-catalogue/main/public/catalogue.json':'',fetch:async(u,o)=>{calls.push({url:String(u),options:o});if(fail)throw Error('Panne test');return{ok:true,json:async()=>data};}});
 vm.runInContext(source,context);return{context,node,calls,advance(ms){offset+=ms;},interval:()=>interval()};
}
const tick=()=>new Promise(r=>setImmediate(r));async function settled(){for(let i=0;i<8;i++)await tick();}
let passed=0;function test(name,fn){fn();passed++;console.log('PASS',name);}
(async()=>{
 const app=make();await settled();
 test('Chaque élément utilisé par le script existe dans la page',()=>{for(const m of source.matchAll(/\$\('#([A-Za-z][A-Za-z0-9]*)'\)/g))assert(html.includes(`id="${m[1]}"`),m[1]);});
 test('Les données viennent du dépôt public et aucun identifiant n’est envoyé',()=>{assert(app.calls.length>0);assert(app.calls.every(c=>c.url.startsWith('https://raw.githubusercontent.com/')&&c.options.credentials==='omit'));assert(!app.calls.some(c=>c.url.includes('api.php')));});
 test('Le programme ne promet pas un prix live pour un ancien relevé',()=>{const stale=make(real,{age:86400000});vm.runInContext('archiveData=MATTECH_ARCHIVE; const sample=localState(); if(sample.items.length) globalThis.oldCard=card(sample.items[0]);',stale.context);if(real.products.length){assert(stale.context.oldCard.includes('Prix à actualiser'));assert(!stale.context.oldCard.includes('TTC · hors livraison'));}});
 test('Les huit familles restent présentes',()=>{for(const label of ['Processeurs','Cartes graphiques','Cartes mères','Mémoire RAM','SSD','Alimentations','Boîtiers','Refroidissement'])assert(app.node('#catNav').innerHTML.includes(label));});
 await vm.runInContext('selectTab("sources")',app.context);
 test('Les boutiques exposent des liens HTTPS exacts',()=>{for(const s of real.sources)assert(app.node('#sources').innerHTML.includes(s.url));});
 if(real.products.length){
  const p=real.products[0];await vm.runInContext('query.category="all";query.q="";load()',app.context);
  test('Le lien marchand est bien associé au produit',()=>{const visible=vm.runInContext('localState().items[0]',app.context);const card=vm.runInContext(`card(${JSON.stringify(visible)})`,app.context);assert(card.includes(visible.best.url.replaceAll('&','&amp;')));});
  test('Les données des boutiques sont échappées avant affichage',()=>{const x=JSON.parse(JSON.stringify(p));x.name='<img src=x onerror=alert(1)>';const out=vm.runInContext(`card({...${JSON.stringify(x)},best:${JSON.stringify(p.offers[0])}})`,app.context);assert(!out.includes('<img src=x'));assert(out.includes('&lt;img'));});
  // Time-only fixture in memory: never written to the catalogue or merchant ledger.
  const fixture=JSON.parse(JSON.stringify(real));fixture.products=[JSON.parse(JSON.stringify(p))];for(const o of fixture.products[0].offers){o.checkedEpoch=Math.floor(Date.now()/1000);o.checkedAt=new Date().toISOString();}
  const opened=make(fixture);await settled();vm.runInContext(`openDrawer(${JSON.stringify(p.id)})`,opened.context);assert(!opened.node('#drawerContent').innerHTML.includes('Prix à actualiser'));
  opened.advance(4*3600000);await opened.interval();
  test('Le montant expire même lorsque la fiche détail reste ouverte',()=>{assert(opened.node('#drawerContent').innerHTML.includes('Prix à actualiser'));assert(opened.node('#back').classList.contains('open'));});
 }
 const failed=make(real,{fail:true});await settled();
 test('Une panne garde les données datées et affiche un avertissement',()=>{assert(failed.node('#noticeText').textContent.includes('pas accessible'));assert(failed.node('#connection').textContent.includes('vérifier'));});
 const empty=make({...real,products:[]});await settled();
 test('Une base vide ne produit aucune carte inventée',()=>{assert(!empty.node('#grid').innerHTML.includes('class="card"'));assert(empty.node('#grid').innerHTML.includes('Aucune offre'));});
 const noRepo=make(real,{remote:false});await settled();
 test('L’installation incomplète est indiquée sans demande de mot de passe',()=>{assert.equal(noRepo.calls.length,0);assert(noRepo.node('#noticeText').textContent.includes('installation unique'));});
 console.log(passed+' contrôles d’interface VM réussis. Aucun essai navigateur effectué.');
})().catch(e=>{console.error(e);process.exitCode=1;});
