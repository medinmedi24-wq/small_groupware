const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync(require('node:path').join(__dirname,'../public/assets/attendance-gps.js'),'utf8');
function setup(){
  let submit,success,failure,options,calls=0,saves=0;
  const label={hidden:false};
  const fields={csrf:{value:'test-token'},punchMode:{value:'office',addEventListener(_,fn){this.change=fn}},tripReason:{value:'',closest(){return label}}};
  const button={textContent:'위치 확인 후 출근'},status={};
  const form={elements:{namedItem:n=>fields[n]},querySelector:s=>s==='button'?button:status,addEventListener(_,fn){submit=fn},append(i){fields[i.name]=i}};
  const navigator={onLine:true,geolocation:{getCurrentPosition(ok,fail,opts){calls++;success=ok;failure=fail;options=opts}}};
  const context={AbortController,URLSearchParams,setTimeout,clearTimeout,fetch:async()=>({ok:true,status:200,json:async()=>({office:false})}),window:{isSecureContext:true},navigator,document:{querySelectorAll:()=>[form],createElement:()=>({})},HTMLFormElement:{prototype:{submit(){saves++}}}};
  vm.runInNewContext(source,context);
  return {fields,button,status,label,context,send:()=>submit({preventDefault(){}}),ok:(overrides={})=>success({coords:{latitude:37.3796517,longitude:126.6659428,accuracy:10,...overrides},timestamp:Date.now()}),fail:code=>failure({code}),stats:()=>({calls,saves,options})};
}
test('location only requested on click; repeated clicks locked until result',async()=>{
  const s=setup();assert.equal(s.stats().calls,0);await s.send();await s.send();assert.equal(s.stats().calls,1);assert.equal(s.stats().saves,0);assert.equal(s.button.disabled,true);
  s.ok();assert.equal(s.stats().saves,1);assert.equal(s.fields.latitude.value,'37.3796517');assert.equal(s.stats().options.maximumAge,0);assert.equal(s.stats().options.enableHighAccuracy,true);
});
test('denial, timeout, unavailable and inaccurate position never submit and allow retry',async()=>{
  for(const code of [1,2,3]){const s=setup();await s.send();s.fail(code);assert.equal(s.stats().saves,0);assert.equal(s.button.disabled,false);await s.send();assert.equal(s.stats().calls,2);}
  const s=setup();await s.send();s.ok({accuracy:101});assert.equal(s.stats().calls,2);assert.equal(s.button.disabled,true);s.ok({accuracy:101});assert.equal(s.stats().saves,0);assert.equal(s.button.disabled,false);assert.match(s.status.textContent,/101m/);
});
test('offline or insecure pages do not request position',async()=>{
  const s=setup();s.context.navigator.onLine=false;await s.send();assert.equal(s.stats().calls,0);s.context.navigator.onLine=true;s.context.window.isSecureContext=false;await s.send();assert.equal(s.stats().calls,0);
});
test('trip reason appears and becomes required only for business trips',async()=>{
  const s=setup();assert.equal(s.label.hidden,true);assert.equal(s.fields.tripReason.required,false);s.fields.punchMode.value='business_trip';s.fields.punchMode.change();assert.equal(s.label.hidden,false);assert.equal(s.fields.tripReason.required,true);
});

test('one fresh retry can recover low accuracy without weakening the limit',async()=>{const s=setup();await s.send();s.ok({accuracy:500});s.ok({accuracy:20});assert.equal(s.stats().calls,2);assert.equal(s.stats().saves,1);assert.equal(s.fields.accuracy.value,'20');});

test('GPS no longer bypassed by company network',async()=>{
 const s=setup();s.context.fetch=async()=>{throw new Error('network endpoint must not be called')};await s.send();assert.equal(s.stats().saves,0);assert.equal(s.stats().calls,1);
});
