<?php

namespace ntz;

class MagicFeaturedImage {
    protected $oembed_providers;

    function __construct()
    {
        add_filter( 'oembed_providers', array( $this, 'oembed_providers' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
    }


    public function oembed_providers( $oembed_providers ){
      $this->oembed_providers = $oembed_providers;

      return $oembed_providers;
    }


    public function save_post( $post_id, $post )
    {
        if ( wp_is_post_revision( $post_id ) || has_post_thumbnail( $post_id ) ){
            return;
        }

        $uploaded_image = null;

        if ( $parent_id = wp_is_post_revision( $post_id ) ){
            $post_id = $parent_id;
        }

        $content = $post->post_content;
        $image = $this->get_first_image( $content );

        if( !$image ) {
            $image = $this->get_image_from_video( $content );
        }

        if( $image ){
            $uploaded_image = $this->upload_image_from_remote( $image );
        }

        if( $uploaded_image ){
            $upload_id = $this->upload_image_to_wp( $uploaded_image, $post_id );
            set_post_thumbnail( $post_id, $upload_id );
        }
    }



    protected function get_first_image( $content )
    {
        $regex = "/<img\s[^>]*?src\s*=\s*(['\"]([^'\"]*?)['\"])[^>]*?>/";
        preg_match_all( $regex, $content, $images );
        if( isset( $images[2][0] ) ){
            return  $images[2][0];
        }
        return null;
    }


    protected function get_image_from_video( $content )
    {
        $video = null;
        $oembed_provider = null;

        // calling the_content filter to populate oembed_providers array
        $filtered_content = apply_filters( 'the_content', $content );

        if( $this->oembed_providers ){
            foreach( $this->oembed_providers as $oembed_pattern => $oembed_url ){
                if( $video ) { continue; }
                preg_match_all( $oembed_pattern, $content, $matched_urls );
                if( isset( $matched_urls[0][0] ) ){
                    $video = $matched_urls[0][0];
                    $oembed_provider = $oembed_url[0];
                }
            }
        }

        if( $video ){
            $oembed_url = add_query_arg( 'url', $video, $oembed_provider );
            $parsed_oembed = wp_remote_get( $oembed_url );
            if( isset( $parsed_oembed['body'] ) ){
                $parsed_oembed = json_decode( $parsed_oembed['body'] );
                if( isset( $parsed_oembed->thumbnail_url ) ){
                    return $parsed_oembed->thumbnail_url;
                }
            }
        }
    }



    protected function upload_image_to_wp( $image, $post_id )
    {
        $wp_filetype = wp_check_filetype( basename( $image ), null );

        $uploadfile = $this->get_uploaded_file_path( $image );

        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => $image,
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment( $attachment, $uploadfile, $post_id );

        $imagenew = get_post( $attach_id );
        $fullsizepath = get_attached_file( $imagenew->ID );
        $attach_data = wp_generate_attachment_metadata( $attach_id, $fullsizepath );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        return $attach_id;
    }


    protected function upload_image_from_remote( $image )
    {
        $filename = $this->get_hash( $image ) . '-' . basename ( $image );
        $uploadfile = $this->get_uploaded_file_path( $filename );

        $allowed_extensions = array(
            "image/jpeg" => "jpg",
            "image/gif"  => "gif",
            "image/png"  => "png",
        );
        $remote = wp_remote_get( $image );

        $savefile = fopen( $uploadfile, 'w' );
        $status = fwrite( $savefile, $remote['body'] );
        fclose( $savefile );

        if( $status ){
            return $filename;
        }

        return null;
    }


    protected function get_uploaded_file_path( $filename )
    {
        $uploaddir = wp_upload_dir();
        return $uploaddir['path'] . '/' . $filename;
    }


    protected function get_hash( $text )
    {
        return substr( sha1( $text ), 0, 15 );
    }

}