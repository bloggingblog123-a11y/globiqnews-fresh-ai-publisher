const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(__dirname+'/plugin/globiqnews-fresh-ai-publisher/assets/admin.js','utf8');
let checks=0;
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS: '+label);}
for(const failSave of [false,true]){
 const handlers={},ready=[],requests=[],rendered={};let saves=0;
 class Element {
  constructor(selector,cat=1){this.selector=selector;this.cat=cat;this.length=1;}
  on(event,selector,fn){handlers[selector]=fn;return this;}
  prop(){return this;}removeClass(){return this;}addClass(){return this;}
  text(value){if(value===undefined)return 'Run';rendered[this.selector]=value;return this;}
  data(){return this.cat;}find(s){return new Element(s,this.cat);}first(){return this;}is(){return false;}
  val(){return this.selector.includes('post_limit')?2:this.selector==='selected'?this.cat:'';}
  each(fn){if(this.selector==='.gnf6-select-category:checked'){[1,2,3].forEach(cat=>fn.call(new Element('selected',cat)));}return this;}
 }
 function $(s){if(typeof s==='function'){ready.push(s);return;}return s instanceof Element?s:new Element(s);}
 $.post=(url,data)=>{
  requests.push(data);let result;
  if(data.action==='gnf5_save_category'){saves++;result=failSave&&saves===2?{success:false,data:{message:'Save busy; retry.'}}:{success:true,data:{category:{post_limit:2,enabled:1}}};}
  else if(data.action==='gnf6_enqueue_categories')result={success:true,data:{message:'Queued.',queue:{jobs:[{category:1,state:'processing',created:0,target:2},{category:2,state:'waiting',created:0,target:2}]}}};
  else result={success:true,data:{jobs:[{category:1,state:'completed',created:2,target:2},{category:2,state:'processing',created:0,target:2}]}};
  return {done(fn){fn(result);return this;},fail(){return this;},always(fn){fn();return this;}};
 };
 vm.runInNewContext(code,{jQuery:$,document:{},window:{},setTimeout(){},GNF5Data:{catLimits:{},catNames:{1:'Sports',2:'Business',3:'AI'},enabledCats:[],bulkRecovery:{}}});
 handlers['.gnf6-run-selected'].call(new Element('button'));
 const actions=requests.map(x=>x.action);
 if(failSave){check(!actions.includes('gnf6_enqueue_categories'),'failed category save prevents queuing stale configuration');}
 else{
  check(actions.join(',')==='gnf5_save_category,gnf5_save_category,gnf5_save_category,gnf6_enqueue_categories','all selected settings save before a single group enqueue');
  check(requests.slice(0,3).every(x=>x.rss===''),'empty RSS is explicitly submitted for every selected category');
  check(JSON.stringify(requests.at(-1).cat_ids)==='[1,2,3]','complete ordered selection goes to backend queue');
  ready[0]();
  check(rendered['#gnf6-queue-status'].includes('Sports — Completed')&&rendered['#gnf6-queue-status'].includes('Business — Processing'),'poll refresh displays next backend worker automatically');
  check(!requests.some(x=>x.action==='gnf5_run_category'),'browser polls state without controlling article execution');
 }
}
console.log('TOTAL: '+checks+' queue UI checks passed');
