(function($){
    function status(msg,ok){var $s=$('#gnf5-status');$s.prop('hidden',false).removeClass('ok err').addClass(ok===true?'ok':(ok===false?'err':'')).text(msg);}
    function call(action,data){data=data||{};data.action=action;data.nonce=GNF5Data.nonce;return $.post(GNF5Data.ajaxurl,data);}
    function handle(promise,done){promise.done(function(r){if(r&&r.success){status(r.data.message||'Done.',true);if(done)done(true,r);}else{status((r&&r.data&&r.data.message)||'Operation failed.',false);if(done)done(false,r);}}).fail(function(xhr){var msg='Request failed.';if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;status(msg,false);if(done)done(false,xhr);});}
    function catName(cat){return (GNF5Data.catNames||{})[cat]||('Category '+cat);}

    $(document).on('click','.gnf5-check-publish-scores',function(){
        var button=$(this);button.prop('disabled',true);
        status('Checking saved Rank Math scores and publishing eligible drafts…');
        var request=call('gnf5_check_publish_scores',{});handle(request);
        // Preserve the result on screen so any blocking reason can be read.
        request.always(function(){button.prop('disabled',false);});
    });

    function categoryFormData(cat){
        var box=$('#gnf5-cat-'+cat);
        if(!box.length)return null;
        var get=function(suffix){return box.find('[name$="['+suffix+']"]').first();};
        return {
            cat_id:cat,
            enabled:box.find('input[type="checkbox"][name$="[enabled]"]').first().is(':checked')?1:0,
            post_limit:get('post_limit').val()||1,
            interval:get('interval').val()||'hourly',
            author_id:get('author_id').val()||0,
            rss:get('rss').val()||'',
            urls:get('urls').val()||'',
            external_links:get('external_links').val()||'',
            instructions:get('instructions').val()||''
        };
    }

    function rememberSavedCategory(cat,r){
        if(!r||!r.data||!r.data.category)return;
        GNF5Data.catLimits[cat]=parseInt(r.data.category.post_limit||1,10);
        var idx=(GNF5Data.enabledCats||[]).map(String).indexOf(String(cat));
        if(parseInt(r.data.category.enabled||0,10)===1&&idx<0)GNF5Data.enabledCats.push(cat);
        if(parseInt(r.data.category.enabled||0,10)!==1&&idx>=0)GNF5Data.enabledCats.splice(idx,1);
    }

    function saveCategoryBeforeAction(cat,callback){
        var data=categoryFormData(cat);
        if(!data){status('Category panel not found.',false);callback(false);return;}
        status(catName(cat)+': saving current RSS/Source settings first…');
        call('gnf5_save_category',data).done(function(r){
            if(r&&r.success){
                rememberSavedCategory(cat,r);
                callback(true,r);
            }else{
                status((r&&r.data&&r.data.message)||'Could not save category settings.',false);
                callback(false,r);
            }
        }).fail(function(xhr){
            var msg='Could not save category settings.';
            if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;
            status(msg,false);callback(false,xhr);
        });
    }

    function runCategoryBatch(cat,limit,onDone){
        var made=0,requests=0,zeroStreak=0;
        var summary={published:0,drafts:0,duplicates:0,failed:0,rssFailures:0,sourceFailures:0,rssCandidates:0,sourceCandidates:0,diagnostics:[]};
        limit=Math.max(1,parseInt(limit||1,10));var maxRequests=(limit*4)+4;

        function addDiagnostics(list){
            (list||[]).forEach(function(x){if(x&&summary.diagnostics.indexOf(x)<0)summary.diagnostics.push(x);});
        }
        function step(){
            if(made>=limit||requests>=maxRequests||zeroStreak>=5){onDone(made,summary);return;}
            requests++;status(catName(cat)+': created '+made+' of '+limit+'. Reading RSS/Source candidates…');
            call('gnf5_run_category',{cat_id:cat,pass:Math.min(4,requests)}).done(function(r){
                if(r&&r.success&&r.data.result){
                    var d=r.data.result,c=parseInt(d.created||0,10);made+=c;
                    summary.published+=parseInt(d.published||0,10);
                    summary.drafts+=parseInt(d.drafts||0,10);
                    summary.duplicates+=parseInt(d.duplicates||0,10);
                    summary.failed+=parseInt(d.failed_before_create||0,10);
                    summary.rssFailures=Math.max(summary.rssFailures,parseInt(d.rss_failures||0,10));
                    summary.sourceFailures=Math.max(summary.sourceFailures,parseInt(d.source_failures||0,10));
                    summary.rssCandidates=Math.max(summary.rssCandidates,parseInt(d.rss_candidates||0,10));
                    summary.sourceCandidates=Math.max(summary.sourceCandidates,parseInt(d.source_candidates||0,10));
                    addDiagnostics(d.diagnostics);

                    // If nothing reached article processing and the source itself failed,
                    // do not repeat the exact same broken feed five times.
                    if(c===0 && parseInt(d.attempted||0,10)===0 && (summary.rssFailures>0||summary.sourceFailures>0)){
                        onDone(made,summary);return;
                    }
                    if(c===0)zeroStreak++;else zeroStreak=0;step();
                }else{
                    var msg=(r&&r.data&&r.data.message)||'Category run failed.';
                    addDiagnostics([msg]);onDone(made,summary);
                }
            }).fail(function(xhr){
                var msg='Category request failed.';
                if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;
                addDiagnostics([msg]);onDone(made,summary);
            });
        }step();
    }

    $(document).on('click','.gnf5-test-cat-sources',function(){
        var b=$(this),cat=parseInt(b.data('cat'),10),orig=b.text();
        b.prop('disabled',true).text('Saving + Testing…');
        saveCategoryBeforeAction(cat,function(saved){
            if(!saved){b.prop('disabled',false).text(orig);return;}
            status(catName(cat)+': testing RSS, Source URLs and External Links…');
            handle(call('gnf5_test_category_sources',{cat_id:cat}),function(){b.prop('disabled',false).text(orig);});
        });
    });

    $(document).on('click','.gnf5-save-cat',function(){
        var b=$(this),cat=parseInt(b.data('cat'),10),orig=b.text(),data=categoryFormData(cat);
        if(!data){status('Category panel not found.',false);return;}
        b.prop('disabled',true).text('Saving…');
        handle(call('gnf5_save_category',data),function(ok,r){
            b.prop('disabled',false).text(orig);
            if(ok)rememberSavedCategory(cat,r);
        });
    });

    $(document).on('click','.gnf5-run-cat',function(){
        var b=$(this),cat=parseInt(b.data('cat'),10),orig=b.text();
        b.prop('disabled',true).text('Saving + Running…');
        saveCategoryBeforeAction(cat,function(saved){
            if(!saved){b.prop('disabled',false).text(orig);return;}
            var limit=(GNF5Data.catLimits||{})[cat]||1;
            b.text('Running…');
            runCategoryBatch(cat,limit,function(made,summary){
                var msg=catName(cat)+' run finished. Target '+limit+'; created '+made+
                    ' | published '+summary.published+' | draft/pending '+summary.drafts+
                    ' | duplicates '+summary.duplicates+' | failed before create '+summary.failed+'.';
                if(made===0){
                    if(summary.rssFailures>0)msg+=' RSS feed error detected.';
                    else if(summary.rssCandidates===0&&summary.sourceCandidates===0)msg+=' No usable RSS/Source article candidates were found.';
                    if(summary.diagnostics.length)msg+=' '+summary.diagnostics[0];
                }
                status(msg,made>0);
                b.prop('disabled',false).text(orig);
            });
        });
    });

    $(document).on('click','.gnf5-run-all',function(){
        var b=$(this),cats=(GNF5Data.enabledCats||[]).slice(),i=0,total=0;
        if(!cats.length){status('No categories are enabled. Save settings first.',false);return;}
        b.prop('disabled',true);
        function next(){
            if(i>=cats.length){status('Run All finished. Created '+total+' post(s) across '+cats.length+' enabled categories.',total>0);b.prop('disabled',false);return;}
            var cat=cats[i++],limit=(GNF5Data.catLimits||{})[cat]||1;
            runCategoryBatch(cat,limit,function(made){total+=made;next();});
        }next();
    });

    $(document).on('click','.gnf5-run-manual',function(){var url=$('#gnf5-manual-url').val(),cat=$('#gnf5-manual-cat').val();if(!url){status('Enter an article URL.',false);return;}status('Processing manual article now…');handle(call('gnf5_run_manual',{url:url,cat_id:cat}),function(){setTimeout(function(){location.reload();},800);});});
    $(document).on('click','.gnf5-test-source',function(){var url=$('#gnf5-manual-url').val();if(!url){status('Enter an article URL to test.',false);return;}status('Testing public source extraction…');handle(call('gnf5_test_source',{url:url}));});
    $(document).on('click','.gnf5-test-gemini',function(){status('Testing Gemini with retry protection…');handle(call('gnf5_test_gemini',{}));});
    $(document).on('click','.gnf5-test-image',function(){status('Generating one temporary test image…');handle(call('gnf5_test_image',{}));});
    $(document).on('click','.gnf5-clear-log',function(){if(!window.confirm(GNF5Data.strings.confirmClear))return;handle(call('gnf5_clear_log',{}),function(ok){if(ok)$('#gnf5-log').text('No Fresh V5 log entries yet.');});});
    $(document).on('click','.gnf5-clear-lock',function(){var cat=$(this).data('cat');handle(call('gnf5_clear_lock',{cat_id:cat}),function(ok){if(ok)location.reload();});});

    var gnf5BulkRunning=false;

    function bulkSelectedIds(){
        var ids=[];
        $('.gnf5-recovery-select:checked').each(function(){
            var id=parseInt($(this).val(),10);if(id)ids.push(id);
        });
        return ids;
    }

    function updateBulkState(st){
        st=st||{};
        var pending=parseInt(st.pending||0,10),processing=parseInt(st.processing||0,10);
        $('#gnf5-bulk-recovery-state').text(pending?(pending+' queued'+(processing?' · 1 processing':'')):'Queue empty');
        var ids=(st.post_ids||[]).map(function(x){return parseInt(x,10);});
        $('.gnf5-recovery').each(function(){
            var row=$(this),id=parseInt(row.data('post'),10),queued=ids.indexOf(id)>=0;
            row.find('.gnf5-row-queue-state').text(queued?'Queued':'Not queued');
            row.find('.gnf5-recovery-select').prop('checked',queued);
        });
    }

    function runBulkRecoveryWorker(){
        if(gnf5BulkRunning)return;
        gnf5BulkRunning=true;
        $('.gnf5-retry-selected,.gnf5-retry-all-visible,.gnf5-retry-post').prop('disabled',true);

        function step(){
            call('gnf5_bulk_recovery_step',{}).done(function(r){
                if(!r||!r.success){
                    gnf5BulkRunning=false;
                    $('.gnf5-retry-selected,.gnf5-retry-all-visible,.gnf5-retry-post').prop('disabled',false);
                    status((r&&r.data&&r.data.message)||'Bulk recovery step failed.',false);
                    return;
                }
                var st=(r.data&&r.data.status)||{};
                updateBulkState(st);
                var post=parseInt(st.processed_post||0,10);
                if(post){
                    var row=$('#gnf5-recovery-'+post);
                    if(st.deferred){
                        row.find('.gnf5-row-queue-state').text('Waiting — category busy');
                    }else if(parseInt(st.validated||0,10)===1){
                        row.find('.gnf5-row-queue-state').text('Recovered');
                        row.fadeOut(500);
                    }else{
                        row.find('.gnf5-row-queue-state').text('Retry finished — still Draft');
                        if(r.data.message)row.find('.gnf5-recovery-message').text(r.data.message);
                    }
                }

                var pending=parseInt(st.pending||0,10);
                if(pending>0){
                    status((r.data&&r.data.message)||('One article finished. '+pending+' remain queued.'),!st.error);
                    window.setTimeout(step,st.deferred?10000:700);
                }else{
                    gnf5BulkRunning=false;
                    $('.gnf5-retry-selected,.gnf5-retry-all-visible,.gnf5-retry-post').prop('disabled',false);
                    status('Bulk recovery queue finished. Every selected article was handled one-by-one.',true);
                    $('#gnf5-bulk-recovery-state').text('Queue empty');
                }
            }).fail(function(xhr){
                gnf5BulkRunning=false;
                $('.gnf5-retry-selected,.gnf5-retry-all-visible,.gnf5-retry-post').prop('disabled',false);
                var msg='Bulk recovery request failed.';
                if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;
                status(msg,false);
            });
        }
        step();
    }

    function enqueueBulk(ids){
        ids=ids||[];
        if(!ids.length){status('Select at least one failed draft.',false);return;}
        status('Adding '+ids.length+' draft(s) to the safe one-by-one recovery queue…');
        call('gnf5_enqueue_bulk_recovery',{post_ids:ids}).done(function(r){
            if(!r||!r.success){status((r&&r.data&&r.data.message)||'Could not create bulk recovery queue.',false);return;}
            updateBulkState((r.data&&r.data.status)||{});
            status(r.data.message||'Recovery queue created.',true);
            runBulkRecoveryWorker();
        }).fail(function(xhr){
            var msg='Could not create bulk recovery queue.';
            if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;
            status(msg,false);
        });
    }

    $(document).on('change','#gnf5-select-all-recovery',function(){
        $('.gnf5-recovery-select:visible').prop('checked',$(this).is(':checked'));
    });

    $(document).on('click','.gnf5-retry-selected',function(){
        enqueueBulk(bulkSelectedIds());
    });

    $(document).on('click','.gnf5-retry-all-visible',function(){
        var ids=[];
        $('.gnf5-recovery-select:visible').each(function(){var id=parseInt($(this).val(),10);if(id)ids.push(id);});
        $('.gnf5-recovery-select:visible').prop('checked',true);
        enqueueBulk(ids);
    });

    $(function(){
        var st=GNF5Data.bulkRecovery||{};
        if(parseInt(st.pending||0,10)>0){
            updateBulkState(st);
            window.setTimeout(runBulkRecoveryWorker,600);
        }
    });

    $(document).on('click','.gnf5-retry-post',function(){
        var b=$(this),post=parseInt(b.data('post'),10),orig=b.text();b.prop('disabled',true).text('Retrying…');
        status('Retrying saved draft #'+post+' without starting over…');
        handle(call('gnf5_retry_post',{post_id:post}),function(ok){b.prop('disabled',false).text(orig);if(ok)setTimeout(function(){location.reload();},700);});
    });

    $(document).on('click','.gnf5-skip-failed',function(){
        if(!window.confirm(GNF5Data.strings.confirmSkip))return;
        var post=parseInt($(this).data('post'),10);
        handle(call('gnf5_skip_failed',{post_id:post}),function(ok){if(ok)$('#gnf5-recovery-'+post).fadeOut(250,function(){if($('#gnf5-recovery-section .gnf5-recovery:visible').length===0){$('#gnf5-recovery-section').fadeOut(200);}});});
    });
})(jQuery);
