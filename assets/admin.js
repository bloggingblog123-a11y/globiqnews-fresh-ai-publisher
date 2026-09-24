(function($){
    function status(msg,ok){var $s=$('#gnf5-status');$s.prop('hidden',false).removeClass('ok err').addClass(ok===true?'ok':(ok===false?'err':'')).text(msg);}
    function call(action,data){data=data||{};data.action=action;data.nonce=GNF5Data.nonce;return $.post(GNF5Data.ajaxurl,data);}
    function handle(promise,done){promise.done(function(r){if(r&&r.success){status(r.data.message||'Done.',true);if(done)done(true,r);}else{status((r&&r.data&&r.data.message)||'Operation failed.',false);if(done)done(false,r);}}).fail(function(xhr){var msg='Request failed.';if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;status(msg,false);if(done)done(false,xhr);});}
    function catName(cat){return (GNF5Data.catNames||{})[cat]||('Category '+cat);}
    function categorySaveStatus(cat,msg,ok){$('#gnf5-cat-'+cat).find('.gnf6-category-save-result').text(msg);status(msg,ok);}

    $(document).on('click','.gnf6-category-manual',function(){
        var button=$(this),cat=button.data('cat'),url=$('#gnf5-cat-'+cat).find('.gnf6-category-url').val();
        if(!url){status('Enter an article URL for this category.',false);return;}
        button.prop('disabled',true);
        saveCategoryBeforeAction(cat,function(ok){
            if(!ok){button.prop('disabled',false);return;}
            status(catName(cat)+': researching the manual article…');
            var request=call('gnf5_run_manual',{url:url,cat_id:cat});handle(request);
            request.always(function(){button.prop('disabled',false);});
        });
    });

    $(document).on('click','.gnf5-check-publish-scores',function(){
        var button=$(this);button.prop('disabled',true);
        status('Checking saved Rank Math scores; articles remain Draft…');
        var request=call('gnf5_check_publish_scores',{});handle(request);
        // Preserve the result on screen so any blocking reason can be read.
        request.always(function(){button.prop('disabled',false);});
    });

    // Subsection inputs intentionally have no form names: only their own AJAX save can persist them.
    function sectionValues(box){
        var result={}, links=[];
        box.find('[data-field]').each(function(){
            var input=$(this);if(input.closest('.gnf6-link-row').length)return;
            result[input.data('field')]=input.is(':checkbox')?(input.is(':checked')?1:0):input.val();
        });
        if(box.data('section')==='links'){
            box.find('.gnf6-link-list .gnf6-link-row').each(function(){var row={};$(this).find('[data-field]').each(function(){var i=$(this);row[i.data('field')]=i.is(':checkbox')?(i.is(':checked')?1:0):i.val();});links.push(row);});
            result.manual_links=links;
        }
        return result;
    }
    function dirtySection(box){box.data('dirty',true).find('.gnf6-section-result').text('Unsaved changes — use this section’s Save button.');}
    $(document).on('input change','.gnf6-section [data-field]',function(){dirtySection($(this).closest('.gnf6-section'));});
    $(document).on('click','.gnf6-link-add',function(){var box=$(this).closest('.gnf6-section');box.find('.gnf6-link-list').append(box.find('template').html());box.find('.gnf6-link-row').last().find('[data-field="id"]').val('link-'+Date.now()+'-'+Math.random().toString(36).slice(2));dirtySection(box);});
    $(document).on('click','.gnf6-link-delete',function(){var box=$(this).closest('.gnf6-section');$(this).closest('.gnf6-link-row').remove();dirtySection(box);});
    $(document).on('click','.gnf6-link-up',function(){var row=$(this).closest('.gnf6-link-row'),box=row.closest('.gnf6-section');row.insertBefore(row.prev('.gnf6-link-row'));dirtySection(box);});
    $(document).on('click','.gnf6-save-section',function(){
        var button=$(this),box=button.closest('.gnf6-section'),values=JSON.stringify(sectionValues(box));
        button.prop('disabled',true);box.find('.gnf6-section-result').text('Saving…');
        var request=call('gnf5_save_section',{cat_id:box.data('cat'),section:box.data('section'),revision:box.attr('data-revision'),values:values});
        request.done(function(r){
            box.find('.gnf6-section-result').text(r&&r.data&&r.data.message||'Could not save settings.');
            if(r&&r.success){box.attr('data-revision',r.data.revision);box.data('dirty',JSON.stringify(sectionValues(box))!==values);if(box.data('dirty'))box.find('.gnf6-section-result').append(' Newer edits still need saving.');}
        }).fail(function(xhr){box.find('.gnf6-section-result').text(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message||'Save failed. Your changes are still unsaved.');}).always(function(){button.prop('disabled',false);});
    });
    $(window).on('beforeunload',function(event){
        var dirty=false;$('.gnf6-section').each(function(){if($(this).data('dirty'))dirty=true;});
        if(dirty){event.preventDefault();event.originalEvent.returnValue='';return '';}
    });

    function categoryFormData(cat){
        var box=$('#gnf5-cat-'+cat);
        if(!box.length)return null;
        var get=function(suffix){return box.find('[name$="['+suffix+']"]').first();};
        return {
            cat_id:cat,
            complete:1,revision:box.find('.gnf6-category-revision').val()||'',
            enabled:box.find('input[type="checkbox"][name$="[enabled]"]').first().is(':checked')?1:0,
            post_limit:get('post_limit').val()||1,
            interval:get('interval').val()||'hourly',
            author_id:get('author_id').val()||0,
            rss:get('rss').val()||'',
            urls:get('urls').val()||'',
            instructions:get('instructions').val()||'',
            gdelt_enabled:box.find('input[type=checkbox][name$="[gdelt_enabled]"]').is(':checked')?1:0,
            gdelt_keywords:get('gdelt_keywords').val()||'',gdelt_language:get('gdelt_language').val()||'',gdelt_country:get('gdelt_country').val()||'',
            gdelt_window:get('gdelt_window').val()||'6h',gdelt_results:get('gdelt_results').val()||50,gdelt_interval:get('gdelt_interval').val()||60,
            min_sources:get('min_sources').val()||2,max_candidates:get('max_candidates').val()||50,opportunity_threshold:get('opportunity_threshold').val()||60
        };
    }

    function rememberSavedCategory(cat,r){
        if(!r||!r.data||!r.data.category)return;
        if(r.data.revision)$('#gnf5-cat-'+cat).find('.gnf6-category-revision').val(r.data.revision);
        GNF5Data.catLimits[cat]=parseInt(r.data.category.post_limit||1,10);
        var idx=(GNF5Data.enabledCats||[]).map(String).indexOf(String(cat));
        if(parseInt(r.data.category.enabled||0,10)===1&&idx<0)GNF5Data.enabledCats.push(cat);
        if(parseInt(r.data.category.enabled||0,10)!==1&&idx>=0)GNF5Data.enabledCats.splice(idx,1);
    }

    function saveCategoryBeforeAction(cat,callback){
        var data=categoryFormData(cat);
        if(!data){status('Category panel not found.',false);callback(false);return;}
        categorySaveStatus(cat,catName(cat)+': saving current category settings…');
        call('gnf5_save_category',data).done(function(r){
            if(r&&r.success){
                rememberSavedCategory(cat,r);
                categorySaveStatus(cat,r.data.message,true);
                callback(true,r);
            }else{
                categorySaveStatus(cat,(r&&r.data&&r.data.message)||'Could not save category settings.',false);
                callback(false,r);
            }
        }).fail(function(xhr){
            var msg='Could not save category settings.';
            if(xhr.responseJSON&&xhr.responseJSON.data&&xhr.responseJSON.data.message)msg=xhr.responseJSON.data.message;
            categorySaveStatus(cat,msg,false);callback(false,xhr);
        });
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
        categorySaveStatus(cat,catName(cat)+': saving category settings…');
        handle(call('gnf5_save_category',data),function(ok,r){
            b.prop('disabled',false).text(orig);
            if(ok)rememberSavedCategory(cat,r);
            categorySaveStatus(cat,(r&&r.data&&r.data.message)||(r&&r.responseJSON&&r.responseJSON.data&&r.responseJSON.data.message)||'Save failed. Your changes are still unsaved.',ok);
        });
    });

    function displayCategoryQueue(data){
        var jobs=data.jobs||[], lines=[];
        jobs.forEach(function(job){
            var labels={waiting:'Waiting',claiming:'Starting',dispatched:'Starting',processing:'Processing',completed:'Completed',exhausted:'Exhausted',failed:'Failed',blocked:'Blocked',skipped:'Skipped',cancelled:'Cancelled'};
            var line=catName(job.category)+' — '+(labels[job.state]||job.state)+' · Drafts: '+job.created+(job.target?' / '+job.target:'');
            $('#gnf5-cat-'+job.category).find('.gnf6-category-queue-state').text(line+(job.message?' — '+job.message:''));lines.push(line);
        });
        $('#gnf6-queue-status').text(lines.length?lines.join(' | '):'Queue empty.');
    }
    function pollCategoryQueue(){
        if(!$('#gnf6-queue-status').length)return;
        call('gnf6_category_queue_status',{}).done(function(r){if(r&&r.success)displayCategoryQueue(r.data);})
            .always(function(){setTimeout(pollCategoryQueue,3000);});
    }
    function enqueueCategories(cats,button){
        if(!cats.length){status('Select or enable at least one category.',false);return;}
        button.prop('disabled',true);var i=0;
        // Save all current general fields first, including explicitly empty RSS.
        // Only after every save succeeds is the complete group enqueued.
        function saveNext(){
            if(i<cats.length){saveCategoryBeforeAction(cats[i++],function(ok){if(ok)saveNext();else button.prop('disabled',false);});return;}
            var request=call('gnf6_enqueue_categories',{cat_ids:cats});
            handle(request,function(ok,r){if(ok&&r.data.queue)displayCategoryQueue({jobs:r.data.queue.jobs});});
            request.always(function(){button.prop('disabled',false);});
        }
        saveNext();
    }
    $(document).on('click','.gnf5-run-cat',function(){var b=$(this);enqueueCategories([parseInt(b.data('cat'),10)],b);});
    $(document).on('click','.gnf5-run-all',function(){
        var cats=[];$('.gnf5-category').each(function(){var box=$(this);if(box.find('input[type="checkbox"][name$="[enabled]"]').first().is(':checked'))cats.push(parseInt(box.find('.gnf5-run-cat').data('cat'),10));});enqueueCategories(cats,$(this));
    });
    $(document).on('click','.gnf6-run-selected',function(){var cats=[];$('.gnf6-select-category:checked').each(function(){cats.push(parseInt($(this).val(),10));});enqueueCategories(cats,$(this));});
    $(pollCategoryQueue);

    $(document).on('click','.gnf5-run-manual',function(){var url=$('#gnf5-manual-url').val(),cat=$('#gnf5-manual-cat').val();if(!url){status('Enter an article URL.',false);return;}status('Processing manual article now…');handle(call('gnf5_run_manual',{url:url,cat_id:cat}),function(){setTimeout(function(){location.reload();},800);});});
    $(document).on('click','.gnf5-test-source',function(){var url=$('#gnf5-manual-url').val();if(!url){status('Enter an article URL to test.',false);return;}status('Testing public source extraction…');handle(call('gnf5_test_source',{url:url}));});
    $(document).on('click','.gnf5-test-gemini',function(){status('Testing Gemini with retry protection…');handle(call('gnf5_test_gemini',{}));});
    $(document).on('click','.gnf5-test-image',function(){status('Checking image mode and testing the enabled generator…');handle(call('gnf5_test_image',{}));});
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
    $(document).on('change','#gnf5-log-filter',function(){var value=$(this).val();$('#gnf5-log [data-log-type]').each(function(){$(this).toggle(!value||$(this).attr('data-log-type')===value);});});
})(jQuery);
