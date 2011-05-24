<?php
/*
 * Plugin Name: Auto Featured Image
 * Description: Automatically generate featured image for new/old posts if Post Thumbnail is not set manually. In addition to post attachment, it also support external image, Youtube, Vimeo, DailyMotion. Originally designed by Aditya Mooley <adityamooley@sanisoft.com>.
 * Version: 1.3
 * Author: Ka Yue Yeung <kayuey@gmail.com>
 * Author URI: http://ka-yue.com
 */

class Auto_Feautred_Image_Plugin {
    
    public function __construct()
    {
        // run plugin every time people save post
        add_action( 'save_post', array(__CLASS__, "generate_thumbmail") );
        
        // plugin should work for scheduled posts as well
        add_action( 'transition_post_status', array(__CLASS__, "check_transition") ); 
        
        // 
        add_action( 'admin_notices', array(__CLASS__, "check_permission") );
        
        add_action( 'admin_menu', array(__CLASS__, "add_admin_menu") ); // Add batch process capability
        add_action( 'wp_ajax_generatepostthumbnail', array(__CLASS__, "ajax_process_post") ); // Hook to implement AJAX request
    }
        
    /**
     * Function to check whether scheduled post is being published. If so, afi_publish_post should be called.
     * 
     * @param $new_status
     * @param $old_status
     * @param $post
     * @return void
     */
    static function check_transition( $new_status='', $old_status='', $post='' ) 
    {
        if ('publish' == $new_status && 'future' == $old_status) {
            self::generate_thumbmail($post->ID);
        }
    }
    
    /**
     * Function to save first image in post as post thumbmail.
     */
    static function generate_thumbmail( $post_id )
    {
        global $wpdb;
        
        $post = get_post($dummy_wp = $post_id);
        
        // reset post parent id
        $post_parent_id = $post->post_parent === 0 ? $post->ID : $post->post_parent;
        
        // check whether Post Thumbnail is already set for this post.
        if ( has_post_thumbnail($post_parent_id) ) return "has thumbnail";
        
        // case 1: there is an image attachment we can use
        // found all images attachments from the post
        $attachments = array_values(get_children(array(
            'post_parent' => $post_parent_id, 
            'post_status' => 'inherit', 
            'post_type' => 'attachment', 
            'post_mime_type' => 'image', 
            'order' => 'ASC', 
            'orderby' => 'menu_order ID') 
        ));
        
        // if attachment found, set the first attachment as thumbnail
        if( sizeof($attachments) > 0 ) {
            update_post_meta( $post_parent_id, '_thumbnail_id', $attachments[0]->ID );
            return;
        }
        
        // case 2: need to search for an image from content
        // find image from content
        // check is there any image we can use
        $image_url = self::extractThumbnail($post->post_content);
        
        // if no url found, do nothing
        if( $image_url === null ) return;
        
        // try to create an image attchment from given image url, and use it as thumbnail
        $post_thumbnail_id = self::create_post_attachment_from_url($image_url);
        
        // update post thumbnail meta if thumbnail found
        if(is_int($post_thumbnail_id)) {
            update_post_meta( $post_parent_id, '_thumbnail_id', $post_thumbnail_id );
        }
        
        return;
    }
    
    /*
     * Extract thumbnail from content. 
     * @return String Thumbnail url
     */
    function extractThumbnail($content) {
        $matches = array();

        // image tag
        preg_match( '/<img [^>]*src=["\']?(?<src>(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\w-_=&\/?\.]*)*\/?)["\'][^>]*>/im', $content, $matches ); 
        if(isset($matches['src'])) return $matches['src'];

        // embedded youtube
        $pattern = "/http:\/\/(www.)?youtube.com\/embed\/(?<id>[\w-_]+)/i";
        preg_match( $pattern, $content, $matches ); 
        if( isset($matches['id']) ) {
            $content = "http://www.youtube.com/watch?v={$matches['id']}";
        }
        
        $pattern = "/http:\/\/player.vimeo.com\/video\/(?<id>[\w-_]+)/i";
        preg_match( $pattern, $content, $matches ); 
        if( isset($matches['id']) ) {
            $content = "http://vimeo.com/{$matches['id']}";
        }

        // get thumbnail from oebmed protocal
        $providers = array();
        // Embed.ly API
        $providers['/(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\w-_=&\/?\.]*)*\/?/i'] = 'http://api.embed.ly/1/oembed?format=json&maxwidth=500';
        // $providers['/http:\/\/(www\.)?(youtube.com\/watch.|youtu.be\/.)[\w-_=&\/]+/i'] = 'http://www.youtube.com/oembed?format=json';
        // $providers['/http:\/\/(www\.)?(vimeo.com\/)[\w-_=&\/]+/i'] = 'http://vimeo.com/api/oembed.json?';

        foreach($providers as $scheme => $endpoint) {
            preg_match( $scheme, $content, $matches ); 
            var_dump($matches);
            // if url found...
            if(isset($matches[0])) :
                $url = urlencode($matches[0]);
                $query = "{$endpoint}&url={$url}";
                var_dump($query);
                $ch = curl_init($query);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                
                if($data = curl_exec($ch)){
                    // curl success, get http code
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if( $http_code >= 200 && $http_code < 300 ){
                        // success
                        $result = json_decode(trim($data));
                        var_dump($result);
                        if(isset($result->thumbnail_url)) return $result->thumbnail_url;
                    } 

                    // http error: $http_code
                } else {
                    // curl error: curl_errno($ch);
                };
                
                curl_close($ch);
            endif;
        }

        // return null if nothing found
        return null;
    }
    
    /**
     * Function to fetch the image from URL and generate the required thumbnails
     * @return Attachment ID
     */
    static function create_post_attachment_from_url($imageUrl = null)
    {
        if($imageUrl === null) return null;
        
        // get file name
        $filename = substr($imageUrl, (strrpos($imageUrl, '/'))+1);

        if (!(($uploads = wp_upload_dir(current_time('mysql')) ) && false === $uploads['error'])) {
            return null;
        }
    
        // Generate unique file name
        $filename = wp_unique_filename( $uploads['path'], $filename );
        $filename .= ".jpg";
    
        // move the file to the uploads dir
        $new_file = $uploads['path'] . "/$filename";

        // download file
        if (!ini_get('allow_url_fopen')) {
            $file_data = self::curl_get_file_contents($imageUrl);
        } else {
            $file_data = @file_get_contents($imageUrl);
        }

        // fail to download image.
        if (!$file_data) {
            return null;
        }
        
        file_put_contents($new_file, $file_data);

        // Set correct file permissions
        $stat = stat( dirname( $new_file ));
        $perms = $stat['mode'] & 0000666;
        @chmod( $new_file, $perms );
        
        // get the file type. Must to use it as a post thumbnail.
        $wp_filetype = wp_check_filetype( $filename, $mimes );
        
        extract( $wp_filetype );

        // no file type! No point to proceed further
        if ( ( !$type || !$ext ) && !current_user_can( 'unfiltered_upload' ) ) {
            return null;
        }
        
        // construct the attachment array
        $attachment = array(
            'post_mime_type' => $type,
            'guid' => $uploads['url'] . "/$filename",
            'post_parent' => null,
            'post_title' => '',
            'post_content' => '',
        );
    
        // insert attachment
        $thumb_id = wp_insert_attachment($attachment, $file, $post_id);
        
        // error!
        if ( is_wp_error($thumb_id) ) {
            return null;
        }
        
        require_once(ABSPATH . '/wp-admin/includes/image.php');
        wp_update_attachment_metadata( $thumb_id, wp_generate_attachment_metadata( $thumb_id, $new_file ) );
        
        return $thumb_id;
    }
    
    /**
     * Function to fetch the contents of URL using curl in absense of allow_url_fopen.
     * 
     * Copied from user comment on php.net (http://in.php.net/manual/en/function.file-get-contents.php#82255)
     */
    static function curl_get_file_contents($URL) {
        $c = curl_init();
        curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($c, CURLOPT_URL, $URL);
        $contents = curl_exec($c);
        curl_close($c);
    
        if ($contents) {
            return $contents;
        }
        
        return FALSE;
    }
    
    
    /**
     * Check whether the required directory structure is available so that the plugin can create thumbnails if needed.
     * If not, don't allow plugin activation.
     */
    static function check_permission() {
        $uploads = wp_upload_dir(current_time('mysql'));
    
        if ($uploads['error']) {
            echo '<div class="updated"><p>';
            echo $uploads['error'];
            
            if ( function_exists('deactivate_plugins') ) {
                deactivate_plugins('auto-featured-image/auto-featured-image.php', 'auto-featured-image.php' );
                echo '<br /> This plugin has been automatically deactivated.';
            }
    
            echo '</p></div>';
        }
    }
    
    static function add_admin_menu() // Register the management page
    {
        add_options_page('Auto Featured Image', 'Auto Featured Image', 'manage_options', 'generate-post-thumbnails', array( "Auto_Feautred_Image_Plugin", "construct_admin_interface"));
    }
    
    
    /**
     * Admin user interface plus post thumbnail generator
     * 
     * Most of the code in this function is copied from - 
     * Regenerate Thumbnails plugin (http://www.viper007bond.com/wordpress-plugins/regenerate-thumbnails/)
     * 
     * @return void
     */
    static function construct_admin_interface() {
        global $wpdb;
        ?>
        
        <div class="wrap genpostthumbs">
            <h2>Auto Featured Image</h2>
            
        <?php 
        // If the button was clicked
        if ( !empty($_POST['generate-post-thumbnails']) ) :
            
            // Capability check
            if ( !current_user_can('manage_options') ) wp_die('Cheatin&#8217; uh?');
            
            // Form nonce check
            check_admin_referer( 'generate-post-thumbnails' );
            
            // Get id's of all the published posts for which post thumbnails does not exist.
            $query = "SELECT ID FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND post_type = 'post' AND p.ID NOT IN (
                        SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id', 'skip_post_thumb')
                      ) ORDER BY ID DESC";
            
            $posts = $wpdb->get_results($query);
            
            if ( empty($posts) ) {
                echo '<p>Currently there are no published posts available to generate thumbnails.</p>';
            } else {
                
                echo '<p>We are generating post thumbnails. Please be patient!</p>';
                
                // Generate the list of IDs
                $ids = array();
                foreach ( $posts as $post )
                    $ids[] = $post->ID;
                $ids = implode( ',', $ids );
    
                $count = count( $posts );
            ?>
            
            <div id="message">Processing...</div>
            
            <noscript><p><em>You must enable Javascript in order to proceed!</em></p></noscript>
            
            <script type="text/javascript">
            // <![CDATA[
            jQuery(document).ready(function($){
                var i;
                var postIds = [<?php echo $ids; ?>];
                var totalPosts = postIds.length;
                var processedPostCount = 1;
                var $message = $("#message");
                
                function genPostThumb( id ) {
                    $.post( "admin-ajax.php", { action: "generatepostthumbnail", id: id }, function() {
                        var percentDone = ( processedPostCount / totalPosts ) * 100;
                        
                        $message.text( "Processing post #" + id + ". " + Math.round(percentDone) + "% done." );
                        
                        processedPostCount++;
                        
                        if ( postIds.length ) {
                            genPostThumb( postIds.shift() );
                        } else {
                            $message
                                .addClass("updated")
                                .html("<p><strong>All done! Processed "+totalPosts+" posts.</strong></p>");
                        }
                        
                    });
                }
                
                // start ajax
                genPostThumb( postIds.shift() );
            });
            // ]]>
            </script>
            <?php
            }
        else : // button is not clicked
        ?>
            <p>Use this tool to auto generate featured image for your published posts.</p>
            <p>If the script stops executing for any reason, just <strong>reload</strong> the page and it will continue from where it stopped.</p>
            
            <form method="post" action="">
                <?php wp_nonce_field('generate-post-thumbnails') ?>
                <p><input type="submit" class="button hide-if-no-js" name="generate-post-thumbnails" id="generate-post-thumbnails" value="Generate Thumbnails" /></p>
                <noscript><p><em>You must enable Javascript in order to proceed!</em></p></noscript>
            </form>
            
        <? endif; ?>
        </div>
    <?php
    } //End afi_interface()
    
    /**
     * Process single post to generate the post thumbnail
     * 
     * @return void
     */
    static function ajax_process_post() {
        if ( !current_user_can( 'manage_options' ) ) {
            die('-1');
        }    
    
        $id = (int) $_POST['id'];
        
        if ( empty($id) ) {
            die('-1');
        }
        
        set_time_limit( 60 );
        
        // Pass on the id to our 'publish' callback function.
        echo self::generate_thumbmail($id);
        
        die(-1);
    } //End ajax_process_post()
}

new Auto_Feautred_Image_Plugin();