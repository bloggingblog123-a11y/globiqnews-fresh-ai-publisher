const fs=require('fs'),vm=require('vm'),assert=require('assert');
const code=fs.readFileSync(__dirname+'/plugin/globiqnews-fresh-ai-publisher/assets/report.js','utf8');
for(const mode of ['dirty','cancel','confirm','recheck']){
 const handlers={},posts=[],messages=[];let prompts=0;
 const task=mode==='recheck'?'recheck_title':'regenerate_title';
 class Element{
  on(event,selector,fn){handlers[selector]=fn;return this;}
  closest(){return this;}find(){return this;}prop(){return this;}
  text(value){messages.push(value);return this;}data(key){return {action:task,post:77}[key];}
 }
 function $(value){return value instanceof Element?value:new Element();}
 $.post=(url,data)=>{posts.push(data);return {done(){return this;},fail(){return this;},always(fn){fn();return this;}};};
 const wp={data:{select(){return {isEditedPostDirty(){return mode==='dirty';}};}}};
 const window={wp,confirm(){prompts++;return mode!=='cancel';}};
 vm.runInNewContext(code,{jQuery:$,document:{},window,wp,GNF6Report:{ajaxurl:'/ajax',nonce:'test'}});
 handlers['.gnf6-action'].call(new Element());
 if(mode==='dirty'){assert.equal(posts.length,0);assert.equal(prompts,0);assert.match(messages[0],/Save your editor changes/);}
 if(mode==='cancel'){assert.equal(posts.length,0);assert.equal(prompts,1);}
 if(mode==='confirm'){assert.equal(posts.length,1);assert.equal(posts[0].confirmed,'yes');assert.equal(posts[0].task,task);}
 if(mode==='recheck'){assert.equal(posts.length,1);assert.equal(prompts,0);assert.equal(posts[0].confirmed,'no');}
 console.log('PASS: title action '+mode);
}
