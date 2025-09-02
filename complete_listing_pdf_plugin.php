<?php
/**
 * Plugin Name: Listing PDF Generator
 * Description: Generates PDF sheets for directory listings
 * Version: 1.0
 * Author: Eat Local First
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the PDF generator class
require_once plugin_dir_path(__FILE__) . 'includes/simple_universal_pdf.php';

class ListingPDFPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function init() {
        // Add download endpoint
        add_action('wp_ajax_download_listing_pdf', array($this, 'handle_pdf_download'));
        add_action('wp_ajax_nopriv_download_listing_pdf', array($this, 'handle_pdf_download'));
        
        // Add download button to listings
        add_filter('listing_footer_actions', array($this, 'add_pdf_download_button'));
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    }
    
    public function add_pdf_download_button($content) {
        if (is_singular('atlas_listing') && is_user_logged_in()) {
            $post_id = get_the_ID();
            $current_user_id = get_current_user_id();
            $post_author_id = get_post_field('post_author', $post_id);
            
            // Show button if user is the listing owner OR if user is administrator
            if ($current_user_id == $post_author_id || current_user_can('manage_options')) {
                $nonce = wp_create_nonce('pdf_download_' . $post_id);
                
                $button = sprintf(
                    '<div class="pdf-download-section" style="margin: 20px 0;">
                        <a href="#" class="button pdf-download-btn" data-post-id="%d" data-nonce="%s"
                           style="background: #6AA338; border-color: #6AA338; color: white; padding: 12px 24px;
                                  text-decoration: none; display: inline-block;
                                  ">
                            📄 Download PDF Listing
                        </a>
                    </div>
                    <script>
                    jQuery(document).ready(function($) {
                        $(".pdf-download-btn").click(function(e) {
                            e.preventDefault();
                            var postId = $(this).data("post-id");
                            var nonce = $(this).data("nonce");
                            
                            window.location.href = ajax_object.ajax_url + "?action=download_listing_pdf&post_id=" + postId + "&nonce=" + nonce;
                        });
                    });
                    </script>',
                    $post_id,
                    $nonce
                );
                
                $content .= $button;
            }
        }
        
        return $content;
    }
    
    public function handle_pdf_download() {
        if (!isset($_GET['post_id']) || !isset($_GET['nonce'])) {
            wp_die('Invalid request');
        }
        
        $post_id = intval($_GET['post_id']);
        $nonce = sanitize_text_field($_GET['nonce']);
        $current_user_id = get_current_user_id();
        
        if (!wp_verify_nonce($nonce, 'pdf_download_' . $post_id)) {
            wp_die('Security check failed');
        }
        
        // Verify user permission - must be listing owner or administrator
        $post_author_id = get_post_field('post_author', $post_id);
        if (!$current_user_id || ($current_user_id != $post_author_id && !current_user_can('manage_options'))) {
            wp_die('You do not have permission to download this PDF');
        }
        
        $generator = new SimpleListingPDFGenerator();
        $pdf_content = $generator->create_listing_pdf($post_id);
        
        if ($pdf_content === false) {
            wp_die('PDF generation failed');
        }
        
        $post_title = get_the_title($post_id);
        $filename = sanitize_file_name($post_title . '-listing.pdf');
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        echo $pdf_content;
        exit;
    }
}

// Initialize the plugin
new ListingPDFPlugin();
?>