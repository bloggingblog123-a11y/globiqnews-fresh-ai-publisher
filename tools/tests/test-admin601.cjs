const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(__dirname+'/plugin/globiqnews-fresh-ai-publisher/assets/admin.js','utf8');
for(const failure of ['locked','network']){
  const handlers={},messages=[];let calls=0;
  class Element {
    constructor(selector){this.selector=selector;this.length=1;}
    on(event,selector,fn){handlers[selector]=fn;return this;}
    prop(){return this;} removeClass(){return this;} addClass(){return this;}
    text(value){if(value===undefined)return 'Run Tech';if(this.selector==='#gnf5-status')messages.push(value);return this;}
    data(){return 1;}find(s){return new Element(s);}first(){return this;}is(){return false;}
    val(){return this.selector.includes('post_limit')?5:'';}
  }
  function $(s){if(typeof s==='function'){s();return;}return s instanceof Element?s:new Element(s);}
  $.post=(url,data)=>{
    const save=data.action==='gnf5_save_category';if(!save)calls++;
    const result=save?{success:true,data:{category:{post_limit:5,enabled:1}}}:{success:false,data:{message:'Another article is processing. Categories and SEO analysis run one at a time; retry shortly.'}};
    return {done(fn){if(save||failure==='locked')fn(result);return this;},fail(fn){if(!save&&failure==='network')fn({responseJSON:{data:{message:'Server request interrupted.'}}});return this;},always(fn){fn();return this;}};
  };
  vm.runInNewContext(code,{jQuery:$,document:{},GNF5Data:{catLimits:{1:5},catNames:{1:'Tech'},enabledCats:[],bulkRecovery:{}},window:{}});
  handlers['.gnf5-run-cat'].call(new Element('button'));
  const msg=messages.at(-1);
  assert.equal(calls,1);assert.match(msg,/run stopped/);assert.doesNotMatch(msg,/No usable|run finished/);
  assert.match(msg,failure==='locked'?/Another article is processing/:/Server request interrupted/);
  console.log('PASS: '+failure+' preserves the real error without inventing source failures or repeating the request');
}
