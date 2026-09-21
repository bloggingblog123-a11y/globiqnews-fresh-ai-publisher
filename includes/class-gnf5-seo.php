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
        $out['content_html']=$html;

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

    public static function insert_internal_links($html, $post_id, $cat_id) {
        if (empty(GNF5_Utils::settings()['internal_links']) || !class_exists('DOMDocument')) return $html;
        $kw = trim((string)get_post_meta($post_id, 'rank_math_focus_keyword', true));
        if ($kw === '') return $html;
        $posts = get_posts(array('post_type'=>'post','post_status'=>'publish','numberposts'=>12,'category'=>$cat_id,'post__not_in'=>array($post_id),'s'=>$kw));
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?><div id="gnf-links">'.$html.'</div>', LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD|LIBXML_NONET);
        $xp = new DOMXPath($doc); $count=0;
        foreach ($posts as $p) {
            $anchor = trim(explode(',',(string)get_post_meta($p->ID,'rank_math_focus_keyword',true))[0]);
            if (!$anchor || !self::contains_exact_phrase($p->post_title.' '.$p->post_content,$kw)) continue;
            $nodes=array(); foreach ($xp->query('//*[@id="gnf-links"]//p//text()[not(ancestor::a)]') as $n) $nodes[]=$n;
            foreach ($nodes as $n) {
                if (!preg_match('/(?<![\p{L}\p{N}])'.preg_quote($anchor,'/').'(?![\p{L}\p{N}])/iu',$n->nodeValue,$m,PREG_OFFSET_CAPTURE)) continue;
                $at=$m[0][1]; $text=$m[0][0]; $parent=$n->parentNode;
                $parent->insertBefore($doc->createTextNode(substr($n->nodeValue,0,$at)),$n);
                $a=$doc->createElement('a');$a->setAttribute('href',get_permalink($p->ID));$a->appendChild($doc->createTextNode($text));
                $parent->insertBefore($a,$n);$parent->insertBefore($doc->createTextNode(substr($n->nodeValue,$at+strlen($text))),$n);$parent->removeChild($n);
                $count++;break;
            }
            if ($count>=3) break;
        }
        $root=$doc->getElementById('gnf-links');$out='';if($root)foreach($root->childNodes as $n)$out.=$doc->saveHTML($n);
        return $out ?: $html;
    }

    public static function external_links_section($links_text,$source_url=''){
        $urls=GNF5_Utils::urls_from_lines($links_text);
        $source=GNF5_Utils::normalize_url($source_url);
        $safe=array();

        foreach($urls as $url){
            if($source&&GNF5_Utils::source_identity_url($url)===GNF5_Utils::source_identity_url($source))continue;
            $url_host=preg_replace('/^www\./i','',(string)wp_parse_url($url,PHP_URL_HOST));
            $home_host=preg_replace('/^www\./i','',(string)wp_parse_url(home_url('/'),PHP_URL_HOST));
            if(strcasecmp($url_host,$home_host)===0)continue;

            $check=GNF5_Sources::test_external_link($url,false);
            if(is_wp_error($check))continue;
            $status=(string)($check['status']??'');
            if(!in_array($status,array('ok','restricted'),true))continue;

            $safe[]=$url;
            if(count($safe)>=2)break;
        }

        if(!$safe)return '';
        $items='';
        foreach($safe as $url){
            $host=preg_replace('/^www\./i','',(string)wp_parse_url($url,PHP_URL_HOST));
            $items.='<li><a href="'.esc_url($url).'">'.esc_html($host).'</a></li>';
        }
        return self::simple_heading('Useful Resources','useful-resources').self::simple_list($items);
    }

    private static function simple_heading($text,$anchor){
        $html='<h2 class="wp-block-heading" id="'.esc_attr($anchor).'">'.esc_html($text).'</h2>';
        return function_exists('serialize_block')?serialize_block(self::block_array('core/heading',array('anchor'=>$anchor),$html)):$html;
    }

    private static function simple_list($items){
        $html='<ul class="wp-block-list">'.$items.'</ul>';
        return function_exists('serialize_block')?serialize_block(self::simple_list_block($items)):$html;
    }

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
            $href=GNF5_Utils::normalize_url($a->getAttribute('href'));
            if(!$href)continue;
            if($source && GNF5_Utils::source_identity_url($href)===GNF5_Utils::source_identity_url($source))$out['source_visible']=true;
            $host=preg_replace('/^www\./i','',(string)wp_parse_url($href,PHP_URL_HOST));
            if($host===$home){
                $out['internal']++;
                $valid=(url_to_postid($href)>0);
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
