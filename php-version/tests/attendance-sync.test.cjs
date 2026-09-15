const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/attendance-sync.js'), 'utf8');
function setup() {
  let tick, requests = 0, reloads = 0, blocked = false;
  let reply = async () => ({ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({revision:'new'})});
  const events = {}, notice = {hidden:true}, storage = new Map();
  const root = {dataset:{attendanceSync:'index.php?page=attendance-revision',revision:'old'},querySelector:()=>notice};
  const document = {hidden:false,activeElement:{matches:()=>false},querySelector:s=>s==='[data-attendance-sync]'?root:blocked,addEventListener:(n,fn)=>events[n]=fn};
  const navigator = {onLine:true};
  const context = {document,navigator,AbortController,location:{pathname:'/index.php',search:'?page=attendance&month=2026-09',reload:()=>reloads++},sessionStorage:{getItem:k=>storage.get(k)??null,removeItem:k=>storage.delete(k),setItem:(k,v)=>storage.set(k,v)},window:{scrollY:250,scrollTo(){},addEventListener:(n,fn)=>events[n]=fn},setInterval:fn=>tick=fn,setTimeout:()=>1,clearTimeout(){},fetch:async()=>{requests++;return reply();}};
  vm.runInNewContext(source,context);
  return {tick:()=>tick(),events,notice,document,navigator,storage,reply:fn=>reply=fn,block:()=>blocked=true,stats:()=>({requests,reloads})};
}
test('remote change reloads once and preserves filtered URL scroll',async()=>{
  const s=setup();await s.tick();await s.tick();assert.deepEqual(s.stats(),{requests:1,reloads:1});assert.equal([...s.storage.values()][0],'250');
});
test('unchanged revision does not reload',async()=>{
  const s=setup();s.reply(async()=>({ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({revision:'old'})}));await s.tick();assert.equal(s.stats().reloads,0);
});
test('editing while request is in flight preserves values and shows notice',async()=>{
  const s=setup();let resolve;s.reply(()=>new Promise(r=>resolve=r));const work=s.tick();
  s.events.input({target:{closest:()=>true}});resolve({ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({revision:'new'})});
  await work;assert.equal(s.stats().reloads,0);assert.equal(s.notice.hidden,false);
});
test('offline and hidden pages pause; returning online checks immediately',async()=>{
  const s=setup();s.navigator.onLine=false;await s.tick();s.navigator.onLine=true;s.document.hidden=true;await s.tick();assert.equal(s.stats().requests,0);s.document.hidden=false;await s.events.online();assert.equal(s.stats().reloads,1);
});
test('GPS in progress and focused inputs defer refresh',async()=>{
  const s=setup();s.block();await s.tick();assert.equal(s.stats().reloads,0);assert.equal(s.notice.hidden,false);
  const f=setup();f.document.activeElement.matches=()=>true;await f.tick();assert.equal(f.stats().reloads,0);
});
test('expired sessions stop polling; network errors retry without reloading',async()=>{
  const s=setup();s.reply(async()=>({status:401,ok:false}));await s.tick();await s.tick();assert.equal(s.stats().requests,1);assert.equal(s.stats().reloads,0);
  const n=setup();n.reply(async()=>{throw Error('offline')});await n.tick();await n.tick();assert.equal(n.stats().requests,2);assert.equal(n.stats().reloads,0);
});
test('concurrent checks and form submission cannot race a refresh',async()=>{
  const s=setup();let resolve;s.reply(()=>new Promise(r=>resolve=r));const work=s.tick();await s.tick();assert.equal(s.stats().requests,1);
  s.events.submit({defaultPrevented:false});resolve({ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>({revision:'new'})});await work;assert.equal(s.stats().reloads,0);
});
