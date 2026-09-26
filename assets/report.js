(function($){
    $(document).on('click','.gnf6-action',function(){
        var button=$(this),box=button.closest('.gnf6-report'),task=button.data('action'),confirmed='no';
        if(window.wp && wp.data && wp.data.select('core/editor') && wp.data.select('core/editor').isEditedPostDirty()){
            box.find('.gnf6-result').text('Save your editor changes before using this action.');return;
        }
        var prompts={regenerate:'Replace this Draft’s title and article text with a newly researched article? Your manual images will be preserved. Review your edits before continuing.',regenerate_images:'Remove and regenerate only this article’s generated images? Manual images will be preserved.',remove_images:'Remove only images generated for this article? Manual images will be preserved.'};
        prompts.regenerate_title='Replace this Draft’s headline and SEO title after originality and factual checks? The body and images will stay unchanged. Save your current edits first.';
        if(prompts[task]){if(!window.confirm(prompts[task]))return;confirmed='yes';}
        var attachment=parseInt(button.data('attachment')||0,10),alt='';
        if(attachment)alt=box.find('.gnf6-alt[data-attachment="'+attachment+'"]').val()||'';
        box.find('button').prop('disabled',true);box.find('.gnf6-result').text('Processing this Draft. Publication remains manual…');
        $.post(GNF6Report.ajaxurl,{action:'gnf5_article_action',nonce:GNF6Report.nonce,post_id:box.data('post'),task:task,confirmed:confirmed,attachment_id:attachment,alt:alt})
            .done(function(r){box.find('.gnf6-result').text(r&&r.data&&r.data.message?r.data.message:'Action failed.');})
            .fail(function(xhr){box.find('.gnf6-result').text(xhr.responseJSON&&xhr.responseJSON.data?xhr.responseJSON.data.message:'Request failed. The Draft remains saved.');})
            .always(function(){box.find('button').prop('disabled',false);});
    });
})(jQuery);
