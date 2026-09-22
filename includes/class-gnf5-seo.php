<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_SEO {
    public static function recent_titles($limit = 30) {
        $q = new WP_Query(array(
            'post_type'=>'post','post_status'=>array('publish','draft','pending','future'),
            'posts_per_page'=>max(1,absint($limit)),'orderby'=>'date','order'=>'DESC',
            'fields'=>'ids','no_found_rows'=>true,
        ));
        $out=array();
        foreach($q->posts as $id){
            $seo=trim((string)get_post_meta($id,'rank_math_title',true));
            $title=$seo?:get_the_title($id);
            if($title){$out[]=$title;}
        }
        return array_values(array_unique($out));
    }

    public static function normalize_title($title){
        $title=strtolower(wp_strip_all_tags((string)$title));
        $title=preg_replace('/[^\p{L}\p{N}\s]+/u',' ',$title);
        return trim(preg_replace('/\s+/u',' ',$title));
    }

    public static function title_similarity($a,$b){
        $a=self::normalize_title($a);$b=self::normalize_title($b);
        if(!$a||!$b)return 0;
        if($a===$b)return 100;
        similar_text($a,$b,$pct);
        return (float)$pct;
    }

    public static function title_is_unique($title,$ignore_post_id=0,$focus_keyword=''){
        $title=trim((string)$title);
        if(!$title)return false;
        $exclude=$ignore_post_id?array(absint($ignore_post_id)):array();

        // Global exact Rank Math SEO-title duplicate check.
        $exact_meta=new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,
            'post__not_in'=>$exclude,'meta_key'=>'rank_math_title','meta_value'=>$title,
        ));
        if(!empty($exact_meta->posts))return false;

        // Global exact WordPress-title duplicate check.
        $exact_title=new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,
            'post__not_in'=>$exclude,'title'=>$title,
        ));
        if(!empty($exact_title->posts))return false;

        // Similarity is intentionally limited to recent posts for performance.
        $q=new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>200,'fields'=>'ids',
            'no_found_rows'=>true,'post__not_in'=>$exclude,'orderby'=>'date','order'=>'DESC',
        ));
        foreach($q->posts as $id){
            // Similar wording about different subjects is not the same story.
            // Structured topic fingerprints perform the broader event check.
            if($focus_keyword!=='' && strcasecmp(trim((string)get_post_meta($id,'rank_math_focus_keyword',true)),trim($focus_keyword))!==0)continue;
            $existing=trim((string)get_post_meta($id,'rank_math_title',true));
            if(!$existing)$existing=get_the_title($id);
            if(self::title_similarity($title,$existing)>=82)return false;
        }
        return true;
    }

    public static function recent_focus_keywords($limit=80){
        $limit=max(1,absint($limit));
        $q=new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>$limit,'fields'=>'ids',
            'no_found_rows'=>true,'orderby'=>'date','order'=>'DESC',
        ));
        $out=array();
        foreach((array)$q->posts as $id){
            $raw=(string)get_post_meta($id,'rank_math_focus_keyword',true);
            foreach(explode(',',$raw) as $kw){
                $kw=trim($kw);
                if($kw!=='')$out[strtolower($kw)]=$kw;
            }
        }
        return array_values($out);
    }

    public static function focus_keyword_is_unique($keyword,$ignore_post_id=0){
        $keyword=trim((string)$keyword);
        if($keyword==='')return false;
        $exclude=$ignore_post_id?array(absint($ignore_post_id)):array();

        // LIKE narrows candidates, then exact token comparison avoids substring false positives.
        $q=new WP_Query(array(
            'post_type'=>'post','post_status'=>'any','posts_per_page'=>100,'fields'=>'ids','no_found_rows'=>true,
            'post__not_in'=>$exclude,'meta_key'=>'rank_math_focus_keyword','meta_value'=>$keyword,'meta_compare'=>'LIKE',
        ));
        foreach((array)$q->posts as $id){
            $raw=(string)get_post_meta($id,'rank_math_focus_keyword',true);
            foreach(explode(',',$raw) as $existing){
                if(strcasecmp(trim($existing),$keyword)===0)return false;
            }
        }
        return true;
    }

    public static function has_number($title){return (bool)preg_match('/\b\d+\b/u',(string)$title);}

    private static function rank_math_power_words(){
        static $words=null;
        if(is_array($words))return $words;

        $fallback=array(
            'essential','ultimate','proven','exclusive','expert','smart','key','breakthrough',
            'complete','definitive','effective','valuable','significant','urgent','trusted',
            'powerful','important','major','surprising','revealed','insider','must-know','critical'
        );
        $paths=array();

        if(defined('RANK_MATH_PATH'))$paths[]=trailingslashit(RANK_MATH_PATH).'assets/vendor/powerwords/en.php';
        if(function_exists('rank_math')){
            try{
                $rm=rank_math();
                if(is_object($rm)&&method_exists($rm,'plugin_dir')){
                    $paths[]=trailingslashit($rm->plugin_dir()).'assets/vendor/powerwords/en.php';
                }
            }catch(Throwable $e){}
        }
        if(defined('WP_PLUGIN_DIR'))$paths[]=trailingslashit(WP_PLUGIN_DIR).'seo-by-rank-math/assets/vendor/powerwords/en.php';

        foreach(array_unique($paths) as $file){
            if(!$file||!is_readable($file))continue;
            $loaded=include $file;
            if(is_array($loaded)&&$loaded){
                $clean=array();
                foreach($loaded as $word){
                    $word=trim((string)$word);
                    if($word!=='')$clean[]=$word;
                }
                if($clean){$words=array_values(array_unique($clean));return $words;}
            }
        }
        $words=$fallback;
        return $words;
    }

    public static function has_power_word($title){
        return self::contains_any_word($title,self::rank_math_power_words());
    }

    public static function has_sentiment_word($title){
        return self::contains_any_word($title,array(
            'good','great','best','positive','impressive','successful','winning','exciting',
            'remarkable','promising','better','bad','worst','negative','alarming','shocking',
            'dangerous','serious','weak','disappointing','controversial','worrying','stunning'
        ));
    }

    private static function contains_any_word($text,$words){
        $text=' '.self::normalize_title($text).' ';
        foreach($words as $w){$needle=self::normalize_title($w);if($needle!==''&&strpos($text,' '.$needle.' ')!==false)return true;}
        return false;
    }

    public static function contains_exact_phrase($text,$keyword) {
        $keyword=trim((string)$keyword);
        if($keyword==='')return false;
        $text=html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $pattern='/(?<![\p{L}\p{N}])'.preg_quote($keyword,'/').'(?![\p{L}\p{N}])/iu';
        return (bool)preg_match($pattern,$text);
    }

    public static function begins_with_exact_phrase($text,$keyword) {
        $keyword=trim((string)$keyword);
        if($keyword==='')return false;
        $text=ltrim(html_entity_decode(wp_strip_all_tags((string)$text),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $pattern='/^'.preg_quote($keyword,'/').'(?![\p{L}\p{N}])/iu';
        return (bool)preg_match($pattern,$text);
    }

    public static function limit_slug($value,$max=75){
        $max=max(10,absint($max));
        $slug=trim(sanitize_title((string)$value),'-');
        if(strlen($slug)<=$max)return $slug;

        $out='';
        foreach(explode('-',$slug) as $segment){
            if($segment==='')continue;
            $candidate=$out===''?$segment:$out.'-'.$segment;
            if(strlen($candidate)<=$max){$out=$candidate;continue;}
            if($out!=='')break;

            // A single long segment (common with percent-encoded non-Latin slugs).
            // Keep complete %XX tokens / Unicode characters only.
            if(preg_match_all('/%[0-9A-Fa-f]{2}|./u',$segment,$m)){
                foreach($m[0] as $token){
                    if(strlen($out.$token)>$max)break;
                    $out.=$token;
                }
            }
            break;
        }
        return trim($out,'-');
    }

    public static function slug_contains_focus_keyword($slug,$keyword) {
        $slug=trim((string)$slug,'-');
        $kw_slug=trim(sanitize_title((string)$keyword),'-');
        if($slug===''||$kw_slug==='')return false;
        return (bool)preg_match('/(?:^|-)'.preg_quote($kw_slug,'/').'(?:-|$)/i',$slug);
    }

    public static function heading_contains_exact_phrase($html,$keyword) {
        $keyword=trim((string)$keyword);
        if($keyword==='')return false;
        if(!class_exists('DOMDocument')){
            return (bool)preg_match('/<h[23][^>]*>.*?(?<![\p{L}\p{N}])'.preg_quote($keyword,'/').'(?![\p{L}\p{N}]).*?<\/h[23]>/isu',$html);
        }
        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        foreach(array('h2','h3') as $tag){
            foreach($doc->getElementsByTagName($tag) as $node){
                if(self::contains_exact_phrase($node->textContent,$keyword))return true;
            }
        }
        return false;
    }

    public static function keyword_occurrences($html,$keyword){
        $text=html_entity_decode(wp_strip_all_tags((string)$html),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $keyword=trim((string)$keyword);
        if($keyword==='')return 0;

        $text=preg_replace('/\s+/u',' ',function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text));
        $keyword=preg_replace('/\s+/u',' ',function_exists('mb_strtolower')?mb_strtolower($keyword,'UTF-8'):strtolower($keyword));

        // Count the exact phrase as a phrase, not as letters inside unrelated words.
        $pattern='/(?<![\p{L}\p{N}])'.preg_quote($keyword,'/').'(?![\p{L}\p{N}])/u';
        $count=preg_match_all($pattern,$text,$matches);
        return $count===false?0:absint($count);
    }

    public static function keyword_density($html,$keyword){
        // Match Rank Math's displayed density more closely: exact focus-keyword
        // combination occurrences divided by total article words.
        $wc=max(1,GNF5_Utils::word_count($html));
        return (self::keyword_occurrences($html,$keyword)/$wc)*100;
    }

    public static function sanitize_article_data($d,$fallback_title=''){
        $out=array();
        $out['title']=sanitize_text_field($d['title']??$fallback_title);
        $out['seo_title']=sanitize_text_field($d['seo_title']??$out['title']);
        $out['focus_keyword']=sanitize_text_field($d['focus_keyword']??'');
        $out['slug']=self::limit_slug($d['slug']??$out['title'],75);
        $out['meta_description']=sanitize_text_field($d['meta_description']??'');
        $out['excerpt']=sanitize_textarea_field($d['excerpt']??'');
        $html=wp_kses($d['content_html']??'', array('p'=>array(),'h2'=>array(),'h3'=>array(),'h4'=>array(),'h5'=>array(),'ul'=>array(),'ol'=>array(),'li'=>array(),'strong'=>array(),'em'=>array(),'b'=>array(),'i'=>array(),'blockquote'=>array(),'table'=>array(),'thead'=>array(),'tbody'=>array(),'tr'=>array(),'th'=>array(),'td'=>array(),'br'=>array()));
        $html=preg_replace('/<h1\b[^>]*>.*?<\/h1>/is','',$html);
        $html=preg_replace('/<(?:picture|figure)\b[^>]*>.*?<\/(?:picture|figure)>/is','',$html);
        $html=preg_replace('/<img\b[^>]*>/is','',$html);
        // AI-generated article text is never allowed to choose links. Preserve the anchor text,
        // but remove generated hrefs; the plugin later inserts verified same-category internal
        // links and administrator-supplied trusted external links itself.
        $html=preg_replace('/<a\b[^>]*>(.*?)<\/a>/is','$1',$html);
        $out['content_html']=self::public_article_html($html);

        $tags=is_array($d['tags']??null)?$d['tags']:array();
        $out['tags']=array();$seen_tags=array();
        foreach($tags as $tag){
            $tag=sanitize_text_field($tag);if($tag==='')continue;
            $key=function_exists('mb_strtolower')?mb_strtolower($tag,'UTF-8'):strtolower($tag);
            if(isset($seen_tags[$key]))continue;
            $seen_tags[$key]=true;$out['tags'][]=$tag;
            if(count($out['tags'])>=8)break;
        }

        $prompts=is_array($d['image_prompts']??null)?$d['image_prompts']:array();
        $alts=is_array($d['image_alts']??null)?$d['image_alts']:array();
        $out['image_prompts']=$out['image_alts']=array();
        for($i=0;$i<2;$i++){
            $out['image_prompts'][$i]=sanitize_textarea_field($prompts[$i]??('Original editorial illustration about '.$out['title'].', scene '.($i+1).', no text, no logo, no watermark.'));
            $out['image_alts'][$i]=sanitize_text_field($alts[$i]??(($out['focus_keyword']?:$out['title']).' editorial image '.($i+1)));
        }
        if(strcasecmp(trim($out['image_prompts'][0]),trim($out['image_prompts'][1]))===0){
            $out['image_prompts'][1].=' Use a clearly different composition, viewpoint, subject arrangement, and visual concept from image 1.';
        }
        if(trim($out['image_alts'][1])==='' || strcasecmp(trim($out['image_alts'][0]),trim($out['image_alts'][1]))===0){
            $out['image_alts'][1]=sanitize_text_field(($out['title']?:'Article').' — supporting editorial image');
        }
        return self::normalize_metadata($out);
    }

    public static function normalize_metadata($d) {
        $d['slug'] = self::limit_slug($d['slug'] ?? ($d['title'] ?? ''), 75);
        // No invented year, sentiment, keyword padding or filler description.
        $d['meta_description'] = self::trim_chars_word_boundary(trim((string)($d['meta_description'] ?? '')), 160);
        return $d;
    }

    private static function char_len($text){return function_exists('mb_strlen')?mb_strlen($text):strlen($text);}

    private static function trim_chars_word_boundary($text,$max){
        if(self::char_len($text)<=$max)return trim($text);
        $s=GNF5_Utils::safe_substr($text,0,$max);
        $s=preg_replace('/\s+\S*$/u','',$s);
        return rtrim($s," \t\n\r\0\x0B,;:-").'.';
    }

    public static function split_long_paragraphs($html,$max_words=70){
        if(!class_exists('DOMDocument'))return $html;
        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="gnf5-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $xp=new DOMXPath($doc);
        $nodes=array();
        foreach($xp->query('//*[@id="gnf5-root"]//p') as $p)$nodes[]=$p;

        foreach($nodes as $p){
            $plain=trim(preg_replace('/\s+/u',' ',$p->textContent));
            if(GNF5_Utils::word_count($plain)<=$max_words)continue;
            $sentences=preg_split('/(?<=[.!?])\s+/u',$plain,-1,PREG_SPLIT_NO_EMPTY);
            if(count($sentences)<2)continue;
            $groups=array();$cur='';
            foreach($sentences as $sentence){
                $test=trim($cur.' '.$sentence);
                if($cur!=='' && GNF5_Utils::word_count($test)>55){$groups[]=$cur;$cur=trim($sentence);}
                else{$cur=$test;}
            }
            if($cur!=='')$groups[]=$cur;
            if(count($groups)<2)continue;

            $parent=$p->parentNode;
            foreach($groups as $g){
                $np=$doc->createElement('p');
                $np->appendChild($doc->createTextNode($g));
                $parent->insertBefore($np,$p);
            }
            $parent->removeChild($p);
        }

        $root=$doc->getElementById('gnf5-root');
        $out='';
        if($root)foreach($root->childNodes as $n)$out.=$doc->saveHTML($n);
        return $out?:$html;
    }

    public static function build_gutenberg_content($html){
        $html=self::split_long_paragraphs($html,70);
        if(!class_exists('DOMDocument') || !function_exists('serialize_blocks'))return $html;

        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="gnf5-root">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $root=$doc->getElementById('gnf5-root');
        if(!$root)return $html;

        $blocks=array();$headings=array();$used=array();
        $children=array();
        foreach($root->childNodes as $child)$children[]=$child;

        foreach($children as $node){
            if($node->nodeType===XML_TEXT_NODE){
                $v=trim($node->textContent);
                if($v!=='')$blocks[]=self::block_array('core/paragraph',array(),'<p>'.esc_html($v).'</p>');
                continue;
            }
            if($node->nodeType!==XML_ELEMENT_NODE)continue;
            $tag=strtolower($node->nodeName);
            $inner=self::inner_html($doc,$node);

            if(in_array($tag,array('h2','h3','h4','h5'),true)){
                $level=(int)substr($tag,1);
                $plain=trim(wp_strip_all_tags($inner));
                $anchor=sanitize_title($plain)?:'section';
                $base=$anchor;$n=2;
                while(isset($used[$anchor]))$anchor=$base.'-'.$n++;
                $used[$anchor]=true;

                $attrs=array('anchor'=>$anchor);
                if($level!==2)$attrs['level']=$level;
                $block_html='<'.$tag.' class="wp-block-heading" id="'.esc_attr($anchor).'">'.$inner.'</'.$tag.'>';
                $blocks[]=self::block_array('core/heading',$attrs,$block_html);

                if(in_array($level,array(2,3),true)){
                    $headings[]=array(
                        'key'=>'toc-'.time().'-'.substr(wp_generate_password(10,false,false),0,10),
                        'content'=>$plain,'link'=>'#'.$anchor,'level'=>$level,'disable'=>false,
                    );
                }
            } elseif($tag==='p'){
                $p='<p>'.$inner.'</p>';
                if(trim(wp_strip_all_tags($p))!=='')$blocks[]=self::block_array('core/paragraph',array(),$p);
            } elseif($tag==='ul' || $tag==='ol'){
                $blocks[]=self::list_block_from_dom($doc,$node,$tag==='ol');
            } elseif($tag==='blockquote'){
                // Keep uncommon complex quote markup as a valid Custom HTML block instead of
                // emitting an incomplete core/quote serialization that Gutenberg may reject.
                $blocks[]=self::block_array('core/html',array(),$doc->saveHTML($node));
            } else {
                $raw=$doc->saveHTML($node);
                $blocks[]=self::block_array('core/html',array(),$raw);
            }
        }

        if(!empty(GNF5_Utils::settings()['toc_enabled']) && count($headings)>=2){
            array_unshift($blocks,self::rank_math_toc_block($headings));
        }
        return serialize_blocks($blocks);
    }

    private static function inner_html($doc,$node){
        $html='';
        foreach($node->childNodes as $child)$html.=$doc->saveHTML($child);
        return wp_kses_post($html);
    }

    private static function block_array($name,$attrs,$html){
        return array('blockName'=>$name,'attrs'=>$attrs,'innerBlocks'=>array(),'innerHTML'=>$html,'innerContent'=>array($html));
    }

    private static function list_block_from_dom($doc,$node,$ordered=false){
        $tag=$ordered?'ol':'ul';
        $attrs=$ordered?array('ordered'=>true):array();
        $inner_blocks=array();
        $inner_content=array('<'.$tag.' class="wp-block-list">');
        $html='<'.$tag.' class="wp-block-list">';
        foreach($node->childNodes as $child){
            if($child->nodeType!==XML_ELEMENT_NODE || strtolower($child->nodeName)!=='li')continue;
            $li_inner=self::inner_html($doc,$child);
            $li_html='<li>'.$li_inner.'</li>';
            $inner_blocks[]=self::block_array('core/list-item',array(),$li_html);
            $inner_content[]=null;
            $html.=$li_html;
        }
        $inner_content[]='</'.$tag.'>';
        $html.='</'.$tag.'>';
        if(!$inner_blocks){
            return self::block_array('core/html',array(),$doc->saveHTML($node));
        }
        return array('blockName'=>'core/list','attrs'=>$attrs,'innerBlocks'=>$inner_blocks,'innerHTML'=>$html,'innerContent'=>$inner_content);
    }

    private static function simple_list_block($items_html){
        if(!class_exists('DOMDocument'))return self::block_array('core/html',array(),'<ul>'.$items_html.'</ul>');
        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><ul id="gnf5-list">'.$items_html.'</ul>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $node=$doc->getElementById('gnf5-list');
        return $node?self::list_block_from_dom($doc,$node,false):self::block_array('core/html',array(),'<ul>'.$items_html.'</ul>');
    }

    private static function rank_math_toc_block($headings){
        $attrs=array(
            'title'=>'Table of Contents','headings'=>$headings,'listStyle'=>'ul','titleWrapper'=>'h2',
            'excludeHeadings'=>array('h1','h4','h5','h6')
        );
        $tree=self::toc_tree($headings);
        $html='<div class="wp-block-rank-math-toc-block"><h2>Table of Contents</h2><nav><ul>';
        $html.=self::toc_tree_html($tree,'ul');
        $html.='</ul></nav></div>';
        return self::block_array('rank-math/toc-block',$attrs,$html);
    }

    private static function toc_tree($items){
        $out=array();
        if(!$items)return $out;
        $base=(int)$items[0]['level'];
        $count=count($items);
        for($i=0;$i<$count;$i++){
            if((int)$items[$i]['level']!==$base)continue;
            $next=$count;
            for($j=$i+1;$j<$count;$j++){
                if((int)$items[$j]['level']===$base){$next=$j;break;}
            }
            $child_slice=array_slice($items,$i+1,$next-$i-1);
            $out[]=array('heading'=>$items[$i],'children'=>$child_slice?self::toc_tree($child_slice):array());
            $i=$next-1;
        }
        return $out;
    }

    private static function toc_tree_html($tree,$list_style='ul'){
        $out='';
        foreach($tree as $entry){
            $h=$entry['heading'];
            if(!empty($h['disable']))continue;
            $out.='<li><a href="'.esc_attr($h['link']).'">'.esc_html($h['content']).'</a>';
            if(!empty($entry['children'])){
                $out.='<'.$list_style.'>'.self::toc_tree_html($entry['children'],$list_style).'</'.$list_style.'>';
            }
            $out.='</li>';
        }
        return $out;
    }

    public static function related_links($post_id, $cat_id, $limit=3) {
        // Retained API; insert only topically relevant real published posts.
        $kw = (string)get_post_meta($post_id, 'rank_math_focus_keyword', true);
        if (!$kw) return '';
        $q = get_posts(array('post_type'=>'post','post_status'=>'publish','numberposts'=>20,
            'category'=>$cat_id,'post__not_in'=>array($post_id),'s'=>$kw));
        $items = array();
        foreach ($q as $p) {
            if (!self::contains_exact_phrase($p->post_title.' '.$p->post_content, $kw)) continue;
            $items[] = '<a href="'.esc_url(get_permalink($p->ID)).'">'.esc_html(get_the_title($p->ID)).'</a>';
            if (count($items) >= $limit) break;
        }
        return $items ? self::simple_heading('Related reading','related-reading').self::simple_list($items) : '';
    }

    private static function link_terms($text) {
        $stop=array('the','and','for','with','from','this','that','your','what','when','where','which','about','into','have','has','are','was','were','will','can','how','why','new','news','latest','update','guide');
        return array_values(array_unique(array_filter(preg_split('/\s+/u',self::normalize_title($text)),function($w)use($stop){return strlen($w)>2 && !in_array($w,$stop,true);}))); 
    }

    /** Insert at most one link into existing paragraph text, never inside another link. */
    private static function link_phrase($html,$url,$phrases,&$inserted) {
        $inserted=false;if(!class_exists('DOMDocument'))return $html;
        $previous=libxml_use_internal_errors(true);$doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="gnf-links">'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
        $xp=new DOMXPath($doc);
        foreach($phrases as $phrase){
            $phrase=trim(wp_strip_all_tags($phrase));if(strlen($phrase)<3)continue;
            foreach($xp->query('//*[@id="gnf-links"]//p//text()[not(ancestor::a)]') as $n){
                if(!preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase,'/').'(?![\p{L}\p{N}])/iu',$n->nodeValue,$m,PREG_OFFSET_CAPTURE))continue;
                $at=$m[0][1];$text=$m[0][0];$parent=$n->parentNode;
                $parent->insertBefore($doc->createTextNode(substr($n->nodeValue,0,$at)),$n);
                $a=$doc->createElement('a');$a->setAttribute('href',$url);$a->appendChild($doc->createTextNode($text));
                $parent->insertBefore($a,$n);$parent->insertBefore($doc->createTextNode(substr($n->nodeValue,$at+strlen($text))),$n);$parent->removeChild($n);
                $inserted=true;break 2;
            }
        }
        $root=$doc->getElementById('gnf-links');$out='';if($root)foreach($root->childNodes as $n)$out.=$doc->saveHTML($n);
        libxml_clear_errors();libxml_use_internal_errors($previous);
        return $inserted && $out!==''?$out:$html;
    }

    public static function insert_internal_links($html, $post_id, $cat_id) {
        if (empty(GNF5_Utils::settings()['internal_links']) || !class_exists('DOMDocument')) return $html;
        $kw = trim((string)get_post_meta($post_id, 'rank_math_focus_keyword', true));
        if ($kw === '') return $html;
        $terms=self::link_terms($kw.' '.get_the_title($post_id));
        $args=array('post_type'=>'post','post_status'=>'publish','numberposts'=>40,'post__not_in'=>array($post_id),'has_password'=>false);
        $posts=get_posts(array_merge($args,array('category'=>$cat_id)));$seen=array();$ranked=array();
        // Broaden beyond exact-keyword matches and beyond this category, with bounded queries.
        foreach(array_slice(self::link_terms($kw),0,2) as $term)$posts=array_merge($posts,get_posts(array_merge($args,array('s'=>$term))));
        foreach($posts as $p){
            if(isset($seen[$p->ID]) || $p->post_password || $p->post_status!=='publish')continue;$seen[$p->ID]=true;
            $other=trim(explode(',',(string)get_post_meta($p->ID,'rank_math_focus_keyword',true))[0]);
            $shared=count(array_intersect($terms,self::link_terms($p->post_title.' '.$other)));
            $exact=count(self::link_terms($kw))>=2 && self::contains_exact_phrase($p->post_title.' '.$other,$kw);
            if(!$exact && $shared<2)continue;
            $ranked[]=array('post'=>$p,'anchor'=>$other,'rank'=>$shared+($exact?4:0)+(has_category($cat_id,$p)?1:0));
        }
        usort($ranked,function($a,$b){return $b['rank']<=>$a['rank'];});$count=0;$related=array();
        foreach($ranked as $row){
            $p=$row['post'];$url=get_permalink($p->ID);if(!$url || strpos(html_entity_decode($html,ENT_QUOTES,'UTF-8'),$url)!==false)continue;
            $phrases=array_filter(array($row['anchor'],$p->post_title),function($s){return GNF5_Utils::word_count($s)>=2;});
            $html=self::link_phrase($html,$url,$phrases,$inserted);
            if(!$inserted)$related[]='<li><a href="'.esc_url($url).'">'.esc_html($p->post_title).'</a></li>';
            if(++$count>=3)break;
        }
        return $html.($related?'<h2>Related reading</h2><ul>'.implode('',$related).'</ul>':'');
    }

    /** Only for generated article data, never run over human-edited WordPress content. */
    public static function public_article_html($html) {
        if(!empty(GNF5_Utils::settings()['auto_source_links']))return $html;
        // A writer must not bypass the public-source policy with a raw URL or appended bibliography.
        $html=preg_replace('~<h([2-6])\b[^>]*>\s*(?:Sources?|References?|External links|Source credits?)\s*:?</h\1>.*?(?=<h[2-6]\b|$)~is','',$html);
        $html=preg_replace('~<p\b[^>]*>\s*(?:<(?:strong|b)>\s*)?(?:Source|Source credit|Sources|References)\s*:.*?</p>~is','',$html);
        return preg_replace('~https?://[^\s<>"\']+~iu','',$html);
    }

    /** Research visibility and manual editorial links are independent, explicit choices. */
    public static function insert_external_links($html,$post_id,$cat_id) {
        $settings=GNF5_Utils::settings();$cs=GNF5_Utils::category_settings($cat_id,$settings);
        $research=get_post_meta($post_id,'_gnf5_research',true);$research=is_array($research)?$research:array();
        $home=preg_replace('/^www\./','',strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)));
        $candidates=array();$counts=array('manual'=>0,'research'=>0);$seen=array();
        // Existing legacy research URLs remain private; they are not manual-link consent.
        if(!empty($cs['manual_links_enabled']) && $cs['manual_links_max']>0){
            $rows=(array)$cs['manual_links'];
            // Stable partition: Preferred first, retaining the administrator's order within each group.
            $rows=array_merge(array_values(array_filter($rows,function($r){return ($r['usage']??'optional')==='preferred';})),array_values(array_filter($rows,function($r){return ($r['usage']??'optional')!=='preferred';})));
            $article_terms=self::link_terms(wp_strip_all_tags($html));
            foreach($rows as $row){
                if(empty($row['enabled']))continue;
                $context=trim(($row['anchor']??'').' '.($row['note']??''));
                $terms=self::link_terms($context);
                $url_terms=self::link_terms(str_replace(array('-','_','/','.'),' ',urldecode((string)wp_parse_url($row['url']??'',PHP_URL_HOST).' '.(string)wp_parse_url($row['url']??'',PHP_URL_PATH))));
                // Both the supplied context and URL identity must have support in this article.
                // Conservative matching skips unclear/generic links rather than manufacturing a paragraph.
                if(count(array_intersect($terms,$article_terms))<2 || !array_intersect($url_terms,$article_terms))continue;
                $phrases=array_filter(array($row['anchor']??'', $row['note']??''),function($p){return GNF5_Utils::word_count($p)>=2 && !preg_match('~https?://~i',$p);});
                $candidates[]=array('url'=>$row['url'],'phrases'=>$phrases,'kind'=>'manual');
            }
        }
        if(!empty($settings['auto_source_links']))foreach((array)($research['primary_sources']??array()) as $primary){
            foreach((array)($research['sources']??array()) as $source){
                if(($source['id']??'')!==($primary['source']??''))continue;
                $phrases=array();foreach((array)($research['facts']??array()) as $fact){
                    if(in_array($source['id'],array_column((array)($fact['evidence']??array()),'source'),true))$phrases[]=$fact['subject'];
                }
                if($phrases)$candidates[]=array('url'=>$source['url'],'phrases'=>array_unique($phrases),'kind'=>'research');
            }
        }
        // Respect pre-existing links and avoid repeating a domain.
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)/i',$html,$existing);
        foreach($existing[1] as $href){$host=GNF5_Topics::publisher_key(html_entity_decode($href,ENT_QUOTES,'UTF-8'));if($host)$seen[$host]=true;}
        foreach(array_slice($candidates,0,35) as $candidate){
            $kind=$candidate['kind'];$limit=$kind==='manual'?(int)$cs['manual_links_max']:2;
            if($counts[$kind]>=$limit)continue;
            $url=GNF5_Utils::normalize_url($candidate['url']);$host=preg_replace('/^www\./','',strtolower((string)wp_parse_url($url,PHP_URL_HOST)));
            $domain=GNF5_Topics::publisher_key($url);
            if(!$url || !$host || $host===$home || isset($seen[$domain]))continue;
            $proposed=self::link_phrase($html,$url,$candidate['phrases'],$inserted);
            if(!$inserted)continue;
            $check=GNF5_Sources::test_external_link($url,false);
            if(is_wp_error($check) || ($check['status']??'')!=='ok')continue;
            $html=$proposed;$seen[$domain]=true;$counts[$kind]++;
        }
        update_post_meta($post_id,'_gnf5_link_insertion',array('manual'=>$counts['manual'],'research'=>$counts['research'],
            'auto_source_links'=>!empty($settings['auto_source_links']),'manual_links_enabled'=>!empty($cs['manual_links_enabled'])));
        return $html;
    }

    public static function link_report($post_id) {
        $cats=wp_get_post_categories($post_id);$cs=GNF5_Utils::category_settings((int)($cats[0]??0));
        $audit=self::anchor_audit(get_post_field('post_content',$post_id),$post_id);
        $inserted=(array)get_post_meta($post_id,'_gnf5_link_insertion',true);
        return array('Internal links'=>$audit['valid_internal'],'Automatic research/source links'=>!empty(GNF5_Utils::settings()['auto_source_links'])?'ON':'OFF',
            'Category manual external links'=>!empty($cs['manual_links_enabled'])?'ON':'OFF','External links in article'=>$audit['external'],
            'Manual links inserted at last composition'=>(int)($inserted['manual']??0),'Research links inserted at last composition'=>(int)($inserted['research']??0),
            'Optional links'=>'No links is OK when disabled, empty or not relevant. Rank Math may still flag its own external-link checks.');
    }

    /** Local diagnostics, never a fabricated Rank Math score. Image checks are deliberately separate. */
    public static function text_checks($d) {
        $html=$d['content_html']??'';$plain=trim(wp_strip_all_tags(str_replace(array('</p>','</h2>','</h3>','</li>'), ' ', $html)));
        $kw=trim(explode(',',(string)($d['focus_keyword']??''))[0]);$title=$d['seo_title']??$d['title']??'';
        $words=GNF5_Utils::word_count($html);$density=self::keyword_density($html,$kw);
        $start=implode(' ',array_slice(preg_split('/\s+/u',$plain),0,max(1,(int)ceil($words*0.1))));
        $checks=array();
        $add=function($key,$ok,$message,$repair=true)use(&$checks){$checks[$key]=array('status'=>$ok?'PASS':'REVIEW','message'=>$message,'repair'=>$repair);};
        $add('keyword', $kw!=='','Choose a specific focus keyword that accurately describes the verified topic.');
        $add('title_keyword',self::contains_exact_phrase($title,$kw),'Use the focus keyword naturally in the SEO title.');
        $add('title_start',$kw!=='' && stripos(trim($title),$kw)===0,'Start the SEO title with the focus keyword when it reads naturally.');
        $add('description',self::contains_exact_phrase($d['meta_description']??'',$kw),'Include the focus keyword naturally in the meta description.');
        $add('slug',self::slug_contains_focus_keyword($d['slug']??'',$kw),'Include the focus keyword in the URL slug without making it long.');
        $add('introduction',self::contains_exact_phrase($start,$kw),'Use the focus keyword within the first 10% of the article.');
        $add('body_keyword',self::contains_exact_phrase($plain,$kw),'Use the focus keyword naturally in the article body.');
        $add('heading',self::heading_contains_exact_phrase($html,$kw),'Use the focus keyword in a relevant H2 or H3.');
        $add('length',$words>=600,'Article has '.$words.' words. Aim for at least 600, preferably 1000–1200, only with supported useful explanations. If evidence cannot support expansion, provide short_reason; never pad or invent facts.');
        $add('density',$density>=1 && $density<=1.5,'Focus-keyword density is '.round($density,2).'%. Aim around 1–1.5% through natural references, never repetitive filler.');
        $add('url_length',strlen($d['slug']??'')<=75,'Keep the URL slug concise (75 characters or fewer).');
        $add('sentiment',self::has_sentiment_word($title),'Use a positive or negative title word only if the verified facts justify it; otherwise retain a neutral headline.');
        $add('power_word',self::has_power_word($title),'Use an accurate descriptive power word only when supported; do not exaggerate.');
        $add('title_number',self::has_number($title),'Use a verified number or a truthful list count only when useful; never invent a date or number.');
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is',$html,$pars);$long=false;foreach($pars[1] as $p)if(GNF5_Utils::word_count($p)>120)$long=true;
        $add('paragraphs',!$long,'Break long paragraphs into shorter readable paragraphs.');
        return $checks;
    }

    public static function text_feedback($d) {
        $out=array();foreach(self::text_checks($d) as $check)if($check['status']!=='PASS' && $check['repair'])$out[]=$check['message'];
        return $out;
    }

    public static function checklist($post_id) {
        $d=array('title'=>get_the_title($post_id),'seo_title'=>get_post_meta($post_id,'rank_math_title',true)?:get_the_title($post_id),
            'focus_keyword'=>get_post_meta($post_id,'rank_math_focus_keyword',true),'meta_description'=>get_post_meta($post_id,'rank_math_description',true),
            'slug'=>get_post_field('post_name',$post_id),'content_html'=>get_post_field('post_content',$post_id));
        $checks=self::text_checks($d);$links=self::anchor_audit($d['content_html'],$post_id);
        $checks['internal_links']=array('status'=>$links['valid_internal']?'PASS':'REVIEW','message'=>'Relevant published internal links: '.$links['valid_internal'].'. If zero, enable internal links and ensure related published posts exist.');
        $checks['external_links']=array('status'=>$links['external']?'PASS':'OPTIONAL','message'=>'External links: '.$links['external'].'. Zero is allowed when disabled, empty or no suitable contextual link exists.');
        $checks['dofollow']=array('status'=>$links['dofollow_external']?'PASS':'OPTIONAL','message'=>'Followable external links: '.$links['dofollow_external'].'. Only validated editorial links are added.');
        $checks['keyword_unique']=array('status'=>self::focus_keyword_is_unique($d['focus_keyword'],$post_id)?'PASS':'REVIEW','message'=>'A reused focus keyword needs editorial review; do not change the article topic simply to pass this check.');
        $checks['toc']=array('status'=>has_block('rank-math/toc-block',$d['content_html'])?'PASS':'REVIEW','message'=>'Table of contents is added when enabled and at least two headings exist.');
        return $checks;
    }

    public static function external_links_section($links_text,$source_url=''){ return ''; }

    public static function rank_math_toc_block_registered(){
        if(!class_exists('WP_Block_Type_Registry'))return true;
        try{return WP_Block_Type_Registry::get_instance()->is_registered('rank-math/toc-block');}catch(Throwable $e){}
        return true;
    }

    public static function rank_math_schema_module_active(){
        if(!defined('RANK_MATH_VERSION'))return false;
        if(class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper','is_module_active')){
            try{
                // Rank Math's current internal module id for Schema/Rich Snippets is "rich-snippet".
                if(\RankMath\Helper::is_module_active('rich-snippet'))return true;
                // Compatibility fallback for a future/alternate module label.
                if(\RankMath\Helper::is_module_active('schema'))return true;
            }catch(Throwable $e){}
        }
        // If Rank Math registered its own TOC block, the required block/schema feature is available.
        return self::rank_math_toc_block_registered();
    }

    public static function rank_math_schema_types($post_id){
        $post_id=absint($post_id);$types=array();
        if(!$post_id)return $types;

        if(class_exists('\RankMath\Schema\DB') && method_exists('\RankMath\Schema\DB','get_schemas')){
            try{
                $schemas=\RankMath\Schema\DB::get_schemas($post_id);
                foreach((array)$schemas as $schema){
                    if(!is_array($schema)||empty($schema['@type']))continue;
                    foreach((array)$schema['@type'] as $type){
                        $type=sanitize_text_field((string)$type);
                        if($type!=='')$types[]=$type;
                    }
                }
            }catch(Throwable $e){}
        }

        // Fallback direct-meta scan, useful across Rank Math versions.
        if(!$types){
            foreach((array)get_post_meta($post_id) as $key=>$values){
                if(strpos((string)$key,'rank_math_schema_')!==0)continue;
                foreach((array)$values as $raw){
                    $schema=maybe_unserialize($raw);
                    if(!is_array($schema)||empty($schema['@type']))continue;
                    foreach((array)$schema['@type'] as $type){
                        $type=sanitize_text_field((string)$type);
                        if($type!=='')$types[]=$type;
                    }
                }
            }
        }

        if(!$types && class_exists('\RankMath\Helper') && method_exists('\RankMath\Helper','get_default_schema_type')){
            try{
                $default=\RankMath\Helper::get_default_schema_type($post_id);
                if($default)$types[]=sanitize_text_field((string)$default);
            }catch(Throwable $e){}
        }
        return array_values(array_unique(array_filter($types)));
    }

    private static function has_article_schema_type($post_id){
        foreach(self::rank_math_schema_types($post_id) as $type){
            if(in_array(strtolower((string)$type),array('article','newsarticle','blogposting','reportagenewsarticle'),true))return true;
        }
        return false;
    }

    public static function save_rank_math($post_id,$d){
        if(empty(GNF5_Utils::settings()['rankmath_enabled']))return;
        $d=self::normalize_metadata($d);
        $values=array('rank_math_title'=>$d['seo_title'],'rank_math_description'=>$d['meta_description'],
            'rank_math_focus_keyword'=>$d['focus_keyword'],'rank_math_facebook_title'=>$d['seo_title'],
            'rank_math_facebook_description'=>$d['meta_description'],'rank_math_twitter_title'=>$d['seo_title'],
            'rank_math_twitter_description'=>$d['meta_description']);
        $cats=wp_get_post_categories($post_id);
        if(!empty($cats[0]))$values['rank_math_primary_category']=absint($cats[0]);
        $written=(array)get_post_meta($post_id,'_gnf5_rankmath_written',true);
        foreach($values as $key=>$value){
            $current=get_post_meta($post_id,$key,true);
            // Only replace empty values or values still owned by this writer.
            // Legacy/custom metadata without an ownership record is preserved.
            if($current==='' || (array_key_exists($key,$written) && (string)$current===(string)$written[$key])){
                update_post_meta($post_id,$key,$value);
                $written[$key]=$value;
            }
        }
        update_post_meta($post_id,'_gnf5_rankmath_written',$written);
    }

    private static function faq_question_count($content){
        if(!class_exists('DOMDocument'))return substr_count(strtolower((string)$content),'<h3');
        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="gnf5-check-root">'.$content.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $xp=new DOMXPath($doc);$in_faq=false;$count=0;
        foreach($xp->query('//*[@id="gnf5-check-root"]//*[self::h2 or self::h3]') as $node){
            $tag=strtolower($node->nodeName);$text=trim($node->textContent);
            if($tag==='h2'){
                if(preg_match('/frequently asked questions|\bfaq\b/i',$text)){$in_faq=true;continue;}
                if($in_faq)break;
            }
            if($in_faq && $tag==='h3')$count++;
        }
        return $count;
    }

    private static function anchor_audit($content,$post_id){
        $out=array('internal'=>0,'valid_internal'=>0,'external'=>0,'dofollow_external'=>0,'source_visible'=>false);
        if(!class_exists('DOMDocument'))return $out;
        libxml_use_internal_errors(true);$doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div>'.$content.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        $home=preg_replace('/^www\./i','',(string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        $source=GNF5_Utils::normalize_url((string)get_post_meta($post_id,'_gnf5_source_url',true));
        foreach($doc->getElementsByTagName('a') as $a){
            $raw=html_entity_decode($a->getAttribute('href'),ENT_QUOTES,'UTF-8');$parts=wp_parse_url($raw);
            if(!$parts || !in_array($parts['scheme']??'',array('http','https'),true) || isset($parts['user']) || isset($parts['pass']))continue;
            $local=preg_replace('/^www\./i','',(string)($parts['host']??''))===$home;
            // Local links use a database lookup, not an outbound fetch. This also supports staging hosts.
            $href=$local?$raw:GNF5_Utils::normalize_url($raw);
            if(!$href)continue;
            if($source && GNF5_Utils::source_identity_url($href)===GNF5_Utils::source_identity_url($source))$out['source_visible']=true;
            $host=preg_replace('/^www\./i','',(string)wp_parse_url($href,PHP_URL_HOST));
            if($host===$home){
                $out['internal']++;
                $target=url_to_postid($href);
                $valid=$target && get_post_status($target)==='publish' && !get_post_field('post_password',$target);
                if(!$valid){
                    foreach((array)wp_get_post_categories($post_id) as $cid){
                        $cat_url=get_category_link(absint($cid));
                        if(!is_wp_error($cat_url) && $cat_url && GNF5_Utils::same_resource_url($href,$cat_url)){$valid=true;break;}
                    }
                }
                if($valid)$out['valid_internal']++;
            }else{
                $out['external']++;
                $rel=strtolower(trim($a->getAttribute('rel')));
                if(!preg_match('/\b(nofollow|ugc|sponsored)\b/',$rel))$out['dofollow_external']++;
            }
        }
        return $out;
    }

    private static function usable_trusted_external_available($links_text,$post_id=0){
        $source=$post_id?GNF5_Utils::normalize_url((string)get_post_meta(absint($post_id),'_gnf5_source_url',true)):'';
        $home_host=preg_replace('/^www\./i','',(string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        foreach(GNF5_Utils::urls_from_lines($links_text) as $url){
            // Mirror external_links_section(): source article variants and this site's own
            // URLs are not eligible trusted external links, so they must not trigger the gate.
            if($source && GNF5_Utils::source_identity_url($url)===GNF5_Utils::source_identity_url($source))continue;
            $url_host=preg_replace('/^www\./i','',(string)wp_parse_url($url,PHP_URL_HOST));
            if(strcasecmp($url_host,$home_host)===0)continue;
            $check=GNF5_Sources::test_external_link($url,false);
            if(is_array($check) && in_array((string)($check['status']??''),array('ok','restricted'),true))return true;
        }
        return false;
    }

    private static function same_category_link_available($post_id){
        $cats=wp_get_post_categories($post_id);
        if(empty($cats))return false;
        $q=new WP_Query(array('post_type'=>'post','post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,
            'post__not_in'=>array(absint($post_id)),'category__in'=>array(absint($cats[0]))));
        return !empty($q->posts);
    }

    public static function validate($post_id, $d, $image_ids) {
        $content = (string)get_post_field('post_content',$post_id);
        $warnings = array(); $errors = array();
        if (!trim($content)) $errors[]='Article body is empty.';
        if (preg_match('/<h1\b/i',$content)) $errors[]='Body contains H1; use the WordPress title.';
        if (!has_blocks($content)) $warnings[]='Gutenberg blocks were not detected.';
        if (!defined('RANK_MATH_VERSION')) $warnings[]='Rank Math not active; SEO score is NOT CHECKED.';
        if (defined('RANK_MATH_VERSION') && !self::has_article_schema_type($post_id)) $warnings[]='Article schema not detected; review Rank Math schema settings.';
        $robots=get_post_meta($post_id,'rank_math_robots',true);
        if (get_option('blog_public')==='0' || in_array('noindex',(array)$robots,true)) $warnings[]='Site/post noindex detected. Drafts are not indexable; check final publishing settings manually.';
        $canonical=get_post_meta($post_id,'rank_math_canonical_url',true);
        if ($canonical && GNF5_Utils::same_resource_url($canonical,get_post_meta($post_id,'_gnf5_source_url',true))) $warnings[]='Canonical points to source website; review manually.';
        return array('errors'=>$errors,'warnings'=>$warnings,'word_count'=>GNF5_Utils::word_count($d['content_html']??$content),
            'density'=>self::keyword_density($d['content_html']??$content,$d['focus_keyword']??''),
            'checks'=>array('canonical'=>'Managed by Rank Math/WordPress; no competing canonical added.', 'sitemap'=>'Managed by Rank Math/WordPress; draft inclusion not requested.'));
    }

}
