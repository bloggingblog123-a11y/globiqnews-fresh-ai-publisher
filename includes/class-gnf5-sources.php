<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Sources {
    public static function fetch($url, $purpose = 'page') {
        $url = GNF5_Utils::normalize_url($url);
        if (!$url) { return new WP_Error('invalid_url', 'A valid public HTTP/HTTPS URL was not provided.'); }
        if (GNF5_Utils::is_blocked_cached($url)) {
            return new WP_Error('blocked_cached', 'Source is temporarily skipped because it was recently blocked or rate limited.');
        }
        $response = wp_safe_remote_get($url, array(
            'timeout' => 35,
            'redirection' => 5,
            'user-agent' => 'GlobiqNewsFreshPublisher/' . GNF5_VERSION . ' (+'.home_url('/').')',
            'headers' => array(
                'Accept' => $purpose === 'feed'
                    ? 'application/rss+xml,application/atom+xml,application/rdf+xml,application/xml,text/xml;q=0.9,text/html;q=0.5,*/*;q=0.3'
                    : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.6',
                'Accept-Language' => 'en-US,en;q=0.8',
            ),
        ));
        if (is_wp_error($response)) { return $response; }
        $code = absint(wp_remote_retrieve_response_code($response));
        $body = (string)wp_remote_retrieve_body($response);
        $content_type = strtolower((string)wp_remote_retrieve_header($response, 'content-type'));
        if (in_array($code, array(401,403,429), true)) {
            GNF5_Utils::mark_blocked($url, 'HTTP '.$code);
            return new WP_Error('blocked', 'HTTP '.$code.' — source blocked/rate-limited; skipped without bypass.');
        }
        if ($code < 200 || $code >= 400) {
            return new WP_Error('http', 'HTTP '.$code.' for '.$url);
        }
        // Detect real challenge pages conservatively. A legitimate news article may contain
        // ordinary phrases such as "access denied" or "captcha", so one generic phrase alone
        // must never block a source.
        $probe_html = strtolower(GNF5_Utils::safe_substr($body, 0, 18000));
        $probe_text = strtolower(GNF5_Utils::safe_substr(wp_strip_all_tags($body), 0, 12000));
        $page_words = GNF5_Utils::word_count(wp_strip_all_tags($body));
        $challenge_markers = array(
            'cf-chl-', 'challenge-platform', 'cloudflare ray id', 'verify you are human',
            'checking your browser', 'checking if the site connection is secure',
            'please stand by, while we are checking your browser'
        );
        $challenge_hit = '';
        foreach ($challenge_markers as $marker) {
            if (strpos($probe_html, $marker) !== false || strpos($probe_text, $marker) !== false) {
                $challenge_hit = $marker;
                break;
            }
        }
        // CAPTCHA widgets can legitimately exist in comments/login forms on an otherwise
        // accessible article. A widget marker alone is not proof that the whole page is blocked.
        $widget_hit = false;
        foreach (array('cf-turnstile','g-recaptcha','hcaptcha') as $marker) {
            if (strpos($probe_html,$marker)!==false) {$widget_hit=true;break;}
        }
        $generic_hits = 0;
        foreach (array('captcha','access denied','attention required','javascript is required','enable javascript and cookies') as $marker) {
            if (strpos($probe_text, $marker) !== false) { $generic_hits++; }
        }
        if (($challenge_hit !== '' && $page_words < 1200) || ($generic_hits >= 2 && $page_words < 500) || ($widget_hit && $generic_hits >= 1 && $page_words < 180)) {
            $reason = $challenge_hit !== '' ? $challenge_hit : ($widget_hit ? 'captcha challenge page' : 'multiple challenge markers');
            GNF5_Utils::mark_blocked($url, $reason);
            return new WP_Error('challenge', 'Access challenge/CAPTCHA detected; skipped without bypass.');
        }
        // Use the final public URL after ordinary HTTP redirects when the transport exposes it.
        // This makes relative category/article links resolve against the page that was actually
        // returned rather than an outdated pre-redirect path.
        $final_url=$url;
        if(isset($response['http_response']) && is_object($response['http_response']) && method_exists($response['http_response'],'get_response_object')){
            $obj=$response['http_response']->get_response_object();
            if(is_object($obj) && !empty($obj->url)){
                $candidate=GNF5_Utils::normalize_url((string)$obj->url);
                if($candidate)$final_url=$candidate;
            }
        }
        return array(
            'url'=>$final_url,'code'=>$code,'body'=>$body,'headers'=>wp_remote_retrieve_headers($response),
            'content_type'=>$content_type,
        );
    }

    /** Robust RSS/Atom loader. WordPress SimplePie is first choice; raw XML is a fallback. */
    public static function rss_items($feed_url, $limit = 15, $depth = 0) {
        $depth=max(0,absint($depth));
        if($depth>3)return new WP_Error('rss_loop','RSS/Atom linked-feed recursion depth exceeded.');
        include_once ABSPATH . WPINC . '/feed.php';
        $feed_url = GNF5_Utils::normalize_url($feed_url);
        $limit = max(1, absint($limit));
        if (!$feed_url) { return new WP_Error('feed_url', 'Invalid RSS/Atom URL.'); }
        if (GNF5_Utils::is_blocked_cached($feed_url)) {
            return new WP_Error('blocked_cached', 'Feed is temporarily skipped after a blocked response.');
        }

        $out = array();
        $simplepie_error = '';
        $cache_filter = function(){ return 300; };
        add_filter('wp_feed_cache_transient_lifetime', $cache_filter);
        $feed = fetch_feed($feed_url);
        remove_filter('wp_feed_cache_transient_lifetime', $cache_filter);
        if (!is_wp_error($feed)) {
            foreach ($feed->get_items(0, $limit) as $item) {
                $url = GNF5_Utils::normalize_url($item->get_permalink());
                if (!$url) { continue; }
                $content = wp_strip_all_tags((string)$item->get_content());
                $description = wp_strip_all_tags((string)$item->get_description());
                $fallback = GNF5_Utils::word_count($content) >= GNF5_Utils::word_count($description) ? $content : $description;
                $out[] = array(
                    'url'=>$url,'title'=>sanitize_text_field($item->get_title()),
                    'fallback_text'=>trim($fallback),'method'=>'RSS/Atom (WordPress)',
                );
            }
            $out = self::unique_items($out);
            if ($out) { return array_slice($out, 0, $limit); }
        } else {
            $simplepie_error = $feed->get_error_message();
        }

        // Direct fetch fallback helps when SimplePie rejects a valid feed or a host varies content headers.
        $f = self::fetch($feed_url, 'feed');
        if (is_wp_error($f)) {
            return $simplepie_error ? new WP_Error('rss', $simplepie_error.' | Direct feed fetch: '.$f->get_error_message()) : $f;
        }

        if (self::looks_like_feed($f['body'], $f['content_type'])) {
            $out = self::parse_raw_feed($f['body'], $f['url'], $limit);
            if (!is_wp_error($out) && $out) { return $out; }
        }

        // If an HTML page was accidentally entered in the RSS box, use its declared RSS/Atom feed.
        $linked = self::linked_feed_urls($f['body'], $f['url']);
        foreach (array_slice($linked, 0, 3) as $linked_feed) {
            if ($linked_feed === $feed_url) { continue; }
            $r = self::rss_items($linked_feed, $limit, $depth + 1);
            if (!is_wp_error($r) && $r) { return $r; }
        }
        return new WP_Error('rss_empty', 'No usable RSS/Atom items were found at '.$feed_url.($simplepie_error ? ' WordPress feed error: '.$simplepie_error : ''));
    }

    private static function source_scope_tokens($base) {
        $path = trim(strtolower((string)wp_parse_url($base, PHP_URL_PATH)), '/');
        if ($path === '') { return array(); }
        $generic = array(
            'category','categories','section','sections','news','latest','latest-news','home',
            'index','article','articles','story','stories','post','posts'
        );
        $tokens = array();
        foreach (explode('/', $path) as $segment) {
            $segment = sanitize_title($segment);
            if ($segment === '' || strlen($segment) < 3 || in_array($segment, $generic, true)) { continue; }
            if (preg_match('/^\d+$/', $segment)) { continue; }
            $tokens[] = $segment;
        }
        return array_values(array_unique($tokens));
    }

    private static function candidate_in_source_scope($candidate, $base) {
        $candidate = GNF5_Utils::normalize_url($candidate);
        $base = GNF5_Utils::normalize_url($base);
        if (!$candidate || !$base || self::host_key($candidate) !== self::host_key($base)) { return false; }

        $tokens = self::source_scope_tokens($base);
        if (!$tokens) { return true; }

        $candidate_path = '/'.trim(strtolower((string)wp_parse_url($candidate, PHP_URL_PATH)), '/').'/';
        $base_path = trim(strtolower((string)wp_parse_url($base, PHP_URL_PATH)), '/');
        if ($base_path !== '' && strpos(trim($candidate_path,'/'), $base_path.'/') === 0) { return true; }

        // Require at least the most specific category token, and accept any additional
        // category token as reinforcement. This intentionally favors strict category
        // separation over pulling unrelated "trending" stories from the same host.
        $specific = end($tokens);
        if ($specific && strpos($candidate_path, '/'.$specific.'/') !== false) { return true; }

        if (count($tokens) > 1) {
            $hits = 0;
            foreach ($tokens as $token) {
                if (strpos($candidate_path, '/'.$token.'/') !== false) { $hits++; }
            }
            if ($hits >= 2) { return true; }
        }
        return false;
    }

    private static function filter_items_to_source_scope($items, $base) {
        $tokens = self::source_scope_tokens($base);
        if (!$tokens) { return self::unique_items($items); }

        $out = array();
        foreach ((array)$items as $item) {
            $url = GNF5_Utils::normalize_url($item['url'] ?? '');
            if ($url && self::candidate_in_source_scope($url, $base)) { $out[] = $item; }
        }
        return self::unique_items($out);
    }

    /**
     * Semantic/editorial card links are trusted as category-page candidates even when
     * the publisher uses flat article URLs that do not repeat the category path.
     */
    private static function is_article_card_link($node) {
        $p=$node;
        for($i=0;$i<7 && $p;$i++,$p=$p->parentNode){
            if($p->nodeType!==XML_ELEMENT_NODE)continue;
            $tag=strtolower((string)$p->nodeName);
            if($tag==='article')return true;

            $class=strtolower((string)($p->attributes && $p->attributes->getNamedItem('class') ? $p->attributes->getNamedItem('class')->nodeValue : ''));
            $itemtype=strtolower((string)($p->attributes && $p->attributes->getNamedItem('itemtype') ? $p->attributes->getNamedItem('itemtype')->nodeValue : ''));
            if(strpos($itemtype,'article')!==false)return true;
            if(preg_match('/(?:^|[\\s_-])(article|story|headline|post|listing-item|news-item)(?:$|[\\s_-])/',$class))return true;
        }
        return false;
    }

    private static function feed_url_is_category_scoped($feed_url,$base){
        $tokens=self::source_scope_tokens($base);
        if(!$tokens)return false;
        $hay=rawurldecode(strtolower((string)wp_parse_url($feed_url,PHP_URL_PATH).' '.(string)wp_parse_url($feed_url,PHP_URL_QUERY)));
        foreach($tokens as $token){
            if(preg_match('/(?<![a-z0-9])'.preg_quote(strtolower($token),'/').'(?![a-z0-9])/i',$hay))return true;
        }
        return false;
    }

    /**
     * Source URLs can be a category/listing page, a direct article URL, or even a feed URL.
     * The mode is detected automatically.
     */
    public static function discover_source($url, $limit = 15) {
        $limit = max(1, absint($limit));
        $f = self::fetch($url, 'page');
        if (is_wp_error($f)) {
            $normalized=GNF5_Utils::normalize_url($url);
            // A direct article-shaped URL can still be handed to the extraction stage,
            // where official public print/alternate fallbacks may work safely.
            $path=(string)wp_parse_url($normalized,PHP_URL_PATH);
            $direct_shape=(bool)(preg_match('~/articleshow/\d+\.cms$~i',$path) || preg_match('~/(?:article|story|post)/[^/]{8,}/?$~i',$path) || (strlen($path)>35 && preg_match('~\.(?:html?|cms)$~i',$path)));
            if($direct_shape){
                return array(array('url'=>$normalized,'title'=>'','fallback_text'=>'','method'=>'Direct Source Article (public fallback pending)'));
            }
            // Public sitemap is a permitted fallback when the presentation page itself is blocked.
            $map=self::sitemap_candidates($normalized,$limit);
            if(!is_wp_error($map)){
                $map=self::filter_items_to_source_scope($map,$normalized);
                if($map)return array_slice($map,0,$limit);
            }
            return $f;
        }

        // A feed pasted into Source URLs still works.
        // First use the already-fetched XML, then fall back to the full RSS engine
        // (WordPress SimplePie + raw XML + declared-feed handling). Never scan XML as HTML.
        if (self::looks_like_feed($f['body'], $f['content_type'])) {
            $raw_items = self::parse_raw_feed($f['body'], $f['url'], $limit);
            if (!is_wp_error($raw_items) && $raw_items) { return $raw_items; }

            $feed_items = self::rss_items($f['url'], $limit);
            if (!is_wp_error($feed_items) && $feed_items) { return $feed_items; }

            $raw_error = is_wp_error($raw_items) ? $raw_items->get_error_message() : 'No usable raw-feed items.';
            $feed_error = is_wp_error($feed_items) ? $feed_items->get_error_message() : 'No usable feed items.';
            return new WP_Error('source_feed', 'The Source URL is a feed, but it could not be parsed. '.$raw_error.' | '.$feed_error);
        }

        $html = $f['body'];
        $base = $f['url'];
        if (!class_exists('DOMDocument')) { return new WP_Error('dom', 'PHP DOM extension is required.'); }

        // Direct article URLs in the Source box are processed directly rather than scanned as category pages.
        if (self::looks_like_article_page($html, $base)) {
            $parsed = self::parse_article_html($html, $base);
            if (!is_wp_error($parsed) && GNF5_Utils::word_count($parsed['text']) >= 80) {
                return array(array(
                    'url'=>$base,'title'=>$parsed['title'],'fallback_text'=>$parsed['text'],
                    'method'=>'Direct Source Article',
                ));
            }
        }

        $base_host = self::host_key($base);
        $scored = array();
        $titles = array();
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xp = new DOMXPath($doc);

        // Normal anchor discovery.
        foreach ($doc->getElementsByTagName('a') as $a) {
            if (self::is_navigation_or_sidebar_link($a)) { continue; }
            $u = self::absolute_url($a->getAttribute('href'), $base);
            if (!$u || self::host_key($u) !== $base_host) { continue; }
            $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
            $in_scope=self::candidate_in_source_scope($u,$base);
            $trusted_card=self::is_article_card_link($a);
            if(!$in_scope && !$trusted_card){continue;}
            if(!self::candidate_is_article_like($u,$text,$trusted_card)){continue;}
            $score = self::article_url_score($u, $base, $text) + ($trusted_card ? 4 : 0);
            if ($score > 2) {
                $scored[$u] = max($score, $scored[$u] ?? 0);
                if ($text && empty($titles[$u])) { $titles[$u] = $text; }
            }
        }

        // Links inside semantic article cards get an extra score.
        foreach ($xp->query('//article//a[@href] | //*[@itemprop="url"]') as $a) {
            if(self::is_navigation_or_sidebar_link($a)){continue;}
            $href = $a->getAttribute('href');
            if (!$href && $a->hasAttribute('content')) { $href = $a->getAttribute('content'); }
            $u = self::absolute_url($href, $base);
            if (!$u || self::host_key($u) !== $base_host) { continue; }
            $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
            if(!self::candidate_is_article_like($u,$text,true)){continue;}
            $score = self::article_url_score($u, $base, $text) + 5;
            if ($score > 2) {
                $scored[$u] = max($score, $scored[$u] ?? 0);
                if ($text && empty($titles[$u])) { $titles[$u] = $text; }
            }
        }

        // JSON-LD ItemList/Article URLs catch many JavaScript-heavy publisher pages.
        foreach ($xp->query('//script[@type="application/ld+json"]') as $node) {
            $json = json_decode(trim($node->textContent), true);
            if (!is_array($json)) { continue; }
            $json_urls = array();
            self::walk_jsonld_urls($json, $json_urls);
            foreach ($json_urls as $candidate) {
                $u = self::absolute_url($candidate['url'] ?? '', $base);
                if (!$u || self::host_key($u) !== $base_host) { continue; }
                if (!self::candidate_in_source_scope($u, $base)) { continue; }
                $text = sanitize_text_field($candidate['title'] ?? '');
                if(!self::candidate_is_article_like($u,$text,true)){continue;}
                $score = self::article_url_score($u, $base, $text) + 4;
                if ($score > 2) {
                    $scored[$u] = max($score, $scored[$u] ?? 0);
                    if ($text && empty($titles[$u])) { $titles[$u] = $text; }
                }
            }
        }

        // Some React/Next pages expose article URLs only inside serialized script data.
        // We only keep same-host URLs that independently look article-like.
        if (preg_match_all('~https?:\\?/\\?/[^"\'<>\\s]+~i', $html, $m)) {
            foreach (array_slice(array_unique($m[0]), 0, 500) as $raw) {
                $raw = str_replace(array('\\/','\\u002F'), '/', $raw);
                $u = GNF5_Utils::normalize_url(html_entity_decode($raw));
                if (!$u || self::host_key($u) !== $base_host || !self::candidate_in_source_scope($u, $base)) { continue; }
                if(!self::has_article_url_signal($u)){continue;}
                $score = self::article_url_score($u, $base, '');
                if ($score >= 4) { $scored[$u] = max($score, $scored[$u] ?? 0); }
            }
        }

        arsort($scored);
        $items = array();
        foreach (array_slice(array_keys($scored), 0, $limit) as $u) {
            $items[] = array('url'=>$u,'title'=>sanitize_text_field($titles[$u] ?? ''),'fallback_text'=>'','method'=>'Source/Category URL');
        }

        // Publisher-declared feeds are a safe fallback for category pages.
        // Some publishers expose category feeds with ID/generic URLs; in that case the
        // <link rel="alternate" title="..."> label is also used to verify category scope.
        if (count($items) < min(5, $limit)) {
            foreach (self::linked_feed_candidates($html, $base) as $feed_candidate) {
                $feed_url=(string)($feed_candidate['url']??'');
                if(!$feed_url)continue;
                $feed_items = self::rss_items($feed_url, $limit);
                if (!is_wp_error($feed_items)) {
                    if(!self::feed_candidate_is_category_scoped($feed_candidate,$base)){
                        $feed_items = self::filter_items_to_source_scope($feed_items, $base);
                    }
                    $items = array_merge($items, $feed_items);
                }
                if (count(self::unique_items($items)) >= $limit) { break; }
            }
        }

        // Public sitemap fallback. Supports sitemap indexes recursively.
        if (count(self::unique_items($items)) < min(5, $limit)) {
            $sitemap = self::sitemap_candidates($base, $limit - count(self::unique_items($items)));
            if (!is_wp_error($sitemap)) {
                $sitemap = self::filter_items_to_source_scope($sitemap, $base);
                $items = array_merge($items, $sitemap);
            }
        }

        $items = array_slice(self::unique_items($items), 0, $limit);
        if (!$items) {
            return new WP_Error('source_empty', 'The source page was reachable, but no usable article URLs were discovered. Try an RSS feed, a direct article URL, or another public category page.');
        }
        return $items;
    }

    // Backward-compatible alias used by older V5 code paths.
    public static function discover_category($url, $limit = 15) {
        return self::discover_source($url, $limit);
    }

    private static function is_non_article_url($url) {
        $path=strtolower((string)wp_parse_url($url,PHP_URL_PATH));
        if(preg_match('~/(tag|tags|author|authors|category|categories|page|pages|video|videos|photos?|gallery|liveblog|topic|topics|search|login|account|subscription|team|teams|about|contact)(/|$)~i',$path))return true;
        if(preg_match('~\.(jpg|jpeg|png|webp|gif|svg|pdf|mp4|mp3|zip)$~i',$path))return true;
        return false;
    }

    private static function has_article_url_signal($url) {
        $path=(string)wp_parse_url($url,PHP_URL_PATH);
        $query=(string)wp_parse_url($url,PHP_URL_QUERY);
        if(self::is_non_article_url($url))return false;
        if(preg_match('~/articleshow/\d+\.cms$~i',$path))return true;
        if(preg_match('~/(?:article|articles|story|stories|post|posts)/[^/]+~i',$path))return true;
        if(preg_match('~/\d{4}/\d{1,2}/\d{1,2}/~',$path))return true;
        if(preg_match('~[-/][0-9]{6,}(?:[./-]|$)~',$path))return true;
        if($query && preg_match('/(?:^|&)(?:article|story|id)=\d+(?:&|$)/i',$query))return true;
        // A long article-like HTML/CMS URL is useful evidence, but a short section URL
        // such as /technology.html is deliberately not enough by itself.
        if(strlen(trim($path,'/'))>=32 && preg_match('~\.(?:html?|cms)$~i',$path))return true;
        return false;
    }

    private static function candidate_is_article_like($url,$text='',$trusted_card=false) {
        if(self::is_non_article_url($url))return false;
        if(self::has_article_url_signal($url))return true;

        // Flat publisher permalinks can have no numeric/date/.html signal. Accept those
        // only when they are clearly presented as an editorial headline/article card.
        $plain=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$text)));
        if($trusted_card && GNF5_Utils::word_count($plain)>=4 && strlen($plain)>=24)return true;
        return false;
    }

    private static function article_url_score($url, $base, $text='') {
        $path = (string)wp_parse_url($url, PHP_URL_PATH);
        $query = (string)wp_parse_url($url, PHP_URL_QUERY);
        $score = 0;
        if (preg_match('~/articleshow/\d+\.cms$~i', $path)) { $score += 15; }
        if (preg_match('~/(article|articles|story|stories|news|post|posts)/~i', $path)) { $score += 5; }
        if (preg_match('~/\d{4}/\d{1,2}/\d{1,2}/~', $path)) { $score += 5; }
        if (preg_match('~[-/][0-9]{6,}(?:[./-]|$)~', $path)) { $score += 5; }
        if (preg_match('~\.(html?|cms)$~i', $path)) { $score += 4; }
        if (strlen($path) > 45) { $score += 3; }
        if (strlen($text) > 25) { $score += 2; }

        $base_path = trim((string)wp_parse_url($base, PHP_URL_PATH), '/');
        if ($base_path !== '') {
            $candidate_path = trim($path, '/');
            if ($candidate_path !== '' && (strpos($candidate_path, $base_path.'/') === 0 || $candidate_path === $base_path)) {
                $score += 2;
            } else {
                $segments = array_values(array_filter(explode('/', $base_path)));
                $last = $segments ? strtolower((string)end($segments)) : '';
                if ($last !== '' && strlen($last) >= 4 && strpos(strtolower('/'.$candidate_path.'/'), '/'.$last.'/') !== false) {
                    $score += 4;
                }
            }
        }

        if ($query && preg_match('/(?:article|story|id)=\d+/i', $query)) { $score += 4; }
        if (preg_match('~/(tag|tags|author|authors|category|categories|page|pages|video|videos|photos?|gallery|liveblog|topic|topics|search|login|account|subscription)(/|$)~i', $path)) { $score -= 15; }
        if (preg_match('~\.(jpg|jpeg|png|webp|gif|svg|pdf|mp4|mp3|zip)$~i', $path)) { $score -= 25; }
        if (GNF5_Utils::normalize_url($url) === GNF5_Utils::normalize_url($base)) { $score -= 25; }
        return $score;
    }

    private static function looks_like_article_page($html, $url) {
        if (self::looks_like_feed($html, '')) { return false; }

        $parsed = self::parse_article_html($html, $url);
        $words = is_wp_error($parsed) ? 0 : GNF5_Utils::word_count($parsed['text'] ?? '');
        $method = is_wp_error($parsed) ? '' : (string)($parsed['method'] ?? '');

        // Strongest signal: a real JSON-LD articleBody.
        if ($method === 'JSON-LD articleBody' && $words >= 80) { return true; }

        $path = (string)wp_parse_url($url, PHP_URL_PATH);
        $strong_identity = (
            preg_match('~/articleshow/\d+\.cms$~i', $path) ||
            preg_match('~/(?:article|story|post)/[^/]{8,}/?$~i', $path) ||
            preg_match('~[-/]\d{6,}(?:\.(?:html?|cms))?/?$~i', $path) ||
            preg_match('~/\d{4}/\d{1,2}/\d{1,2}/~', $path)
        );
        $generic_extension = (bool)preg_match('~\.(?:html?|cms)$~i', $path);

        $og_article = (
            preg_match('/<meta[^>]+property=["\']og:type["\'][^>]+content=["\']article["\']/i', $html) ||
            preg_match('/<meta[^>]+content=["\']article["\'][^>]+property=["\']og:type["\']/i', $html)
        );
        $published_marker = (
            stripos($html,'article:published_time')!==false ||
            preg_match('/<time\b[^>]+datetime=/i',$html)
        );

        $h1_count = preg_match_all('/<h1\b/i', $html, $dummy_h1);
        $article_cards = preg_match_all('/<article\b/i', $html, $dummy_articles);

        if ($strong_identity && $h1_count >= 1 && $words >= 120 && $article_cards <= 3) { return true; }
        if ($og_article && $h1_count >= 1 && $words >= 120 && $article_cards <= 3) { return true; }
        if ($generic_extension && $h1_count >= 1 && $words >= 160 && $article_cards <= 2 && ($published_marker || $article_cards===1)) { return true; }

        // A section such as /technology.html with many article cards must stay a listing page.
        return false;
    }

    private static function looks_like_feed($body, $content_type='') {
        $head = ltrim(GNF5_Utils::safe_substr($body, 0, 1200));
        if (strpos($content_type, 'rss') !== false || strpos($content_type, 'atom') !== false || strpos($content_type, 'rdf') !== false) return true;
        return (bool)preg_match('/^<\?xml[^>]*>\s*<(?:rss|feed|rdf:RDF)\b|^<(?:rss|feed|rdf:RDF)\b/is', $head);
    }

    private static function parse_raw_feed($xml, $base, $limit) {
        if (!class_exists('DOMDocument')) {
            return self::parse_raw_feed_lightweight($xml,$base,$limit);
        }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml, LIBXML_NOCDATA | LIBXML_NOWARNING | LIBXML_NOERROR)) {
            return new WP_Error('rss_xml', 'Feed XML could not be parsed.');
        }
        $xp = new DOMXPath($doc);
        $items = array();
        $nodes = $xp->query('//*[local-name()="item"] | //*[local-name()="entry"]');
        if (!$nodes) { return new WP_Error('rss_xml', 'No RSS/Atom entries found.'); }
        foreach ($nodes as $node) {
            $url='';$html_alt='';$generic_alt='';$link_fallback='';$title='';$text='';
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                $name = strtolower($child->localName ?: $child->nodeName);
                $value = trim((string)$child->textContent);
                if ($name==='title' && !$title) $title=$value;
                if ($name==='link') {
                    $href=$child->attributes && $child->attributes->getNamedItem('href') ? $child->attributes->getNamedItem('href')->nodeValue : '';
                    $candidate=$href?:$value;
                    $rel=$child->attributes && $child->attributes->getNamedItem('rel') ? strtolower($child->attributes->getNamedItem('rel')->nodeValue) : '';
                    $type=$child->attributes && $child->attributes->getNamedItem('type') ? strtolower($child->attributes->getNamedItem('type')->nodeValue) : '';
                    if ($candidate && !$link_fallback) { $link_fallback=$candidate; }

                    // RSS <link> usually has no rel/type and is the article permalink.
                    if($candidate && $rel==='' && $type==='' && !$url){$url=$candidate;}

                    // Atom can expose several rel=alternate links. Prefer HTML/XHTML.
                    if($candidate && $rel==='alternate'){
                        if(($type==='' || strpos($type,'text/html')===0 || strpos($type,'application/xhtml+xml')===0) && !$html_alt){
                            $html_alt=$candidate;
                        }elseif(!$generic_alt){
                            $generic_alt=$candidate;
                        }
                    }
                }
                if (($name==='guid' || $name==='id') && !$url && preg_match('~^https?://~i',$value)) $url=$value;
                if (in_array($name,array('description','summary','content','encoded'),true) && GNF5_Utils::word_count($value)>GNF5_Utils::word_count($text)) $text=$value;
            }
            if (!$url) { $url=$html_alt?:($generic_alt?:$link_fallback); }
            $url=self::absolute_url($url,$base);
            if(!$url)continue;
            $items[]=array('url'=>$url,'title'=>sanitize_text_field($title),'fallback_text'=>trim(wp_strip_all_tags($text)),'method'=>'RSS/Atom (raw XML fallback)');
            if(count($items)>=$limit)break;
        }
        $items=self::unique_items($items);
        return $items ?: new WP_Error('rss_empty','Feed XML contained no usable article links.');
    }

    /**
     * Last-resort RSS/Atom parser for hosts where PHP DOM is unavailable.
     * WordPress/SimplePie remains the first parser; this only prevents a valid
     * feed from becoming unusable because the DOM extension is missing.
     */
    private static function parse_raw_feed_lightweight($xml,$base,$limit){
        $limit=max(1,absint($limit));
        $blocks=array();
        if(preg_match_all('~<(item|entry)\b[^>]*>(.*?)</\1>~is',(string)$xml,$m,PREG_SET_ORDER)){
            $blocks=$m;
        }
        if(!$blocks)return new WP_Error('rss_xml','Feed XML contained no RSS/Atom entries.');

        $items=array();
        foreach($blocks as $match){
            $block=(string)($match[2]??'');
            $title='';$url='';$text='';

            if(preg_match('~<title\b[^>]*>(.*?)</title>~is',$block,$tm)){
                $title=trim(wp_strip_all_tags(html_entity_decode($tm[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
            }

            // Atom: prefer rel=alternate HTML/XHTML.
            if(preg_match_all('~<link\b([^>]*)/?>~is',$block,$lm,PREG_SET_ORDER)){
                $generic='';
                foreach($lm as $link){
                    $attrs=(string)($link[1]??'');
                    $href='';$rel='';$type='';
                    if(preg_match('~\bhref\s*=\s*["\']([^"\']+)["\']~i',$attrs,$x))$href=html_entity_decode($x[1],ENT_QUOTES|ENT_HTML5,'UTF-8');
                    if(preg_match('~\brel\s*=\s*["\']([^"\']+)["\']~i',$attrs,$x))$rel=strtolower(trim($x[1]));
                    if(preg_match('~\btype\s*=\s*["\']([^"\']+)["\']~i',$attrs,$x))$type=strtolower(trim($x[1]));
                    if(!$href)continue;
                    if(!$generic)$generic=$href;
                    if($rel==='alternate' && ($type==='' || strpos($type,'text/html')===0 || strpos($type,'application/xhtml+xml')===0)){
                        $url=$href;break;
                    }
                }
                if(!$url)$url=$generic;
            }

            // RSS: text content of <link>.
            if(!$url && preg_match('~<link\b[^>]*>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</link>~is',$block,$lm)){
                $url=trim(html_entity_decode(wp_strip_all_tags($lm[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            }
            if(!$url && preg_match('~<(guid|id)\b[^>]*>\s*(?:<!\[CDATA\[)?(https?://.*?)(?:\]\]>)?\s*</\1>~is',$block,$gm)){
                $url=trim(html_entity_decode(wp_strip_all_tags($gm[2]),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            }

            foreach(array('content:encoded','content','description','summary') as $tag){
                $quoted=preg_quote($tag,'~');
                if(preg_match('~<'.$quoted.'\b[^>]*>(.*?)</'.$quoted.'>~is',$block,$cm)){
                    $candidate=trim(wp_strip_all_tags(html_entity_decode($cm[1],ENT_QUOTES|ENT_HTML5,'UTF-8')));
                    if(GNF5_Utils::word_count($candidate)>GNF5_Utils::word_count($text))$text=$candidate;
                }
            }

            $url=self::absolute_url($url,$base);
            if(!$url)continue;
            $items[]=array(
                'url'=>$url,'title'=>sanitize_text_field($title),'fallback_text'=>$text,
                'method'=>'RSS/Atom (lightweight XML fallback)',
            );
            if(count($items)>=$limit)break;
        }
        $items=self::unique_items($items);
        return $items?:new WP_Error('rss_empty','Feed XML contained no usable article links.');
    }

    private static function linked_feed_candidates($html, $base) {
        $out=array();$seen=array();
        if(!class_exists('DOMDocument'))return $out;
        libxml_use_internal_errors(true);
        $doc=new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING|LIBXML_NOERROR);
        $xp=new DOMXPath($doc);
        foreach($xp->query('//link[@rel="alternate"]') as $link){
            $type=strtolower((string)$link->getAttribute('type'));
            if(strpos($type,'rss')===false && strpos($type,'atom')===false && strpos($type,'xml')===false)continue;
            $u=self::absolute_url($link->getAttribute('href'),$base);
            if(!$u || isset($seen[$u]))continue;
            $seen[$u]=true;
            $out[]=array(
                'url'=>$u,
                'title'=>sanitize_text_field((string)$link->getAttribute('title')),
                'type'=>sanitize_text_field($type),
            );
        }
        return $out;
    }

    private static function linked_feed_urls($html, $base) {
        $out=array();
        foreach(self::linked_feed_candidates($html,$base) as $candidate){
            if(!empty($candidate['url']))$out[]=$candidate['url'];
        }
        return array_values(array_unique($out));
    }

    private static function feed_candidate_is_category_scoped($candidate,$base){
        $url=is_array($candidate)?($candidate['url']??''):(string)$candidate;
        if(self::feed_url_is_category_scoped($url,$base))return true;

        $tokens=self::source_scope_tokens($base);
        if(!$tokens)return false;
        $title=is_array($candidate)?(string)($candidate['title']??''):'';
        $hay=' '.preg_replace('/\s+/u',' ',strtolower(rawurldecode($title))).' ';
        foreach($tokens as $token){
            $needle=strtolower(str_replace('-',' ',$token));
            if($needle!=='' && preg_match('/(?<![a-z0-9])'.preg_quote($needle,'/').'(?![a-z0-9])/i',$hay))return true;
        }
        return false;
    }

    private static function walk_jsonld_urls($node, &$out) {
        if(!is_array($node))return;
        $title='';
        foreach(array('headline','name','title') as $k){if(!empty($node[$k])&&is_string($node[$k])){$title=$node[$k];break;}}
        foreach(array('url','@id') as $k){
            if(!empty($node[$k])&&is_string($node[$k])&&preg_match('~^https?://~i',$node[$k]))$out[]=array('url'=>$node[$k],'title'=>$title);
        }
        if(!empty($node['mainEntityOfPage'])){
            $m=$node['mainEntityOfPage'];
            if(is_string($m)&&preg_match('~^https?://~i',$m))$out[]=array('url'=>$m,'title'=>$title);
            elseif(is_array($m)){foreach(array('@id','url') as $k){if(!empty($m[$k])&&is_string($m[$k]))$out[]=array('url'=>$m[$k],'title'=>$title);}}
        }
        if(isset($node['item'])){
            $it=$node['item'];
            if(is_string($it)&&preg_match('~^https?://~i',$it))$out[]=array('url'=>$it,'title'=>$title);
            elseif(is_array($it)){foreach(array('url','@id') as $k){if(!empty($it[$k])&&is_string($it[$k]))$out[]=array('url'=>$it[$k],'title'=>$title);}}
        }
        foreach($node as $v){if(is_array($v))self::walk_jsonld_urls($v,$out);}
    }

    private static function sitemap_candidates($category_url, $remaining) {
        if ($remaining <= 0) { return array(); }
        $p = wp_parse_url($category_url);
        if (!$p || empty($p['host'])) { return array(); }
        $origin = ($p['scheme'] ?? 'https').'://'.$p['host'];
        $robots = self::fetch($origin.'/robots.txt', 'page');
        $sitemaps = array();
        if (!is_wp_error($robots) && preg_match_all('/^\s*Sitemap:\s*(https?:\/\/\S+)/im', $robots['body'], $m)) {
            foreach ($m[1] as $u) { $u=GNF5_Utils::normalize_url($u); if($u)$sitemaps[]=$u; }
        }
        if (!$sitemaps) { $sitemaps = array($origin.'/sitemap.xml',$origin.'/wp-sitemap.xml'); }

        $cat_path = trim((string)($p['path'] ?? ''), '/');
        $segments = array_values(array_filter(explode('/', $cat_path)));
        $needles = array_slice($segments, -2);
        $items = array();$visited=array();$queue=array();
        foreach(array_values(array_unique(array_filter($sitemaps))) as $sm)$queue[]=array($sm,0);

        while($queue && count($items)<$remaining && count($visited)<12){
            list($sm,$depth)=array_shift($queue);
            if(isset($visited[$sm]))continue;$visited[$sm]=true;
            $x=self::fetch($sm,'feed');if(is_wp_error($x))continue;
            if(!preg_match_all('~<loc>\s*(.*?)\s*</loc>~is',$x['body'],$m))continue;
            $is_index=(bool)preg_match('/<sitemapindex\b/i',$x['body']);
            foreach($m[1] as $loc){
                $u=GNF5_Utils::normalize_url(html_entity_decode(strip_tags($loc)));
                if(!$u || self::host_key($u)!==self::host_key($category_url))continue;
                if($is_index && $depth<2){$queue[]=array($u,$depth+1);continue;}
                $path=strtolower((string)wp_parse_url($u,PHP_URL_PATH));
                $match=!$needles;
                foreach($needles as $needle){if($needle&&strpos($path,strtolower($needle))!==false){$match=true;break;}}
                if(!$match)continue;
                if(self::article_url_score($u,$category_url,'')<=2)continue;
                $items[]=array('url'=>$u,'title'=>'','fallback_text'=>'','method'=>'Public sitemap');
                if(count($items)>=$remaining)break;
            }
        }
        return self::unique_items($items);
    }

    /**
     * Test a trusted external link without altering it.
     * 2xx/3xx = OK. 401/403/429 = restricted (may be anti-bot, not necessarily broken in a browser).
     * 404/410 = broken. Other failures are reported as unavailable.
     */
    public static function test_external_link($url, $force = false) {
        $url = GNF5_Utils::normalize_url($url);
        if (!$url) { return new WP_Error('external_url', 'Invalid external URL.'); }

        $home_host = self::host_key(home_url('/'));
        if (self::host_key($url) === $home_host) {
            return new WP_Error('external_internal', 'This is an internal site URL, not an external link.');
        }

        $cache_key = 'gnf5_extcheck_' . md5($url);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['status'])) { return $cached; }
        }

        $args = array(
            'timeout'=>12,'redirection'=>5,
            'user-agent'=>'GlobiqNewsFreshPublisher/'.GNF5_VERSION.' (+'.home_url('/').')',
            'headers'=>array('Accept'=>'text/html,application/xhtml+xml,*/*;q=0.7'),
        );

        $response = wp_safe_remote_head($url, $args);
        $code = is_wp_error($response) ? 0 : absint(wp_remote_retrieve_response_code($response));

        if (is_wp_error($response) || in_array($code, array(0,405,501), true)) {
            $args['limit_response_size'] = 4096;
            $response = wp_safe_remote_get($url, $args);
            $code = is_wp_error($response) ? 0 : absint(wp_remote_retrieve_response_code($response));
        }

        if (is_wp_error($response)) {
            $result = array('url'=>$url,'status'=>'unavailable','code'=>0,'message'=>$response->get_error_message());
        } elseif ($code >= 200 && $code < 400) {
            $result = array('url'=>$url,'status'=>'ok','code'=>$code,'message'=>'Public link reachable.');
        } elseif (in_array($code, array(401,403,429), true)) {
            $result = array('url'=>$url,'status'=>'restricted','code'=>$code,'message'=>'Server restricted automated checking; link may still work for normal visitors.');
        } elseif (in_array($code, array(404,410), true)) {
            $result = array('url'=>$url,'status'=>'broken','code'=>$code,'message'=>'Link returned HTTP '.$code.'.');
        } else {
            $result = array('url'=>$url,'status'=>'unavailable','code'=>$code,'message'=>'Link returned HTTP '.$code.'.');
        }

        set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
        return $result;
    }

    public static function extract_article($url, $rss_fallback = '') {
        $canonical = GNF5_Utils::normalize_url($url);
        $f = self::fetch($canonical, 'page');
        if (is_wp_error($f)) {
            // Safe deterministic public alternatives (for example an official print URL)
            // may still be available even when the normal presentation URL blocks server fetches.
            foreach (self::public_alternative_urls('', $canonical) as $alt) {
                if (GNF5_Utils::same_resource_url($alt, $canonical)) { continue; }
                $af = self::fetch($alt, 'page');
                if (is_wp_error($af)) { continue; }
                $ap = self::parse_article_html($af['body'], $canonical);
                if (!is_wp_error($ap) && GNF5_Utils::word_count($ap['text']) >= 80) {
                    $ap['url'] = $canonical;
                    $ap['method'] = 'Official public print/alternate fallback';
                    return $ap;
                }
            }
            if (GNF5_Utils::word_count($rss_fallback) >= 80) {
                return array('url'=>$canonical,'title'=>'','text'=>trim($rss_fallback),'method'=>'RSS text fallback');
            }
            return $f;
        }
        $parsed = self::parse_article_html($f['body'], $f['url']);
        if (!is_wp_error($parsed) && GNF5_Utils::word_count($parsed['text']) >= 80) { return $parsed; }

        $alts = self::public_alternative_urls($f['body'], $f['url']);
        foreach ($alts as $alt) {
            $af = self::fetch($alt, 'page');
            if (is_wp_error($af)) { continue; }
            $ap = self::parse_article_html($af['body'], $f['url']);
            if (!is_wp_error($ap) && GNF5_Utils::word_count($ap['text']) >= 80) {
                $ap['url'] = $f['url'];
                $ap['method'] = 'Public AMP/print fallback';
                return $ap;
            }
        }
        if (GNF5_Utils::word_count($rss_fallback) >= 80) {
            return array('url'=>$f['url'],'title'=>is_wp_error($parsed)?'':($parsed['title']??''),'text'=>trim($rss_fallback),'method'=>'RSS text fallback');
        }
        return new WP_Error('extract_short', 'Could not extract enough public article text from '.$f['url']);
    }

    private static function parse_article_html($html, $canonical_url) {
        if (!class_exists('DOMDocument')) { return new WP_Error('dom', 'PHP DOM extension is required.'); }
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xp = new DOMXPath($doc);
        $title = '';
        $json_bodies = array();
        $json_descriptions = array();
        foreach ($xp->query('//script[@type="application/ld+json"]') as $node) {
            $raw = trim($node->textContent);
            if (!$raw) { continue; }
            $json = json_decode($raw, true);
            if (is_array($json)) { self::walk_jsonld($json, $title, $json_bodies, $json_descriptions); }
        }
        // Only a genuine articleBody is strong enough to short-circuit HTML extraction.
        // Listing/category pages often contain many NewsArticle descriptions; those must not
        // be merged and mistaken for one direct article.
        if ($json_bodies) {
            $best_body='';$best_title='';$best_words=0;$seen_bodies=array();
            foreach($json_bodies as $entry){
                $body=is_array($entry)?trim((string)($entry['text']??'')):trim((string)$entry);
                if($body==='' || isset($seen_bodies[md5($body)]))continue;
                $seen_bodies[md5($body)]=true;
                $words=GNF5_Utils::word_count($body);
                if($words>$best_words){
                    $best_words=$words;$best_body=$body;
                    $best_title=is_array($entry)?sanitize_text_field((string)($entry['title']??'')):'';
                }
            }
            if ($best_words >= 80) {
                return array('url'=>$canonical_url,'title'=>$best_title?:$title,'text'=>$best_body,'method'=>'JSON-LD articleBody');
            }
        }
        if (!$title) { $n=$xp->query('//h1')->item(0); if($n)$title=trim(preg_replace('/\s+/u',' ',$n->textContent)); }
        if (!$title) { $n=$xp->query('//meta[@property="og:title"]')->item(0); if($n)$title=trim($n->getAttribute('content')); }
        if (!$title) { $n=$xp->query('//title')->item(0); if($n)$title=trim(preg_replace('/\s+/u',' ',$n->textContent)); }

        foreach (array('//script','//style','//nav','//footer','//header','//aside','//form','//noscript','//figure','//picture','//svg','//iframe') as $q) {
            $nodes=$xp->query($q);if(!$nodes)continue;$remove=array();foreach($nodes as $n)$remove[]=$n;
            foreach($remove as $n){if($n->parentNode)$n->parentNode->removeChild($n);}
        }
        $queries = array(
            '//*[@itemprop="articleBody"]//p','//article//p',
            '//*[contains(concat(" ",normalize-space(@class)," ")," article-body ")]//p',
            '//*[contains(@class,"articleBody")]//p','//*[contains(@class,"article__body")]//p',
            '//*[contains(@class,"article-content")]//p','//*[contains(@class,"article_content")]//p',
            '//*[contains(@class,"story-content")]//p','//*[contains(@class,"story_content")]//p',
            '//*[contains(@class,"entry-content")]//p','//*[contains(@class,"post-content")]//p',
            '//*[contains(@class,"content-body")]//p','//*[contains(@class,"content__body")]//p',
            '//*[contains(@class,"story__content")]//p','//*[@data-testid="article-body"]//p',
            '//main//p',
        );
        $parts=array();
        foreach($queries as $q){
            $local=array();
            foreach($xp->query($q) as $n){
                $v=trim(preg_replace('/\s+/u',' ',$n->textContent));
                if(GNF5_Utils::word_count($v)>=5)$local[]=$v;
            }
            if(GNF5_Utils::word_count(implode(' ',$local))>=80){$parts=$local;break;}
        }
        if(!$parts){
            foreach($xp->query('//p') as $n){
                $v=trim(preg_replace('/\s+/u',' ',$n->textContent));
                if(GNF5_Utils::word_count($v)>=8)$parts[]=$v;
            }
        }
        $parts=array_slice(array_values(array_unique($parts)),0,240);
        $text=trim(implode("\n\n",$parts));
        return array('url'=>$canonical_url,'title'=>$title,'text'=>$text,'method'=>'HTML article paragraphs');
    }

    private static function walk_jsonld($node, &$title, &$bodies, &$descriptions) {
        if (!is_array($node)) { return; }
        $type = $node['@type'] ?? '';
        $types = is_array($type) ? $type : array($type);
        $is_article = false;
        foreach ($types as $t) {
            if (in_array(strtolower((string)$t), array('article','newsarticle','blogposting','reportagenewsarticle'), true)) { $is_article = true; break; }
        }
        if ($is_article) {
            if (!$title && !empty($node['headline'])) { $title = sanitize_text_field($node['headline']); }
            if (!empty($node['articleBody']) && is_string($node['articleBody'])) {
                $body_title='';
                foreach(array('headline','name','title') as $title_key){
                    if(!empty($node[$title_key])&&is_string($node[$title_key])){$body_title=sanitize_text_field($node[$title_key]);break;}
                }
                $bodies[] = array(
                    'text'=>trim(wp_strip_all_tags($node['articleBody'])),
                    'title'=>$body_title,
                );
            }
            if (!empty($node['description']) && is_string($node['description'])) {
                $descriptions[] = trim(wp_strip_all_tags($node['description']));
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) { self::walk_jsonld($v, $title, $bodies, $descriptions); }
        }
    }

    private static function is_navigation_or_sidebar_link($node) {
        $p = $node;
        for ($i=0; $i<8 && $p; $i++, $p=$p->parentNode) {
            if ($p->nodeType !== XML_ELEMENT_NODE) { continue; }
            $tag = strtolower((string)$p->nodeName);
            if (in_array($tag, array('nav','header','footer','aside','form'), true)) { return true; }
            $class = strtolower((string)($p->attributes && $p->attributes->getNamedItem('class') ? $p->attributes->getNamedItem('class')->nodeValue : ''));
            $id = strtolower((string)($p->attributes && $p->attributes->getNamedItem('id') ? $p->attributes->getNamedItem('id')->nodeValue : ''));
            if (preg_match('/\\b(nav|menu|footer|header|sidebar|breadcrumb|social|account|login|related|recommended|trending|popular|sponsored|advert)\\b|most[-_ ]?read|more[-_ ]?stories|you[-_ ]?may/i', $class.' '.$id)) { return true; }
        }
        return false;
    }

    private static function public_alternative_urls($html, $base) {
        $out = array();
        if (class_exists('DOMDocument')) {
            libxml_use_internal_errors(true);$d=new DOMDocument();@$d->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOWARNING|LIBXML_NOERROR);$xp=new DOMXPath($d);
            foreach ($xp->query('//link[@rel="amphtml"] | //a[contains(translate(@href,"PRINTAMP","printamp"),"print")]') as $n) {
                $u=self::absolute_url($n->getAttribute('href'),$base);
                if($u&&self::host_key($u)===self::host_key($base))$out[]=$u;
            }
        }
        $path=(string)wp_parse_url($base,PHP_URL_PATH);
        if(preg_match('~/articleshow/(\d+)\.cms$~i',$path,$m)&&stripos((string)wp_parse_url($base,PHP_URL_HOST),'timesofindia.indiatimes.com')!==false){
            $origin=(wp_parse_url($base,PHP_URL_SCHEME)?:'https').'://'.wp_parse_url($base,PHP_URL_HOST);
            $out[]=$origin.'/articleshowprint/'.$m[1].'.cms';
        }
        return array_values(array_unique(array_filter(array_map(array('GNF5_Utils','normalize_url'),$out))));
    }

    private static function absolute_url($href, $base) {
        $href=trim(html_entity_decode((string)$href));
        if(!$href||$href[0]==='#'||stripos($href,'javascript:')===0||stripos($href,'mailto:')===0)return '';
        if(preg_match('~^https?://~i',$href))return GNF5_Utils::normalize_url($href);
        $b=wp_parse_url($base);if(!$b||empty($b['host']))return '';
        $scheme=$b['scheme']??'https';
        if(strpos($href,'//')===0)return GNF5_Utils::normalize_url($scheme.':'.$href);
        $origin=$scheme.'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
        if(strpos($href,'/')===0)return GNF5_Utils::normalize_url($origin.$href);
        $dir=preg_replace('~/[^/]*$~','/',$b['path']??'/');
        return GNF5_Utils::normalize_url($origin.$dir.$href);
    }

    private static function host_key($url) {
        $h=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
        return preg_replace('/^www\./','',$h);
    }

    private static function unique_items($items) {
        $seen=array();$out=array();
        foreach((array)$items as $item){
            $u=GNF5_Utils::normalize_url($item['url']??'');
            if(!$u)continue;
            $key=GNF5_Utils::source_identity_url($u)?:$u;
            if(isset($seen[$key]))continue;
            $seen[$key]=true;$item['url']=$u;$out[]=$item;
        }
        return $out;
    }
}
