const { readFileSync } = require('node:fs');
const { runInNewContext } = require('node:vm');
const assert = require('node:assert/strict');
const handlers = {}, windowHandlers = {};
const options = [{value:'',textContent:'Alle projecten'}, {value:'1',textContent:'Example Project'}, {value:'2',textContent:'Ander',selected:true}, {value:'3',textContent:'Geen match'}];
const status = {hidden:true};const buttons = [{disabled:false},{disabled:false}];const hidden = [];
const form = {dataset:{},matches:(s)=>s==='[data-confirm-invoice]',appendChild(x){hidden.push(x);},querySelector:()=>status,querySelectorAll(s){return s==='button'?buttons:hidden;}};
let filterSubmits = 0;
const filter = {dataset:{}, search:'Example Project', matches:s=>s==='[data-project-catalog-filter]', requestSubmit(){filterSubmits++;handlers.submit({target:filter,preventDefault(){throw new Error('First submit blocked');}});}};
const toggle = {checked:false,form:filter,matches:s=>s==='[data-project-archive-toggle]'};
const preview = {dataset:{submitting:'true'}};
runInNewContext(readFileSync(require('node:path').join(__dirname,'../public/assets/daily.js'),'utf8'), {
 document:{addEventListener(e,f){handlers[e]=f;},getElementById(id){return id==='project-filter'?{options}:preview;},createElement(){const el={remove(){hidden.splice(hidden.indexOf(el),1);}};return el;},querySelectorAll(s){return s==='[data-project-catalog-filter]'?[filter]:[form];}},
 window:{addEventListener(e,f){windowHandlers[e]=f;}}
});
handlers.input({target:{matches:()=>true,value:'example project'}});assert.equal(options[1].hidden,false);assert.equal(options[2].hidden,false);assert.equal(options[3].hidden,true);
handlers.submit({target:form,submitter:{value:'cancel'}});assert.equal(hidden.length,0);assert.equal(status.hidden,true);
handlers.submit({target:form,submitter:{value:'confirm'}});assert.equal(status.hidden,false);assert.equal(buttons[0].disabled,true);assert.equal(hidden[0].value,'confirm');assert.equal(hidden[0].name,'action');
let stopped=false;handlers.submit({target:form,submitter:{value:'confirm'},preventDefault(){stopped=true;}});assert.equal(stopped,true);assert.equal(hidden.length,1);
windowHandlers.pageshow();assert.equal(hidden.length,0);assert.equal(buttons[0].disabled,false);assert.equal(status.hidden,true);assert.equal(preview.dataset.submitting,undefined);
console.log('Dagelijkse JS-tests geslaagd: projectzoeker, directe feedback, submitguard, POST-action en browser-back.');

toggle.checked=true;handlers.change({target:toggle});
assert.equal(filterSubmits,1);assert.equal(filter.search,'Example Project');
handlers.change({target:toggle});assert.equal(filterSubmits,1);
let filterBlocked=false;handlers.submit({target:filter,preventDefault(){filterBlocked=true;}});
assert.equal(filterBlocked,true);
windowHandlers.pageshow();assert.equal(filter.dataset.submitting,undefined);
toggle.checked=false;handlers.change({target:toggle});assert.equal(filterSubmits,2);
assert.equal(filter.search,'Example Project');assert.equal(toggle.checked,false);
handlers.change({target:{matches:()=>false}});assert.equal(filterSubmits,2);
console.log('Projectarchief: aan/uit automatisch submitten, zoekterm behouden, dubbele submit geblokkeerd, browser-back hersteld.');
