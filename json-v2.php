<?php

header( 'Content-type: application/json' );

define( 'WSJ_CRON', true );

require_once( 'wp-load.php' );

//-----------------------------------------------------------------------------------------------------
//  return json formatted info about
//  - a single post:    http://blogs.barrons.com/wsj-json-v2.php?guid=BL-SWB-31653&format=social&include-live-blog-entries=true&src=t1contentsvc&tag=48743311
//                      http://blogs.wsj.com/wsj-json-v2.php?guid=BL-MBB-49285
//  - a liveblog post:  http://blogs.wsj.com/wsj-json-v2.php?guid=BL-MBB-49290&include-all-in-post-content=true&offset=1462383896466
//
//
//  can be called from:
//  - t1contensvc (see example above with src=t1contensvc)
//  - ?
//-----------------------------------------------------------------------------------------------------

class WSJJSONFeed{

    // things we expose/check in more than one place - don't want duplicate DB lookups:

    private $blog_id;
    private $post_tags;
    private $post_categories;
    private $privilege;
    private $from_t1contentsvc;

    //-----------------------------------------------------------------------------------------------------
    //  the constructor...
    //-----------------------------------------------------------------------------------------------------

    public function __construct()
    {
        remove_shortcode('wsj-quote');
        add_shortcode('wsj-quote', array(&$this, 'wsjQuote'));

        // hack/convention: t1contensvc hits us with query string with a cachebuster e.g.`&tag=1038418` so we can use this to have
        // logic targeted at that service
        // NOTE: "tag" was chosen cause that was already included in Akamai cache key and we don't want to pay Akamai PS to update
        //cache keys. dominique has also added the query string `&src=t1contentsvc` which we can rely on in the future (and I have
        //added it to cache key query settings in akamai to hopefully go in next time we DO pay PS for something)

        $this->from_t1contentsvc = $_GET['tag']; // TODO replace with ($_GET['src'] == 't1contentsvc') when added to akamai query string cache key settings
    }


    //-----------------------------------------------------------------------------------------------------
    //  the constructor...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleTitle($p)
    {
        $title = html_entity_decode(get_the_title($p->ID), ENT_QUOTES, 'utf-8'); // decode into UTF-8. also, encode both double and single quotes

        if (get_option('blog_charset') != 'UTF-8')
            $title = utf8_encode($title);

        return ($title);
    }

    //-----------------------------------------------------------------------------------------------------
    //  get the article type from the article_type postmeta...
    //
    //  blogs.wsj.com the possible values are 0,1,2,4, and empty string
    //  marketwatch has 0,1,2, and empty string
    //  barrons does not have this postmeta so it will be an empty string
    //  deloitte.wsj.com does not have this postmeta so it will be an empty string
    //
    //  0 = Standard Article
    //  1 = Live Blog (On)
    //  2 = Live Blog (Off)
    //  4 = Suppress Article
    //  ""= will return "article"
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleType( $p )
    {
        $post_type = array(
            '1' => 'liveblog',
            '2' => 'liveblog',
            'post' => 'article',
            'wsj_slideshow' => 'slideshow' // this is a blogs slideshow (eg BL-120-77822) which is itself attached to an article (eg BL-SEB-77823)
        );

        //
        // get what is set in postmeta...
        
        $article_type = stripslashes( get_post_meta( $p->ID, 'article_type', true ) );

        //
        // check for the old-style slideshows...this probably won't happen much anymore...
        // responsive posts with a slideshow will have article_type=0
        
        if ($article_type == 4 && get_post_meta( $p->ID, 'article_slideshow', true )) {
            // type 4 is Suppress Article, so there is no article, just slideshow. see WSJCOM-10993
            return ('slideshow');
        }

        if( isset( $post_type[ $article_type ] ) ) return ($post_type[ $article_type ]);

        if( isset( $post_type[ $p->post_type ] ) ) return ($post_type[ $p->post_type ]);
     
        return ('article');
    }

    //-----------------------------------------------------------------------------------------------------
    //  return the value for "post_content"
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleContent($p) {

        $content = apply_filters('the_content', $p->post_content);

        $content = preg_replace_callback('#(\[wsj\-responsive\-(.*? )(.*?)])#mis', array(&$this, 'removeResponsiveShortCode'), $content);

        if (get_option('blog_charset') != 'UTF-8') {
            $content = utf8_encode($content);
        }

        return ($content);
    }

    //-----------------------------------------------------------------------------------------------------
    //  substitute empty string for responsive shortcodes...
    //-----------------------------------------------------------------------------------------------------

    public function removeResponsiveShortCode( $matches )
    {
        return '';
    }

    //-----------------------------------------------------------------------------------------------------
    //  return the value for "post_content" including all of the liveblog entries...
    //-----------------------------------------------------------------------------------------------------

    protected function getLiveBlogAsContent( $p )
    {
        
        global $wsj_env ; 

        $content = "";
        $post_id = $p->ID ;  
       
       $type_post =  get_post_meta( $post_id, 'post_type', true ) ;
       $blog_url = get_bloginfo('url') ; 

            //if cross-post get entries from original blog
    if ($type_post == "cross_post") {
        $blog_slug = stripslashes( get_post_meta($post_id,'source_blog',true)); 
        $blog_url = stripslashes( get_post_meta($post_id, 'source_url',true)); 
        $bl_id = get_post_meta($post_id, 'source_post_bl_id',true);
        $pos1 = strpos($bl_id , '-');
        $pos2 = strpos($bl_id, '-', $pos1 + strlen('-'));
        $post_id = substr($bl_id, $pos2+1,strlen($bl_id));
      }

        $entries = stripslashes_deep( get_post_meta( $post_id ,'article_live_blog' ) );
        if ( $entries && ! empty($entries) && isset($entries[0]['data']) && is_array($entries[0]['data']) ) {
            // hacky stuffed-in styles!
            $content .= '


<script type="text/javascript" src="http://s.wsj.net/blogs/js/mobile_update.js"></script>
<style>
    div.liveblog-entries .pMeta {
        list-style: none;
        margin: 0;
        padding: 0;
        color: #666;
        clear: both;
        display: block;
    }
    div.liveblog-entries .pMeta li.timeStamp {
        padding-left: 0;
        border: none;
        width: auto;
        margin: 0;
    }
    div.liveblog-entries .pMeta li {
        border-left: 1px #000 solid;
        font-size: 1.1em;
        line-height: 1em;
        color: #C74B15;
        font-weight: bold;
        padding: 0 8px;
        margin: 0;
        background: none;
        float: left;
        display: inline;
    }
    div.liveblog-entries .pMeta li .postAuthor {
        font-style: normal;
        font-weight: bold;
        line-height: 1em;
        color: #999;
    }
    div.liveblog-entries p {
        clear: both;
        padding-top: 8px;
    }
</style>
<div class="liveblog-entries">
';
            // rendered in blog's timezone
            // TODO: should be modified by JS to be in user's timezone?
            $prev_tz = date_default_timezone_get();
            $new_tz = get_option("timezone_string", "America/New_York");
            $new_tz = $new_tz == "" ? "America/New_York" : $new_tz;
            date_default_timezone_set($new_tz); // default to EST

             $article_type =  get_post_meta( $post_id, 'article_type' , true);
             if( $article_type == 1 ) {

            foreach (array_reverse($entries[0]['data']) as $data) // reversing to get newest first by default
                    {
                        
                        $entry_id = $data[ 'entry_id' ] ; 
                        $content .= '<hr>';
                        $content .= '<ul class="pMeta" id="entry_'.$entry_id.'">';

                        $content .= "  <li class='listFirst timeStamp'>" . date( 'g:i a', @$data[ 'entry_date' ] ) . "</li>";

                        if ( $data['entry_tag'] ) {
                            $content .= "  <li class='bl_tag'>$data[entry_tag]</li>";
                        }
                        if ( $data['entry_author'] ) {
                            $content .= "  <li><cite class='postAuthor'>$data[entry_author]</cite></li>";
                        }
                        
                        $content .= '</ul>';
                        
                        $content .= '<div class="content'.$entry_id.'">';
                       
                        if ( $data['entry_image'] ) {
                            $content .= '<p><img src="' . $data['entry_image'] . '" style="width:100%;"></p>';
                        }
                        if ( $data['entry_content'] ) {
                            $content .= apply_filters( 'the_content', $data['entry_content'] );
                            $content .= '</div>' ; 
                        }
            }
          } else {  //if liveblogging is off reverse it.
            
             foreach (($entries[0]['data']) as $data) // reversing to get newest first by default
            {
                $entry_id = $data[ 'entry_id' ] ; 
                $content .= '<hr>';
                $content .= '<ul class="pMeta" id="entry_'.$entry_id.'">';

                $content .= "  <li class='listFirst timeStamp'>" . date( 'g:i a', @$data[ 'entry_date' ] ) . "</li>";

                if ( $data['entry_tag'] ) {
                    $content .= "  <li class='bl_tag'>$data[entry_tag]</li>";
                }
                if ( $data['entry_author'] ) {
                    $content .= "  <li><cite class='postAuthor'>$data[entry_author]</cite></li>";
                }
                
                $content .= '</ul>';
                
                $content .= '<div class="content'.$entry_id.'">';
               
                if ( $data['entry_image'] ) {
                    $content .= '<p><img src="' . $data['entry_image'] . '" style="width:100%;"></p>';
                }
                if ( $data['entry_content'] ) {
                    $content .= apply_filters( 'the_content', $data['entry_content'] );
                    $content .= '</div>' ; 
                }
            }

         }      
             $content .= "</div>"; // end class liveblog-entries
        }
        date_default_timezone_set($prev_tz); // we don't mess up wp's own timezone magic in the rest of this script's execution        

        return $content;
    }


    //-----------------------------------------------------------------------------------------------------
    //  ...
    //-----------------------------------------------------------------------------------------------------

    protected function getSlideshowAsContent( $slideshow_guid )
    {
        if ( strpos($slideshow_guid, "BL") === 0 ) { // blogs slideshow
            // get current URL (wsj-json-v2.php in correct environment) and replace guid with guid of slideshow
            $json_url = preg_replace("/guid=[^&]*&?/", "guid=$slideshow_guid&", "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
            $slideshow = json_decode(wsj_include_url($json_url, 0), true); // true makes it associative array
            if ( ! isset($slideshow['post_photos']) || ! is_array($slideshow['post_photos']) ) return "";
            $photos = $slideshow['post_photos'];
        }
        else if ( strpos($slideshow_guid, "SB") === 0 ) { // main-site slideshow
            // always getting from prod, but FDEV and SAT t1svco seem to pull from prod anyways:
            $json_url = "http://virt1svco.wsjprod.dowjones.net/t1svco/articles/v1/Articles/$slideshow_guid";
            // in the future maybe we should use this URL since we own it: http://admin.stream.wsj.com/wsj-ajax.php?action=stream_content&uuid=SB10001424127887324886704579049861998580666 - would need to be handled differently here
            
            $slideshow = json_decode(wsj_include_url($json_url, 0), true); // true makes it associative array
            if ( ! isset($slideshow['insets']) || ! is_array($slideshow['insets']) ) return "";

            $photos = array(); // create this array to match format of blogs slideshow array above
            foreach ($slideshow['insets'] as $inset) {
                if ( ! isset($inset['imageInfo']) || ! isset($inset['imageInfo']['url']) ) {
                    continue;
                }
                $slide['photo_url'] = $inset['imageInfo']['url'];
                $slide['photo_caption'] = isset($inset['caption']) ? $inset['caption'] : "";
                $slide['photo_credit'] = isset($inset['credit']) ? $inset['credit'] : "";
                $photos[] = $slide;
            }
        }
        else {
            error_log("Error: unknown slideshow guid $slideshow_guid - expecting a BL-ID or SB #");
            return "";
        }

        ob_start(); // capture output to buffer

        foreach ($photos as $photo) { ?>

<hr>
<div class="mceTemp" style="text-align: left;">
    <dl class="wp-caption alignleft caption-alignleft">
        <dt class="wp-caption-dt"><img class="size-full wp-image-5" src="<?php echo $photo['photo_url']; ?>" alt=""/></dt>
        <dd class="wp-caption-dd" style="text-align: left;"><?php echo $photo['photo_caption']; ?></dd>
        <dd class="wp-caption-dd wp-cite-dd" style="text-align: right;"><?php echo $photo['photo_credit']; ?></dd>
    </dl>
</div>

        <?php }

        return ob_get_clean(); // return buffer output
    }


    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_date_gmt"...will be original post time in GMT in integer format. Ex: 1462392022
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleDateGMT( $p )
    {
        return get_post_time( 'U', true, $p );
    }

    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_updated_est"...will be the date/time of most recent major revision or the
    //  original publish time if no revision...integer format...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleUpdatedDateEST($p)
    {
        $revision = get_post_meta($p->ID, 'wsj_revision', true);

        $estTimezone = new DateTimeZone('America/New_York');
        $gmtTimezone = new DateTimeZone('GMT');

        //$offset = get_option('gmt_offset') * 3600; 

        // since major_revision_date is in GMT as well, we need to calculate EST date/time via calculating offset

        if (isset($revision['major_date']) && $revision['major_date']) {
            $article_updated = new DateTime(date('F j Y H:i:s', strtotime($revision['major_date'])), $gmtTimezone);
            $offset = $estTimezone->getOffset($article_updated);

            return strtotime($revision['major_date']) + $offset;
        }
        else {
            $article_published = new DateTime(date('F j Y H:i:s', get_post_time('U', true, $p)), $gmtTimezone);
            $offset = $estTimezone->getOffset($article_published);
            return get_post_time('U', true, $p) + $offset;
        }
    }


    //-----------------------------------------------------------------------------------------------------
    //  get the value for "post_summary"...this is what was entered in the Short Summary meta box...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSummary($p)
    {

        $summary = stripslashes(get_post_meta($p->ID, 'article_short_summary', true));

        //
        // for deloitte we want the excerpt instead...

        if ( isDeloitte() ) {

            $excerpt = $p->post_excerpt;

            if ( strlen($excerpt) > 0 ) {

                $summary = stripslashes($excerpt);

                //
                // get rid of possible <p></p>

                $rc = preg_match("#^<p>(.*)</p>#", $summary, $matches);

                if ( $rc == 1)
                    $summary = $matches[1];
            }
        }

        if (get_option('blog_charset') != 'UTF-8') {
            $summary = utf8_encode($summary);
        }

        return ($summary);
    }

    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_source"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSource($p)
    {
        //
        // first see if there is a custom option...

        $source = get_option('source');

        if ($source) return ($source);


        //
        // get the source from the domain...

        global $domain;

        preg_match( '/\.(wsj|barrons|marketwatch|smartmoney|dowjones|wallstreetjournal)\.(com|de)/', $domain, $matches );

        return (strtoupper($matches[1]) . '.' . $matches[2]);
    }

    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_privilege"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticlePrivilege( $p )
    {
        $privilege = stripslashes( get_post_meta( $p->ID, 'article_privilege', true ) );
        $this->privilege = $privilege;

        $privileges = array(
            0 => 'FREE',
            1 => 'WSJ_ONLY',
            2 => 'PRO_ONLY',
            3 => 'CFO_ONLY',
            4 => 'BDSA_ONLY',
            5 => 'CIO_ONLY'
        );

        return isset( $privileges[ $privilege ] ) ? $privileges[ $privilege ] : 'FREE';
    }

    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_section"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSection($p)
    {
        $section = stripslashes(get_post_meta($p->ID, 'f_section_name', true)); // five things section name
        if ($section) return $section;

        // hack to return section name that matches nav for realtime blogs, without changing Custom Option `section` that gets used elsewhere (like Omniture)
        if ($this->from_t1contentsvc) {
            $section = get_option('section_json_v2_override');
            if ($section) return $section;
        }

        $section = get_option('section');
        if ($section === false) $section = '';
        return $section;
    }


    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_subsection"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSubSection( $p )
    {
        $subsection = get_option( 'subsection' );
        if( $subsection === false ) $subsection = '';
        return $subsection;
    }

    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_categories"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleCategories($p)
    {
        $cats = array();

        $categories = get_the_category($p->ID);

        foreach ($categories as $category) {
            $cats[] = array(
                'name' => $category->cat_name,
                'nicename' => $category->category_nicename
            );
        }

        $this->post_categories = $cats;
        return $cats;
    }


    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_tags"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleTags($p)
    {
        $t = array();

        $tags = get_the_tags($p->ID);

        if ($tags) foreach ($tags as $tag) {
            $t[] = array(
                'name' => $tag->name,
                'nicename' => $tag->slug
            );
        }

        $this->post_tags = $t;
        return $t;
    }


    //-----------------------------------------------------------------------------------------------------
    //  get part of the value for "post_selfCodes"...
    //-----------------------------------------------------------------------------------------------------

    protected function getKeywordSelfCodes ( $p )
    {
        if ( ! is_array($this->post_tags) ) {
            // getArticleTags() hasn't been run yet
            $this->post_tags = $this->getArticleTags($p);
        }
        if ( ! is_array($this->post_categories) ) {
            // getArticleCategories() hasn't been run yet
            $this->post_categories = $this->getArticleCategories($p);
        }

        $keywordSelfCodes = array();

        $tagsAndCats = array_unique(array_merge($this->post_tags, $this->post_categories), SORT_REGULAR);

        foreach( $tagsAndCats as $tag ) {
            $t = array();
            $t['value'] = $t['seoname'] = $tag['name'];
            $t['type'] = "KEYWORD";
            $t['symbol'] = str_replace('-', '_', strtoupper($tag['nicename']));
            $t['vrtysux'] = "KEYWORD|" . $t['symbol'];
            $keywordSelfCodes[] = $t;
        }

        return $keywordSelfCodes;
    }


    //-----------------------------------------------------------------------------------------------------
    //  get value for "post_images"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleImages($p)
    {
        $images = array();

        $article_images = get_post_meta($p->ID, 'article_images', true);

        if ($article_images != '') {

            foreach ((array)$article_images as $size => $url) {
                if ($url == '') continue;
                $images[$size] = $url;
            }
        }

        return $images;
    }


    //-----------------------------------------------------------------------------------------------------
    //  return a string of the author name(s) for "post_author"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleAuthor( $p )
    {
        $article_suppress_author = stripslashes( get_post_meta( $p->ID, 'article_suppress_author', true ) );

        if( ! get_option( 'suppress_author' ) && $article_suppress_author != "1" )

            return (get_the_author());

        return ('');
     }
    
    //-----------------------------------------------------------------------------------------------------
    //  return an array of author(s) each with name, topic id, and bio URL for "post_authors"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleAuthors( $p )
    {
        $authors = array();

        $article_suppress_author = stripslashes( get_post_meta( $p->ID, 'article_suppress_author', true ) );

        if( ! get_option( 'suppress_author' ) && $article_suppress_author != "1" )
        {
            // first we get what WordPress thinks the post author is, or custom
            $custom_author = get_post_meta( $p->ID, 'custom_author', true );
            $authors[] = $this->getAuthorInfo($custom_author ? $custom_author : $p->post_author);

            // now any extra authors from post metadata
            $extra_authors = get_post_meta($p->ID, 'extra_authors', true);
            if ($extra_authors) foreach ($extra_authors as $author) {
                $authors[] = $this->getAuthorInfo($author);
            }
        }

        return $authors;
    }

    //-----------------------------------------------------------------------------------------------------
    //  accepts author id or custom author string, returns object with name, topic id, and bio URL
    //-----------------------------------------------------------------------------------------------------

    protected function getAuthorInfo( $author )
    {
        if (is_numeric($author)) { // id for user in our system

            $author_id = intval($author);

            $topic_ID = esc_attr(get_the_author_meta( 'topicID', $author_id ));

            if (!$topic_ID) {
              $topic_ID = null;
              $bio_URL = null;
            }
            else {
              $bio_URL = "http://online.wsj.com/news/author/$topic_ID";
            }

            return array(
                "authorName" => get_user_meta( $author_id, 'display_name', true ),
                "authorTopicId" => $topic_ID,
                "authorBiography" => $bio_URL
            );
        }
        else { // custom author
            return array(
                "authorName" =>$author
            );
        }
    }

    //-----------------------------------------------------------------------------------------------------
    //  return an array of video-id's for "post_videos"...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleVideos($p)
    {
        $videos = array();

        $optionName = (isDeloitte()) ? "wsj_article_video" : "article_videos";

        $article_videos = stripslashes(get_post_meta($p->ID, $optionName, true));

        if ($article_videos != '') {
            $article_videos = str_replace(array('{', '}'), '', $article_videos);
            $article_videos = explode(',', $article_videos);

            foreach ($article_videos as $video) {
                $videos[] = $video;
            }
        }

        return ($videos);
    }

    //-----------------------------------------------------------------------------------------------------
    //  return the value for "post_slideshow"...can only have one...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSlideshow( $p ) {

        $article_slideshow = stripslashes( get_post_meta( $p->ID, 'article_slideshow', true ) );

        if( $article_slideshow != '' ){

            $n = strrpos($article_slideshow, ":");                          // see if a Placement option is at the end of the string

            if ( $n !== false )                                             //
                $article_slideshow = substr($article_slideshow, 0, $n);     // get rid of placement
        }

        return($article_slideshow);
    }

    //-----------------------------------------------------------------------------------------------------
    //  return the value for "post_interactive"...can only have one...
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleInteractive( $p ) {

        $article_interactive = stripslashes( get_post_meta( $p->ID, 'article_interactive', true ) );

        if( $article_interactive != '' ) {

            $n = strrpos($article_interactive, ":");                            // see if a Placement option is at the end of the string

            if ( $n !== false )                                                 //
                $article_interactive = substr($article_interactive, 0, $n);     // get rid of placement
        }

        return($article_interactive);
    }


    //-----------------------------------------------------------------------------------------------------
    //  return array of codes for "post_selfCodes"
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleSelfCodes ( $p )
    {
        $selfCodes = array();

        $selfCodes = array_merge($selfCodes, $this->getKeywordSelfCodes($p));
        $selfCodes = array_merge($selfCodes, $this->getArticleTickerSelfCodes($p));
        $selfCodes = array_merge($selfCodes, $this->getPrivilegeSelfCode($p));

        return $selfCodes;
    }

    //-----------------------------------------------------------------------------------------------------
    //  return array of codes that will be part of "post_selfCodes"
    //-----------------------------------------------------------------------------------------------------

    protected function getArticleTickerSelfCodes ( $p )
    {
        global $wpdb;

        $tickerSelfCodes = array(); // return this
        $tickers = array(); // array of ticker symbols

        // first we'll get tickers created by post admin meta box "Ticker Symbols"
        $tickerquery = "SELECT ticker_symbol 
                        FROM wp_{$this->blog_id}_tickers 
                        WHERE post_id = {$p->ID} 
                            AND ticker_type='COMPANY' 
                        ORDER BY id ASC";
        $results = $wpdb->get_col( $tickerquery );
        if( $results ) $tickers = array_merge($tickers, $results);

        // not sure what uses post_meta wsj_ticker, but this was in DJML creation
        $from_meta = get_post_meta($p->ID, 'wsj_ticker');
        if( $from_meta && isset($from_meta['symbol']) ) $tickers[] = $from_meta['symbol'];

        // also, the ticker shortcode which is in the post body, get that too
        preg_match_all('/\[wsj-ticker[^\]]*ticker="(.*?)"/', $p->post_content, $matches); // copied from DJML plugin
        $tickers = array_merge($tickers, $matches[1]); // 2nd element of matches is array of captured backreferences

        $tickers = array_unique( array_map("strtoupper", $tickers) );

        if( $tickers ) foreach( $tickers as $ticker )
        {
            $tick = array();
            $tick['symbol'] = $ticker;
            $tick['seoname'] = $ticker;
            $tick['value'] = $ticker;
            $tick['type'] = ( strlen( $ticker ) == 5 && preg_match( "/.*[^X]X$/", strtoupper( $ticker ) ) > 0 ) ? 'MUTUAL-FUND' : 'COMPANY';
            $tick['vrtysux'] = $tick['type'] . '|' . $ticker;
            $tickerSelfCodes[] = $tick;
        }

        return $tickerSelfCodes;
    }

    //-----------------------------------------------------------------------------------------------------
    //  return array of codes that will be part of "post_selfCodes"
    //-----------------------------------------------------------------------------------------------------

    protected function getPrivilegeSelfCode ( $p )
    {
        $retval = array();

        if ($this->privilege == 0) {
            $code = array();
            $code['symbol'] = "FREE";
            $code['type'] = "STATISTIC";
            $code['vrtysux'] = "STATISTIC|FREE";
            $retval[] = $code;
        }
        return $retval;
    }

    //-----------------------------------------------------------------------------------------------------
    //  handler for [wsj-quote] shortcode...
    //-----------------------------------------------------------------------------------------------------

    public function wsjQuote( $atts )
    {
        extract( shortcode_atts( array(
            'ticker'            => '',
            'name'              => '',
            'realtimechannel'   => '',
            'channel'           => '',
            'id'                => ''
        ), $atts ));

        return '<span data-widget="dj.ticker" data-ticker-name="' . $ticker . '"></span>';
    }

    //-----------------------------------------------------------------------------------------------------
    //  return array of strings that will be part of "post_home_page_summary"
    //
    //  the strings come from the "Homepage Summary" meta box...
    //-----------------------------------------------------------------------------------------------------

    public function getHomePageSummary( $p )
    {
        $article_homepage_summary_headline = stripslashes( get_post_meta( $p->ID, 'article_homepage_summary_headline', true ) );
        $article_homepage_summary = stripslashes( get_post_meta( $p->ID, 'article_homepage_summary', true ) );
        $article_homepage_summary_editions = get_post_meta( $p->ID, 'article_homepage_summary_editions', true );

        return array( 'headline' => $article_homepage_summary_headline, 'summary' => $article_homepage_summary, 'edition' => $article_homepage_summary_editions );
    }

    //-----------------------------------------------------------------------------------------------------
    //  gather all the info about the post...
    //-----------------------------------------------------------------------------------------------------

    public function processPost( $p, $blog_id = null )
    {
        $post[ 'post_id' ]          = $_GET[ 'guid' ];
        $post[ 'post_type' ]        = $this->getArticleType( $p );

        $blogname = stripslashes(get_post_meta($p->ID, 'f_originating_blog', true)); // five things override for blog name
	 $blog_details = get_blog_details( $blog_id );

	 $post_url = get_permalink( $p->ID );
        
	 if( strpos( $post_url, '/blog/' ) )
	 	error_log( " Wrong URL(having /blog) " . $blog_details->blogname . " & " . $blog_details->path  . " - " . $post[ 'post_id' ] . " - " . $post_url . "\n", 3, '/var/log/nginx/json-v2.log' ); // Adding logs to server to catch issue WSJCOM-25881

        if ($blogname){
		$post[ 'post_blog_name' ] = $blogname;
	 } else {
		if( strpos($post_url, '/blog/') !== false )			
			$post[ 'post_blog_name' ] = $blog_details->blogname;
		else
			$post[ 'post_blog_name' ] = get_bloginfo( 'name' );
	 }

	 if( strpos($post_url, '/blog/') !== false ){			
		$post[ 'post_blog_url' ]    = $blog_details->domain . $blog_details->path;
		$post[ 'post_url' ]         = str_replace( '/blog/', $blog_details->path, $post_url );
	 }else{
        	$post[ 'post_blog_url' ]    = get_bloginfo( 'home' );
		$post[ 'post_url' ]         = $post_url;
        }
    
        $post[ 'article_type' ]     = $post[ 'post_blog_name' ].'blog' ; 
        $post[ 'post_title' ]       = $this->getArticleTitle( $p );
        $post[ 'post_content' ]     = $this->getArticleContent( $p );
        $post[ 'post_date_gmt' ]    = $this->getArticleDateGMT( $p );
        $post[ 'post_updated_est' ] = $this->getArticleUpdatedDateEST( $p );
        $post[ 'post_summary' ]     = $this->getArticleSummary( $p );
        $post[ 'post_home_page_summary' ] = $this->getHomePageSummary( $p );
        
        $post[ 'post_source' ]      = $this->getArticleSource( $p );
        $post[ 'post_privilege' ]   = $this->getArticlePrivilege( $p );
        $post[ 'post_section' ]     = $this->getArticleSection( $p );
        $post[ 'post_subsection' ]  = $this->getArticleSubSection( $p );

        $post[ 'post_categories' ]  = $this->getArticleCategories( $p );
        $post[ 'post_tags' ]        = $this->getArticleTags( $p );
        $post[ 'post_images' ]      = $this->getArticleImages( $p );
        $post[ 'post_videos' ]      = $this->getArticleVideos( $p );
       
        $post[ 'post_authors' ]     = $this->getArticleAuthors( $p );
        $post[ 'post_author' ]      = $this->getArticleAuthor( $p );
      

        $post[ 'post_slideshow' ]   = $this->getArticleSlideshow( $p );
        $post[ 'post_interactive' ] = $this->getArticleInteractive( $p );
        $post[ 'post_selfCodes' ]   = $this->getArticleSelfCodes( $p );

        if( $_GET['include-all-in-post-content'] || $_GET['include-live-blog-entries'] )
        {
            if ( $post[ 'post_type' ] == 'liveblog' ) {
                $post[ 'post_content' ] .= $this->getLiveBlogAsContent( $p );
            }
            if ( $post[ 'post_slideshow' ] ) {
                $post[ 'post_content' ] .= $this->getSlideshowAsContent( $post[ 'post_slideshow' ] );
            }
            if ( $post[ 'post_interactive' ] ) {
                $post[ 'post_content' ] .= "<a href='" . $post['post_url'] . "?dsk=y'><hr><p style='font-weight: bold;'>This article's interactive graphics are not currently available in a mobile format. Tap here to see them in the desktop version of this article.</p><hr></a>";
            }
        }

        return $post;
    }

    //-----------------------------------------------------------------------------------------------------
    //  gather all the info about the live blog...
    //-----------------------------------------------------------------------------------------------------

    public function processLiveBlog( $p, $blog_id )
    {
        $post = $this->processPost( $p, $blog_id );

        $post[ 'post_live_entries' ] = array();

        $entries = stripslashes_deep( get_post_meta( $p->ID, 'article_live_blog' ) );

        if( empty( $entries ) )
            return $post;

        $post[ 'post_live_entries' ] = $entries[ 0 ];
        
        return $post;
    }

    //-----------------------------------------------------------------------------------------------------
    //  gather all the info about the live blog...
    //-----------------------------------------------------------------------------------------------------

  public function processSlideShow( $p )
    {
        $post[ 'post_id' ]          = $_GET[ 'guid' ];
        $post[ 'post_type' ]        = $this->getArticleType( $p );
        $post[ 'post_blog_name' ]   = get_bloginfo( 'name' );
        $post[ 'post_blog_url' ]    = get_bloginfo( 'home' );
        $post[ 'post_title' ]       = $this->getArticleTitle( $p );
        $post[ 'post_content' ]     = $this->getArticleContent( $p );
        $post[ 'post_date_gmt' ]    = $this->getArticleDateGMT( $p );
        $post[ 'post_updated_est' ] = $this->getArticleUpdatedDateEST( $p );
        $post[ 'post_url' ]         = get_permalink( $p->ID );
        $post[ 'post_source' ]      = $this->getArticleSource( $p );
       
        $post[ 'post_authors' ]     = $this->getArticleAuthors( $p );
        $post[ 'post_author' ]      = $this->getArticleAuthor( $p );
     

        $meta = get_post_custom( $p->ID );

        $slides = array();

        foreach( $meta as $key => $value )
        {
            if( strstr( $key, 'slideshow_slide' ) === false )
                continue;

            $k = explode( '_', $key );
            $slide =  stripslashes_deep( unserialize( $value[ 0 ] ) );

            $slides[ $k[ 2 ] ][ 'photo_caption' ] = wp_kses( $slide[ 'description' ], array() );
            $slides[ $k[ 2 ] ][ 'photo_credit' ]  = $slide[ 'cite' ];
            $slides[ $k[ 2 ] ][ 'photo_url' ]     = $slide[ 'image' ];
        }
     
        ksort( $slides );

        $post[ 'post_photos' ] = $slides;

        return $post;
    }

    //-----------------------------------------------------------------------------------------------------
    //  the main handler...
    //-----------------------------------------------------------------------------------------------------

    public function json( $args )
    {
        global $wpdb;

        if( ! isset( $args[ 'guid' ] ) )
        {
            echo '{}';
            return;
        }

        $bl_id = explode( '-', $args[ 'guid' ] );

        if( is_numeric( trim($bl_id[ 1 ], "B") ) ) // trim B because even when using blog id for base_doc_abbrev we still add B, e.g. BL-253B-28
        {
		$this->blog_id = (int) trim($bl_id[ 1 ], "B");
		$error_msg = "IF....GUID = " . $args[ 'guid' ] . "... trim(bl_id[ 1 ], 'B') = " .  trim($bl_id[ 1 ], "B") . "... this->blog_id = " . $this->blog_id . "\n";
        }
        else
        {
            $bl_id[ 1 ] =  substr( $bl_id[ 1 ], 0, -1 );

            $this->blog_id = $wpdb->get_var( $wpdb->prepare( 
                'SELECT blog_id 
                 FROM wp_global_options 
                 WHERE option_name = %s 
                    AND option_value = %s', 'base_doc_abbrev', $bl_id[ 1 ] ) );
		$error_msg = "ELSE....GUID = " . $args[ 'guid' ] . "...bl_id[ 1 ] = " .  $bl_id[ 1 ] . "... this->blog_id = " . $this->blog_id . "\n";

        }

        if( $this->blog_id < 1 )
        {
            echo '{}';
            return;
        }

        switch_to_blog( $this->blog_id );

        unset( $args[ 'guid' ] );
        $args[ 'p' ] = $bl_id[ 2 ];
        $args[ 'numberposts' ] = 1;

        $defaults = array(
            'offset'          => 0,
            'category'        => '',
            'orderby'         => 'post_date',
            'order'           => 'DESC',
            'include'         => '',
            'exclude'         => '',
            'meta_key'        => '',
            'meta_value'      => '',
            'post_type'       => array( 'post', 'wsj_slideshow' ),
            'post_mime_type'  => '',
            'post_parent'     => '',
            'post_status'     => 'publish',
            'numberposts'     => 10 );

        $args = wp_parse_args( $args, $defaults );

        if( $args[ 'numberposts' ] > 20 )
            $args[ 'numberposts' ] = 20;

        if( $args[ 'offset' ] > 40 )
            $args[ 'offset' ] = 40;

        $articles = get_posts( $args );

        if (!$articles && $this->from_t1contentsvc) {
            // let t1contentsvc see drafts so we can do mobile previews, see PSSBSCEN-2312 for more info
            $args['post_status'] = 'any';
            $args['suppress_filters'] = false; // default is true, need show_drafts filter

            // we need to temporarily change the post's status to "publish", otherwise non-logged-in users (like t1contentsvc) can't see it
            add_filter('posts_results', 'show_drafts');
            function show_drafts($posts) {
                remove_filter('posts_results', 'show_drafts');
                if (!empty($posts)) $posts[0]->post_status = 'publish';
                return $posts;
            }

            $articles = get_posts( $args ); // try again
        }

        foreach( $articles as $p )
        {
            setup_postdata( $p );

            $type = $this->getArticleType( $p );

		    error_log( date("F j, Y, g:i a") . "  Post URL = " . $p->guid .  "  " . $error_msg , 3, '/var/log/nginx/json-v2.log' ); // Adding logs to server to catch issue WSJCOM-25881

            if( $type == 'slideshow' && $p->post_type != 'post' ) // only processSlideShow() if actually a blogs slideshow
                $post = $this->processSlideShow( $p );
            else if( $type == 'liveblog' )
                $post = $this->processLiveBlog( $p, $this->blog_id );
            else
                $post = $this->processPost( $p, $this->blog_id );
            
            echo json_encode( $post );

            return;
        }

        echo '{"error":"no article found for '. $_GET["guid"] .'"}';
    }
 }


//-----------------------------------------------------------------------------------------------------
//  the main entry point...
//-----------------------------------------------------------------------------------------------------

 global $wsj_context;
 $wsj_context = "wsj-json-v2";
 
 $wsj_json_feed = new WSJJSONFeed();
 $wsj_json_feed->json( $_GET );
