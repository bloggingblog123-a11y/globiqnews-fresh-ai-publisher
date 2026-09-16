<?php
if (!defined('ABSPATH')) { exit; }

class GNF5_Images {
    /**
     * Image providers receive text only. Strip any URL/data-image token that could
     * accidentally arrive through generated/custom prompt text so a source image
     * can never be passed as an image/reference URL by this plugin.
     */
    private static function sanitize_text_only_prompt($prompt) {
        $prompt=(string)$prompt;
        $prompt=preg_replace("~(?:https?://|data:image/)[^\\s<>\"']+~iu",'[URL omitted]',$prompt);
        return trim((string)$prompt);
    }
    public static function test() {
        $s=GNF5_Utils::settings();
        if (empty($s['image_enabled'])) { return new WP_Error('images_disabled','Image generation is disabled.'); }
        return self::generate_one_with_retry(
            'GlobiqNews image test',
            'Original professional editorial illustration of a modern digital newsroom, abstract screens and news workflow, 16:9, no text, no logos, no watermark, no existing photograph reference.'
        );
    }

    public static function generate_for_post($article,$post_id,$cat_id=0) {
        $s=GNF5_Utils::settings();
        if (empty($s['image_enabled'])) { return array(); }

        $stored=get_post_meta($post_id,'_gnf5_image_ids',true);
        $stored=is_array($stored)?$stored:array();
        $ids=array();

        for ($i=0;$i<2;$i++) {
            $existing=absint($stored[$i]??0);
            // Corrupt/old checkpoint data must never reuse one attachment as both required images.
            if($existing && in_array($existing,$ids,true)){$existing=0;}
            if ($existing && GNF5_Utils::valid_attachment($existing)) {
                $ids[$i]=$existing;
                continue;
            }

            $prompt=trim((string)($article['image_prompts'][$i]??''));
            $prompt.="\nCreate a completely original article-specific editorial visual from text only. Landscape 16:9. Do not copy, recreate, trace, transform, imitate, or reference any source/existing image. No logos, watermark, signature, website branding, or readable text.";
            $custom=trim((string)($s['global_image_instructions']??''));
            if ($custom!=='') {
                $prompt.="\nCUSTOM IMAGE INSTRUCTIONS (apply only when they do not conflict with originality/safety/no-source-image rules):\n".$custom;
            }
            $prompt=self::sanitize_text_only_prompt($prompt);

            $id=self::generate_one_with_retry($article['title'].' image '.($i+1),$prompt,$cat_id);
            if (is_wp_error($id)) {
                update_post_meta($post_id,'_gnf5_image_ids',$ids);
                return new WP_Error('image_'.$i,$id->get_error_message(),array('partial_ids'=>$ids));
            }

            // Verify the two required images are not the exact same file bytes. If a provider
            // returned a duplicate, remove only the duplicate attachment and retry image 2
            // with an explicitly different-composition instruction.
            if($i===1 && !empty($ids[0])){
                $f1=get_attached_file($ids[0]);$f2=get_attached_file($id);
                $same=false;
                if($f1&&$f2&&file_exists($f1)&&file_exists($f2)){
                    $h1=@md5_file($f1);$h2=@md5_file($f2);$same=($h1&&$h2&&hash_equals($h1,$h2));
                }
                if($same){
                    wp_delete_attachment($id,true);
                    $different_prompt=self::sanitize_text_only_prompt($prompt."\nMANDATORY: make this second image visibly different from image 1 in composition, viewpoint, framing, and subject arrangement.");
                    $id=self::generate_one_with_retry($article['title'].' image 2 alternate',$different_prompt,$cat_id);
                    if(is_wp_error($id)){
                        update_post_meta($post_id,'_gnf5_image_ids',$ids);
                        return new WP_Error('image_'.$i,$id->get_error_message(),array('partial_ids'=>$ids));
                    }
                    $f2=get_attached_file($id);$h2=($f2&&file_exists($f2))?@md5_file($f2):false;
                    if($h1&&$h2&&hash_equals($h1,$h2)){
                        wp_delete_attachment($id,true);
                        update_post_meta($post_id,'_gnf5_image_ids',$ids);
                        return new WP_Error('image_duplicate','Image provider returned the same image twice; second image will be retried during recovery.',array('partial_ids'=>$ids));
                    }
                }
            }
            update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($article['image_alts'][$i]??''));
            $parented=wp_update_post(array('ID'=>$id,'post_parent'=>$post_id),true);
            if(is_wp_error($parented)){GNF5_Utils::log('Generated image #'.$id.' saved, but attachment parent assignment failed: '.$parented->get_error_message(),'warning',$cat_id);}
            $ids[$i]=absint($id);
            update_post_meta($post_id,'_gnf5_image_ids',$ids); // checkpoint after every successful image
        }

        ksort($ids);
        return array_values($ids);
    }

    public static function generate_two($article) {
        // Kept for compatibility with any existing V5.x call sites/tests.
        $ids=array();
        for($i=0;$i<2;$i++){
            $prompt=trim((string)($article['image_prompts'][$i]??''));
            $prompt.="\nCreate from text only. Never copy, recreate, trace, transform, imitate, download, or reference any source/existing image or image URL.";
            $prompt=self::sanitize_text_only_prompt($prompt);
            $id=self::generate_one_with_retry($article['title'].' image '.($i+1),$prompt);
            if(is_wp_error($id))return $id;
            update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field($article['image_alts'][$i]??''));
            $ids[]=$id;
        }
        return $ids;
    }

    private static function retryable_error($error) {
        if(!is_wp_error($error))return false;
        $code=(string)$error->get_error_code();

        if(in_array($code,array('http_request_failed','openai_decode','openai_no_image','openai_empty','openai_invalid_image','webui_empty','webui_decode','webui_invalid_image'),true))return true;
        if(preg_match('/^openai_http_(408|429)$/',$code))return true;
        if(preg_match('/^openai_download_http_(408|429)$/',$code))return true;
        if(preg_match('/^openai_http_5\d\d$/',$code))return true;
        if(preg_match('/^openai_download_http_5\d\d$/',$code))return true;
        if(preg_match('/^webui_http_(408|429)$/',$code))return true;
        if(preg_match('/^webui_http_5\d\d$/',$code))return true;

        // API key, model, moderation/user-input and other stable 4xx errors should not
        // be retried three times with the identical request.
        return false;
    }

    public static function generate_one_with_retry($title,$prompt,$cat_id=0) {
        $s=GNF5_Utils::settings();
        $provider=(string)$s['image_provider'];
        $last=new WP_Error('image','Image generation failed.');
        for($attempt=1;$attempt<=3;$attempt++){
            if($cat_id)GNF5_Utils::touch_lock($cat_id);
            $r=self::generate_one($title,$prompt);
            if(!is_wp_error($r)){
                if($attempt>1)GNF5_Utils::log('Image generation recovered on attempt '.$attempt.'.','success');
                return $r;
            }
            $last=$r;
            GNF5_Utils::log('Image generation attempt '.$attempt.' failed: '.$r->get_error_message(),'warning');
            if(!self::retryable_error($r))break;
            if($attempt<3)usleep(350000*$attempt);
        }

        // Built-in fallback runs only after the configured provider has finished its retry cycle.
        if($provider!=='builtin' && !empty($s['builtin_fallback'])){
            GNF5_Utils::log('Primary image provider still failed after retry policy — using built-in original graphics fallback.','warning');
            return self::builtin($title,$prompt);
        }
        return $last;
    }

    public static function generate_one($title,$prompt) {
        $s=GNF5_Utils::settings();
        $provider=$s['image_provider'];
        if($provider==='openai')return self::openai($title,$prompt);
        if($provider==='webui')return self::webui($title,$prompt);
        return self::builtin($title,$prompt);
    }

    private static function openai($title, $prompt) {
        $s = GNF5_Utils::settings();
        $key = trim((string)$s['openai_api_key']);
        if (!$key) { return new WP_Error('openai_key','OpenAI API key is missing.'); }
        $payload = array(
            'model' => $s['openai_model'],
            'prompt' => $prompt,
            'n' => 1,
            'size' => $s['openai_size'],
            'quality' => $s['openai_quality'],
            'output_format' => 'webp',
        );
        $response = self::openai_request($payload, $key);
        if (is_wp_error($response) && $response->get_error_code() === 'openai_format') {
            unset($payload['output_format']);
            $response = self::openai_request($payload, $key);
        }
        if (is_wp_error($response)) { return $response; }
        $bytes = $response['bytes']; $mime = $response['mime'];
        $optimized = self::optimize_low_storage_webp($bytes);
        if ($optimized) { $bytes = $optimized; $mime = 'image/webp'; }
        return self::save_bytes($bytes, $title, $mime);
    }

    private static function openai_request($payload, $key) {
        $r = wp_remote_post('https://api.openai.com/v1/images/generations', array(
            'timeout' => 240,
            'headers' => array('Authorization'=>'Bearer '.$key, 'Content-Type'=>'application/json'),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($r)) { return $r; }
        $code = absint(wp_remote_retrieve_response_code($r));
        $raw = (string)wp_remote_retrieve_body($r);
        $j = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = $j['error']['message'] ?? wp_strip_all_tags($raw);
            if (isset($payload['output_format']) && stripos((string)$msg, 'output_format') !== false) {
                return new WP_Error('openai_format', 'Image API rejected output_format; retrying compatible request.');
            }
            return new WP_Error('openai_http_'.$code, 'OpenAI Image API HTTP '.$code.': '.GNF5_Utils::safe_substr((string)$msg,0,400), array('http_code'=>$code));
        }
        $b64 = $j['data'][0]['b64_json'] ?? '';
        if ($b64) {
            $bytes = base64_decode($b64, true);
            if (!$bytes) { return new WP_Error('openai_decode','OpenAI returned invalid base64 image data.'); }
            $mime=self::detect_mime($bytes);
            if(!$mime){return new WP_Error('openai_invalid_image','OpenAI returned data that is not a valid supported image.');}
            return array('bytes'=>$bytes,'mime'=>$mime);
        }
        $url = $j['data'][0]['url'] ?? '';
        if ($url) {
            $download = wp_safe_remote_get($url, array('timeout'=>90,'redirection'=>3,'limit_response_size'=>25*1024*1024));
            if (is_wp_error($download)) { return $download; }
            $download_code=absint(wp_remote_retrieve_response_code($download));
            if($download_code<200||$download_code>=300){return new WP_Error('openai_download_http_'.$download_code,'OpenAI image download returned HTTP '.$download_code.'.');}
            $bytes = wp_remote_retrieve_body($download);
            if (!$bytes) { return new WP_Error('openai_empty','OpenAI image URL returned empty data.'); }
            $mime=self::detect_mime($bytes);
            if(!$mime){return new WP_Error('openai_invalid_image','OpenAI image URL returned non-image/unsupported data.');}
            return array('bytes'=>$bytes,'mime'=>$mime);
        }
        return new WP_Error('openai_no_image','OpenAI Image API returned no image data.');
    }

    private static function webui($title, $prompt) {
        $s = GNF5_Utils::settings();
        $base = rtrim((string)$s['webui_endpoint'],'/');
        if (!$base) { return new WP_Error('webui_endpoint','Self-hosted image server URL is missing.'); }
        $size = explode('x', $s['openai_size']);
        $width = absint($size[0] ?? 1024); $height = absint($size[1] ?? 1024);
        $body = array(
            'prompt' => $prompt,
            'negative_prompt' => 'watermark, logo, signature, readable text, copied photograph, screenshot, blurry, duplicate',
            'steps' => 24, 'cfg_scale' => 5.5, 'width' => $width, 'height' => $height,
            'batch_size' => 1, 'n_iter' => 1,
        );
        if (!empty($s['webui_model'])) { $body['override_settings'] = array('sd_model_checkpoint'=>$s['webui_model']); }
        $headers = array('Content-Type'=>'application/json');
        if (!empty($s['webui_api_key'])) { $headers['Authorization'] = 'Bearer '.$s['webui_api_key']; }
        $r = wp_remote_post($base.'/sdapi/v1/txt2img', array('timeout'=>240,'headers'=>$headers,'body'=>wp_json_encode($body)));
        if (is_wp_error($r)) { return $r; }
        $code = absint(wp_remote_retrieve_response_code($r)); $raw=(string)wp_remote_retrieve_body($r);
        if ($code < 200 || $code >= 300) { return new WP_Error('webui_http_'.$code,'Image server HTTP '.$code.': '.GNF5_Utils::safe_substr(wp_strip_all_tags($raw),0,300), array('http_code'=>$code)); }
        $j=json_decode($raw,true); $b64=$j['images'][0]??'';
        if (!$b64) { return new WP_Error('webui_empty','Image server returned no image.'); }
        if (strpos($b64,',')!==false) { $b64=substr($b64,strpos($b64,',')+1); }
        $bytes=base64_decode($b64,true); if(!$bytes){ return new WP_Error('webui_decode','Invalid image data returned by image server.'); }
        $mime=self::detect_mime($bytes); if(!$mime){return new WP_Error('webui_invalid_image','Image server returned non-image/unsupported data.');}
        $optimized=self::optimize_low_storage_webp($bytes);
        if($optimized){$bytes=$optimized;$mime='image/webp';}
        return self::save_bytes($bytes,$title,$mime);
    }

    private static function builtin($title, $prompt) {
        if (!function_exists('imagecreatetruecolor')) { return new WP_Error('gd','PHP GD extension is required for built-in fallback graphics.'); }
        $s = GNF5_Utils::settings();
        $w=1200; $h=675; $im=imagecreatetruecolor($w,$h); imagealphablending($im,true);
        $seed = abs(crc32($title.'|'.$prompt.'|'.wp_generate_password(8,false)));
        $r1=35+($seed%150); $g1=35+(($seed>>4)%150); $b1=35+(($seed>>8)%150);
        $r2=30+(($seed>>3)%160); $g2=30+(($seed>>7)%160); $b2=30+(($seed>>11)%160);
        for($y=0;$y<$h;$y++){
            $t=$y/max(1,$h-1); $c=imagecolorallocate($im,(int)($r1*(1-$t)+$r2*$t),(int)($g1*(1-$t)+$g2*$t),(int)($b1*(1-$t)+$b2*$t));
            imageline($im,0,$y,$w,$y,$c);
        }
        for($i=0;$i<18;$i++){
            $x=($seed*($i+5)*31)%$w; $y=($seed*($i+7)*47)%$h;
            $rw=100+(($seed>>($i%15))%350); $rh=70+(($seed>>(($i+3)%15))%260);
            $col=imagecolorallocatealpha($im,255,255,255,85+(($i*5)%30)); imagefilledellipse($im,$x,$y,$rw,$rh,$col);
        }
        $dark=imagecolorallocatealpha($im,0,0,0,75); imagefilledrectangle($im,0,(int)($h*.78),$w,$h,$dark);
        ob_start();
        if (function_exists('imagewebp')) { imagewebp($im,null,$s['webp_quality']); $mime='image/webp'; }
        else { imagejpeg($im,null,$s['webp_quality']); $mime='image/jpeg'; }
        $bytes=ob_get_clean(); imagedestroy($im);
        return self::save_bytes($bytes,$title,$mime);
    }

    private static function optimize_low_storage_webp($bytes) {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled') || !function_exists('imagewebp')) {
            return false;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src) { return false; }
        $sw = imagesx($src); $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) { imagedestroy($src); return false; }

        // Store one consistent, news-friendly 16:9 master. This strips metadata and cuts storage.
        $tw = 1200; $th = 675;
        $target_ratio = $tw / $th;
        $source_ratio = $sw / $sh;
        if ($source_ratio > $target_ratio) {
            $crop_h = $sh;
            $crop_w = (int)round($sh * $target_ratio);
            $sx = (int)max(0, floor(($sw - $crop_w) / 2));
            $sy = 0;
        } else {
            $crop_w = $sw;
            $crop_h = (int)round($sw / $target_ratio);
            $sx = 0;
            $sy = (int)max(0, floor(($sh - $crop_h) / 2));
        }

        $dst = imagecreatetruecolor($tw, $th);
        if (!$dst) { imagedestroy($src); return false; }
        $ok = imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $crop_w, $crop_h);
        imagedestroy($src);
        if (!$ok) { imagedestroy($dst); return false; }

        ob_start();
        imagewebp($dst, null, absint(GNF5_Utils::settings()['webp_quality'] ?? 72));
        $out = ob_get_clean();
        imagedestroy($dst);
        return $out ?: false;
    }

    private static function detect_mime($bytes) {
        if(!$bytes)return '';
        if(function_exists('getimagesizefromstring')){
            $info=@getimagesizefromstring($bytes);
            $mime=is_array($info)?strtolower((string)($info['mime']??'')):'';
            if(in_array($mime,array('image/webp','image/png','image/jpeg'),true))return $mime;
            return '';
        }
        if (substr($bytes,0,4)==="RIFF" && substr($bytes,8,4)==="WEBP") { return 'image/webp'; }
        if (substr($bytes,0,8)==="\x89PNG\r\n\x1a\n") { return 'image/png'; }
        if (substr($bytes,0,3)==="\xff\xd8\xff") { return 'image/jpeg'; }
        return '';
    }

    private static function save_bytes($bytes, $title, $mime) {
        if (!$bytes) { return new WP_Error('image_empty','Empty image data.'); }
        $detected=self::detect_mime($bytes);
        if(!$detected){return new WP_Error('image_invalid','Generated/downloaded data is not a valid supported image.');}
        $mime=$detected;
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $ext = $mime==='image/webp'?'webp':($mime==='image/jpeg'?'jpg':'png');
        $name=sanitize_file_name(sanitize_title($title).'-'.substr(wp_generate_password(8,false),0,8).'.'.$ext);
        $upload=wp_upload_bits($name,null,$bytes);
        if(!empty($upload['error'])){ return new WP_Error('upload',$upload['error']); }
        $id=wp_insert_attachment(array('post_mime_type'=>$mime,'post_title'=>sanitize_text_field($title),'post_status'=>'inherit'),$upload['file'],0,true);
        if(is_wp_error($id) || !$id){
            if(file_exists($upload['file'])){@unlink($upload['file']);}
            return is_wp_error($id)?$id:new WP_Error('attachment_insert','WordPress could not create the generated image attachment.');
        }

        // Low-storage rule: this plugin already stores a web-ready 1200x675-ish original.
        // Prevent WordPress from creating several extra intermediate copies for this generated
        // attachment only. Other Media Library uploads remain completely unaffected.
        $suppress_sizes=function($sizes,$metadata,$attachment_id) use($id){
            return absint($attachment_id)===absint($id) ? array() : $sizes;
        };
        add_filter('intermediate_image_sizes_advanced',$suppress_sizes,10,3);
        $meta=wp_generate_attachment_metadata($id,$upload['file']);
        remove_filter('intermediate_image_sizes_advanced',$suppress_sizes,10);
        wp_update_attachment_metadata($id,$meta);

        update_post_meta($id,'_gnf5_original_generated',1);
        update_post_meta($id,'_gnf5_low_storage_no_subsizes',1);
        return absint($id);
    }

    public static function block_array($id, $alt) {
        $url = wp_get_attachment_image_url($id,'full');
        if (!$url) { return null; }
        $html = '<figure class="wp-block-image size-full"><img src="'.esc_url($url).'" alt="'.esc_attr($alt).'" class="wp-image-'.absint($id).'"/></figure>';
        return array(
            'blockName'=>'core/image',
            'attrs'=>array('id'=>absint($id),'sizeSlug'=>'full','linkDestination'=>'none'),
            'innerBlocks'=>array(),'innerHTML'=>$html,'innerContent'=>array($html),
        );
    }

    public static function block($id, $alt) {
        $block=self::block_array($id,$alt);
        if(!$block)return '';
        return function_exists('serialize_block') ? serialize_block($block) : $block['innerHTML'];
    }

    public static function insert_two_blocks($content, $ids, $alts) {
        if (count($ids)!==2 || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) { return $content; }
        $blocks=parse_blocks($content);
        $imgs=array();
        for($i=0;$i<2;$i++){
            $b=self::block_array($ids[$i],$alts[$i]??'');
            if($b)$imgs[]=$b;
        }
        if(count($imgs)!==2)return $content;

        $out=array();$h2_seen=0;$inserted=array(false,false);
        foreach($blocks as $index=>$block){
            if(!$inserted[0] && $index===0 && ($block['blockName']??'')!=='rank-math/toc-block'){
                $out[]=$imgs[0];$inserted[0]=true;
            }

            $out[]=$block;

            if(($block['blockName']??'')==='rank-math/toc-block' && !$inserted[0]){
                $out[]=$imgs[0];$inserted[0]=true;
                continue;
            }

            if(($block['blockName']??'')==='core/heading' && absint($block['attrs']['level']??2)===2){
                $h2_seen++;
                if($h2_seen===2 && !$inserted[1]){$out[]=$imgs[1];$inserted[1]=true;}
            }
        }

        if(!$inserted[0])$out=array_merge(array($imgs[0]),$out);
        if(!$inserted[1])$out[]=$imgs[1];
        return serialize_blocks($out);
    }
}
