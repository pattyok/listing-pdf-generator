<?php
/**
 * Plugin Name: Complete Listing PDF Generator
 * Plugin URI: https://eatlocalfirst.org
 * Description: Generates PDFs for business listings with QR codes and contact information
 * Version: 1.0.0
 * Author: Eat Local First
 * License: GPL v2 or later
 * Text Domain: complete-listing-pdf
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COMPLETE_LISTING_PDF_VERSION', '1.0.0');
define('COMPLETE_LISTING_PDF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COMPLETE_LISTING_PDF_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Simple Universal PDF Generator V1
 * One template, predetermined fields, no complexity
 */

class SimpleListingPDFGenerator {
    
    private $field_map;
    
    public function __construct() {
        // Predetermined field mapping - customize these for your site
        $this->field_map = array(
            'name' => 'post_title',
            'location' => 'listing_location',
            'address' => 'location_address',
            'email' => 'email',
            'phone' => 'phone',
            'website' => 'website',
            'about' => 'post_content',
            'business_type' => 'listing_type', // taxonomy
            'products' => 'listing_categories', // taxonomy
            'certifications' => 'values_indicator', // taxonomy
            'growing_practices' => 'farms_fish_growing_methods',
            'retail_info' => 'listing_retail_info',
            'wholesale_info' => 'listing_wholesale_info',
            'csa_info' => 'listing_csa_info',
            'payment_methods' => 'listing_features', // taxonomy filtered
        );
    }
    
    /**
     * Main PDF generation - simple and straightforward
     */
    public function create_listing_pdf($post_id) {
        try {
            error_log('PDF Generation: Starting for post ID ' . $post_id);
            
            $data = $this->extract_data($post_id);
            error_log('PDF Generation: Data extracted - ' . print_r(array_keys($data), true));
            
            $qr_code = $this->generate_qr_code(get_permalink($post_id));
            error_log('PDF Generation: QR code generated - ' . $qr_code);
            
            if (!class_exists('TCPDF')) {
                // TCPDF should already be loaded by the AJAX handler
                // But try to load it if it's not available
                $tcpdf_paths = array(
                    plugin_dir_path(__FILE__) . 'vendor/tecnickcom/tcpdf/tcpdf.php',
                    plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php',
                    ABSPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php'
                );
                
                foreach ($tcpdf_paths as $path) {
                    if (file_exists($path)) {
                        require_once($path);
                        error_log('PDF Generation: TCPDF loaded from ' . $path);
                        break;
                    }
                }
                
                if (!class_exists('TCPDF')) {
                    throw new Exception('TCPDF class not available');
                }
            } else {
                error_log('PDF Generation: TCPDF already loaded');
            }
            
            error_log('PDF Generation: Creating TCPDF instance');
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Eat Local First Directory');
            $pdf->SetTitle($data['name'] . ' - Listing');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            $pdf->AddPage();
            
            error_log('PDF Generation: Building HTML template');
            
            // Try main template first, fall back to simple template if it fails
            try {
                $html = $this->build_html($data, $qr_code);
                
                // Log HTML length and first 200 chars for debugging
                error_log('PDF Generation: HTML length: ' . strlen($html));
                error_log('PDF Generation: HTML preview: ' . substr($html, 0, 200) . '...');
                
                error_log('PDF Generation: Writing HTML to PDF');
                $pdf->writeHTML($html, true, false, true, false, '');
                
            } catch (Exception $e) {
                error_log('PDF Generation: Main template failed, trying simple template: ' . $e->getMessage());
                
                // Fallback to simple template
                $simple_html = $this->build_simple_html($data, $qr_code);
                error_log('PDF Generation: Using simple template, length: ' . strlen($simple_html));
                $pdf->writeHTML($simple_html, true, false, true, false, '');
            }
            
            error_log('PDF Generation: Generating PDF output');
            $output = $pdf->Output('', 'S');
            
            error_log('PDF Generation: Success! PDF size: ' . strlen($output) . ' bytes');
            return $output;
            
        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            error_log('PDF Generation Error Stack: ' . $e->getTraceAsString());
            return false;
        } catch (Error $e) {
            error_log('PDF Generation Fatal Error: ' . $e->getMessage());
            error_log('PDF Generation Fatal Error Stack: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Extract data using predetermined field mapping
     */
    private function extract_data($post_id) {
        $post = get_post($post_id);
        
        $data = array(
            'post_id' => $post_id,
            'name' => $post->post_title,
            'about' => wp_strip_all_tags($post->post_content),
            'updated' => get_the_modified_date('F j, Y', $post_id),
            'url' => get_permalink($post_id),
            'hero_image' => $this->get_hero_image($post_id),
        );
        
        // Extract custom fields - simple version
        foreach ($this->field_map as $key => $field_name) {
            if ($key === 'name' || $key === 'about') continue; // Already handled
            
            if ($this->is_taxonomy($field_name)) {
                $data[$key] = $this->get_taxonomy_data($post_id, $field_name, $key);
            } else {
                $data[$key] = get_post_meta($post_id, $field_name, true);
            }
        }
        
        // Clean empty values
        foreach ($data as $key => $value) {
            if (empty($value)) {
                $data[$key] = '';
            }
        }
        
        return $data;
    }
    
    /**
     * Get hero image with ELF-specific fallback priority
     */
    private function get_hero_image($post_id) {
        // Priority 1: Primary image field (need to ask developer for exact field name)
        $primary_image_id = get_post_meta($post_id, 'logo_images_primary_image', true);
		if ($primary_image_id) {
			$primary_image = wp_get_attachment_image_url($primary_image_id, 'medium');

			if ($this->verify_image_accessible($primary_image)) {
				return $primary_image;
			}
		}
        
        // Priority 2: Featured image
        $featured_id = get_post_thumbnail_id($post_id);
        if ($featured_id) {
            $image_url = wp_get_attachment_image_url($featured_id, 'medium');
            if ($this->verify_image_accessible($image_url)) {
                return $image_url;
            }
        }
        
        // Priority 3: Logo field as fallback
        $logo_image_id = get_post_meta($post_id, 'logo_images_your_logo', true);
        if ($logo_image_id) {
            $logo_image = wp_get_attachment_image_url($logo_image_id, 'medium');
            if ($this->verify_image_accessible($logo_image)) {
                return $logo_image;
            }
        }
        
        // Priority 4: Atlas gallery first image
        $gallery_ids = get_post_meta($post_id, 'logo_images_additonal_images', true);
        if ($gallery_ids && is_array($gallery_ids) && !empty($gallery_ids[0])) {
            $image_url = wp_get_attachment_image_url($gallery_ids[0], 'medium');
            if ($this->verify_image_accessible($image_url)) {
                return $image_url;
            }
        }
        
        
        // No image found - return false to skip image section
        return false;
    }
    
    /**
     * Verify image is accessible
     */
    private function verify_image_accessible($image_url) {
        if (empty($image_url)) {
            return false;
        }
        
        $response = wp_remote_head($image_url, array('timeout' => 5));
        if (is_wp_error($response)) {
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        return $response_code == 200;
    }
    
    /**
     * Check if field is a taxonomy - simple version
     */
    private function is_taxonomy($field_name) {
        return in_array($field_name, array(
            'listing_type', 'listing_categories', 'values_indicator', 'listing_features'
        ));
    }
    
    /**
     * Get taxonomy data with special handling
     */
    private function get_taxonomy_data($post_id, $taxonomy, $key) {
        $terms = get_the_terms($post_id, $taxonomy);
        
        if (!$terms || is_wp_error($terms)) {
            return '';
        }
        
        // Special handling for payment methods
        if ($key === 'payment_methods') {
            return $this->filter_payment_methods($terms);
        }
        
        // Regular taxonomy formatting
        $term_names = array();
        foreach ($terms as $term) {
            $term_names[] = $term->name;
        }
        
        return implode(', ', $term_names);
    }
    
    /**
     * Filter features for payment methods only
     */
    private function filter_payment_methods($features) {
        $payment_keywords = array('cash', 'check', 'card', 'venmo', 'snap', 'ebt', 'wic');
        $payment_methods = array();
        
        foreach ($features as $feature) {
            $feature_lower = strtolower($feature->name);
            foreach ($payment_keywords as $keyword) {
                if (strpos($feature_lower, $keyword) !== false) {
                    $payment_methods[] = $feature->name;
                    break;
                }
            }
        }
        
        return implode(', ', $payment_methods);
    }
    
    /**
     * Generate QR code with simple fallback
     */
    private function generate_qr_code($url) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($url);
    }
    
    /**
     * Build the universal HTML template - WORKING VERSION with images
     */
    private function build_html($data, $qr_code) {
        error_log(print_r($data, true));
        
        // Hero image section
        $hero_image_section = '';
        if ($data['hero_image']) {
            $hero_image_section = sprintf(
                '<div style="text-align: center; margin: 8px 0;">
                    <img src="%s" style="height: 164px; width: auto;" alt="Business Image">
                </div>',
                esc_url($data['hero_image'])
            );
        }
        
        return sprintf('
        <style>
            body { 
                font-family: DejaVu Sans, Arial, sans-serif; 
                font-size: 11pt; 
                line-height: 1.4; 
                color: #333; 
                margin: 0; 
                padding: 0; 
            }
            
            .business-name {
                font-size: 20pt;
                font-weight: bold;
                color: #004D43;
                margin-bottom: 10px;
                text-align: center;
                border-bottom: 3px solid #6AA338;
                padding-bottom: 8px;
            }
            
            .business-type {
                background-color: #6AA338;
                color: white;
                padding: 5px 12px;
                font-size: 10pt;
                font-weight: bold;
                margin-bottom: 15px;
                text-align: center;
            }
            
            .contact-section {
                background-color: #f0f0f0;
                padding: 15px;
                margin: 15px 0;
                border-left: 4px solid #6AA338;
            }
            
            .contact-item {
                margin-bottom: 8px;
                font-size: 11pt;
            }
            
            .contact-label {
                font-weight: bold;
                color: #004D43;
                width: 70px;
                display: inline-block;
            }
            
            .section {
                margin: 20px 0;
            }
            
            .section-title {
                font-size: 14pt;
                font-weight: bold;
                color: #004D43;
                margin-bottom: 10px;
                border-bottom: 2px solid #6AA338;
                padding-bottom: 5px;
            }
            
            .section-content {
                font-size: 11pt;
                line-height: 1.5;
            }
            
            .products-list {
                background-color: #f0f8ff;
                padding: 12px;
                border: 1px solid #d6e9f0;
            }
            
            .certification-badge {
                background-color: #6AA338;
                color: white;
                padding: 4px 8px;
                font-size: 9pt;
                font-weight: bold;
                margin: 2px;
                display: inline-block;
            }
            
            .qr-section {
                text-align: center;
                padding: 15px;
                background-color: white;
                border: 1px solid #ddd;
                margin: 20px 0;
            }
            
            .qr-title {
                font-size: 12pt;
                font-weight: bold;
                margin-bottom: 10px;
                color: #004D43;
            }
            
            .footer {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 2px solid #e0e0e0;
                text-align: center;
                font-size: 9pt;
                color: #666;
            }
            
            .website-url {
                font-weight: bold;
                color: #6AA338;
                margin-bottom: 5px;
            }
        </style>
        
        
        <div class="business-name">%s</div>
        
        %s
        
        %s
        
        <table style="width: 100%%; border-collapse: collapse; margin: 15px 0;">
            <tr>
                <td style="width: 65%%; vertical-align: top; padding-right: 20px;">
                    <div class="contact-section">
                        <div style="font-weight: bold; color: #004D43; margin-bottom: 10px;">Contact Information</div>
                        %s
                        %s
                        %s
                        %s
                    </div>
                </td>
                <td style="width: 35%%; vertical-align: top;">
                    <div class="qr-section">
                        <div class="qr-title">Visit Online</div>
                        <img src="%s" style="width: 100px; height: 100px;" alt="QR Code">
                        <br><strong>Location:</strong> %s
                    </div>
                </td>
            </tr>
        </table>
        
        %s
        
        %s
        
        %s
        
        %s
        
        %s
        
        %s
        
        <div class="footer">
            <div class="website-url">%s</div>
            <div>Updated: %s</div>
            <div style="margin-top: 10px; font-size: 10pt; font-weight: bold; color: #6AA338;">
                Fresh • Local • Sustainable
            </div>
            <div style="margin-top: 5px; font-size: 8pt;">
                Generated from Eat Local First • Visit eatlocalfirst.org
            </div>
        </div>',
        
        // Data substitutions
        esc_html($data['name']),
        !empty($data['business_type']) ? '<div class="business-type">' . esc_html($data['business_type']) . '</div>' : '',
        $hero_image_section,
        !empty($data['location']) ? '<div class="contact-item"><span class="contact-label">Location:</span> ' . esc_html($data['location']) . '</div>' : '',
        !empty($data['email']) ? '<div class="contact-item"><span class="contact-label">Email:</span> ' . esc_html($data['email']) . '</div>' : '',
        !empty($data['phone']) ? '<div class="contact-item"><span class="contact-label">Phone:</span> ' . esc_html($data['phone']) . '</div>' : '',
        !empty($data['website']) ? '<div class="contact-item"><span class="contact-label">Website:</span> ' . esc_html($data['website']) . '</div>' : '',
        $qr_code,
        esc_html($data['location'] ?: $data['address'] ?: 'Location not specified'),
        !empty($data['certifications']) ? '<div class="section"><div class="section-title">Certifications</div><div>' . $this->format_certifications($data['certifications']) . '</div></div>' : '',
        !empty($data['products']) ? '<div class="section"><div class="section-title">Products & Services</div><div class="section-content products-list">' . nl2br(esc_html($data['products'])) . '</div></div>' : '',
        !empty($data['about']) ? '<div class="section"><div class="section-title">About Us</div><div class="section-content">' . nl2br(esc_html(wp_trim_words($data['about'], 100))) . '</div></div>' : '',
        !empty($data['growing_practices']) ? '<div class="section"><div class="section-title">Growing Practices</div><div class="section-content">' . nl2br(esc_html($data['growing_practices'])) . '</div></div>' : '',
        !empty($data['retail_info']) ? '<div class="section"><div class="section-title">Retail Information</div><div class="section-content">' . nl2br(esc_html($data['retail_info'])) . '</div></div>' : '',
        !empty($data['payment_methods']) ? '<div class="section"><div class="section-title">Payment Methods</div><div class="section-content">' . esc_html($data['payment_methods']) . '</div></div>' : '',
        esc_html($data['website'] ?: $data['url']),
        esc_html($data['updated'])
        );
    }
    
    /**
     * Format certifications as badges
     */
    private function format_certifications($certifications) {
        if (empty($certifications)) return '';
        
        $certs = explode(', ', $certifications);
        $badges = '';
        
        foreach ($certs as $cert) {
            $badges .= '<span class="certification-badge">' . esc_html(trim($cert)) . '</span> ';
        }
        
        return $badges;
    }
    
    /**
     * Simple fallback HTML template for debugging
     */
    private function build_simple_html($data, $qr_code) {
        return sprintf('
        <style>
            body { font-family: Arial, sans-serif; font-size: 12pt; }
            .header { background-color: #004D43; color: white; padding: 15px; text-align: center; margin-bottom: 15px; }
            .business-name { font-size: 18pt; font-weight: bold; margin-bottom: 10px; }
            .contact { margin: 10px 0; }
            .qr-section { text-align: center; margin: 15px 0; }
        </style>
        
        <div class="header">
            <h1>Eat Local First Directory</h1>
        </div>
        
        <div class="business-name">%s</div>
        
        <div class="contact">
            <strong>Address:</strong> %s<br>
            <strong>Email:</strong> %s<br>
            <strong>Phone:</strong> %s<br>
            <strong>Website:</strong> %s
        </div>
        
        <div class="qr-section">
            <p><strong>Visit Online:</strong></p>
            <img src="%s" style="width: 100px; height: 100px;" alt="QR Code">
        </div>
        
        <div style="margin-top: 20px; font-size: 10pt; text-align: center;">
            Generated from Eat Local First • Updated: %s
        </div>',
        
        esc_html($data['name']),
        esc_html($data['location'] ?: 'Not specified'),
        esc_html($data['email'] ?: 'Not specified'), 
        esc_html($data['phone'] ?: 'Not specified'),
        esc_html($data['website'] ?: 'Not specified'),
        $qr_code,
        esc_html($data['updated'])
        );
    }
}

/**
 * Main plugin class
 */
class CompleteListingPDFPlugin {
    
    private $pdf_generator;
    
    public function __construct() {
        $this->pdf_generator = new SimpleListingPDFGenerator();
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_generate_listing_pdf', array($this, 'handle_pdf_generation'));
        add_action('wp_ajax_nopriv_generate_listing_pdf', array($this, 'handle_pdf_generation'));
        
        // Try multiple hooks to place button after content but outside content area
        add_action('wp_footer', array($this, 'add_pdf_button_via_javascript'));
        add_action('genesis_entry_footer', array($this, 'add_pdf_button_to_entry_footer'));
        add_action('thesis_hook_after_post', array($this, 'add_pdf_button_to_entry_footer'));
        
        // Add debug page for admins
        add_action('wp_ajax_pdf_debug_test', array($this, 'handle_debug_test'));
        add_action('wp_footer', array($this, 'add_debug_link_for_admins'));
    }
    
    /**
     * Enqueue necessary scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on single listing pages
        if (is_singular() && $this->is_listing_post_type()) {
            wp_enqueue_script('jquery');
            // JavaScript is now handled in add_pdf_button_via_javascript()
        }
    }
    
    /**
     * Check if current post is a listing - SIMPLIFIED VERSION
     */
    private function is_listing_post_type() {
        $post = get_post();
        if (!$post) return false;
        
        // Much more inclusive - any published post can have a PDF generated
        // This includes posts, pages, and any custom post types
        return $post->post_status === 'publish';
    }
    
    /**
     * Check if current user can generate PDF for this listing - SIMPLIFIED VERSION
     */
    private function user_can_generate_pdf($post_id = null) {
        // Must be logged in
        if (!is_user_logged_in()) {
            return false;
        }
        
        // SIMPLIFIED: Any logged-in admin can generate PDFs
        // This makes testing much easier
        if (current_user_can('manage_options')) {
            return true;
        }
        
        // Also allow users who can edit posts
        if (current_user_can('edit_posts')) {
            return true;
        }
        
        $post_id = $post_id ?: get_the_ID();
        if (!$post_id) {
            return false;
        }
        
        // Still allow post authors
        $post = get_post($post_id);
        if ($post && $post->post_author == get_current_user_id()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Add PDF button via JavaScript positioning (better control)
     */
    public function add_pdf_button_via_javascript() {
        if (!is_singular() || !$this->is_listing_post_type()) {
            return;
        }
        
        if (!$this->user_can_generate_pdf()) {
            return;
        }
        
        $post_id = get_the_ID();
        echo $this->get_pdf_button_styles();
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Create the PDF button HTML
            var pdfButton = $('<div class="pdf-generator-wrapper" style="margin: 15px 0; padding: 15px; background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;"><button type="button" id="generate-pdf-btn" class="pdf-generator-btn button" data-post-id="<?php echo esc_js($post_id); ?>">📄 Download PDF</button></div>');
            
            // Try different selectors to find where to place the button
            var inserted = false;
            
            // Look for common "Edit Listing" button locations
            var editSelectors = [
                '.entry-meta .edit-link',
                '.post-edit-link', 
                '.edit-post-link',
                '.entry-footer .edit-link',
                '.listing-actions',
                '.post-actions',
                '[class*="edit"]'
            ];
            
            // Try to place after edit button
            for (var i = 0; i < editSelectors.length; i++) {
                var editElement = $(editSelectors[i]);
                if (editElement.length > 0) {
                    editElement.last().after(pdfButton);
                    inserted = true;
                    break;
                }
            }
            
            // Fallback: place after main content
            if (!inserted) {
                var contentSelectors = [
                    '.entry-content',
                    '.post-content', 
                    '.listing-content',
                    'article .content',
                    'main article',
                    '.single-post article'
                ];
                
                for (var i = 0; i < contentSelectors.length; i++) {
                    var contentElement = $(contentSelectors[i]);
                    if (contentElement.length > 0) {
                        contentElement.last().after(pdfButton);
                        inserted = true;
                        break;
                    }
                }
            }
            
            // Final fallback: place before footer
            if (!inserted) {
                $('footer').first().before(pdfButton);
            }
            
            // IMPORTANT: Add click event handler to the dynamically created button
            $(document).on('click', '#generate-pdf-btn', function(e) {
                e.preventDefault();
                
                var button = $(this);
                var postId = button.data('post-id');
                var originalText = button.text();
                
                // Show loading state
                button.prop('disabled', true).text('Generating...');
                
                // Create a form to submit the request
                var form = $('<form>', {
                    method: 'POST',
                    action: '<?php echo admin_url('admin-ajax.php'); ?>',
                    target: '_blank'
                });
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'action',
                    value: 'generate_listing_pdf'
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'post_id',
                    value: postId
                }));
                
                form.append($('<input>', {
                    type: 'hidden',
                    name: 'nonce',
                    value: '<?php echo wp_create_nonce('generate_pdf_nonce'); ?>'
                }));
                
                // Submit form
                $('body').append(form);
                form.submit();
                form.remove();
                
                // Reset button after a delay
                setTimeout(function() {
                    button.prop('disabled', false).text(originalText);
                }, 2000);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Add PDF button to entry footer (theme-specific hook)
     */
    public function add_pdf_button_to_entry_footer() {
        if (!is_singular() || !$this->is_listing_post_type()) {
            return;
        }
        
        if (!$this->user_can_generate_pdf()) {
            return;
        }
        
        $post_id = get_the_ID();
        printf(
            '<div class="pdf-generator-wrapper" style="margin: 15px 0; padding: 15px; background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 5px;">
                <button type="button" id="generate-pdf-btn" class="pdf-generator-btn button" data-post-id="%d">
                    📄 Download PDF
                </button>
            </div>',
            $post_id
        );
    }
    
    /**
     * Get CSS styles for PDF button (now used in JavaScript placement function)
     */
    private function get_pdf_button_styles() {
        return "
        <style>
            .pdf-generator-wrapper {
                margin: 15px 0 !important;
                padding: 15px;
                background-color: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            
            .pdf-generator-btn {
                background: linear-gradient(135deg, #004D43 0%, #6AA338 100%);
                color: white !important;
                border: none;
                padding: 10px 16px;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none !important;
                display: inline-block;
                line-height: 1.4;
            }
            
            .pdf-generator-btn:hover {
                background: linear-gradient(135deg, #6AA338 0%, #004D43 100%);
                color: white !important;
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0, 77, 67, 0.3);
                text-decoration: none !important;
            }
            
            .pdf-generator-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
                background: #999 !important;
            }
            
            .pdf-generator-btn:focus {
                outline: 2px solid #6AA338;
                outline-offset: 2px;
            }
        </style>
        ";
    }
    
    /**
     * Handle PDF generation AJAX request - WITH BETTER ERROR HANDLING
     */
    public function handle_pdf_generation() {
        try {
            // Log debug info
            error_log('PDF Generation AJAX Request: ' . print_r($_POST, true));
            
            // Check if required fields exist
            if (!isset($_POST['nonce'])) {
                wp_die('Missing nonce parameter');
            }
            
            if (!isset($_POST['post_id'])) {
                wp_die('Missing post_id parameter');
            }
            
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'generate_pdf_nonce')) {
                error_log('PDF Generation: Nonce verification failed');
                wp_die('Security check failed');
            }
            
            $post_id = intval($_POST['post_id']);
            
            if (!$post_id || !get_post($post_id)) {
                error_log('PDF Generation: Invalid post ID: ' . $post_id);
                wp_die('Invalid post ID');
            }
            
            // Check if user has permission to generate PDF for this listing
            if (!$this->user_can_generate_pdf($post_id)) {
                error_log('PDF Generation: Permission denied for user ' . get_current_user_id() . ' on post ' . $post_id);
                wp_die('You do not have permission to generate PDF for this listing');
            }
            
            // Check if TCPDF is available - try multiple paths
            if (!class_exists('TCPDF')) {
                $tcpdf_paths = array(
                    plugin_dir_path(__FILE__) . 'vendor/tecnickcom/tcpdf/tcpdf.php',
                    plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php',
                    ABSPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php',
                    '/usr/local/lib/php/tcpdf/tcpdf.php'
                );
                
                $tcpdf_loaded = false;
                foreach ($tcpdf_paths as $path) {
                    if (file_exists($path)) {
                        require_once($path);
                        $tcpdf_loaded = true;
                        error_log('PDF Generation: TCPDF loaded from: ' . $path);
                        break;
                    }
                }
                
                if (!$tcpdf_loaded) {
                    error_log('PDF Generation: TCPDF not found in any standard locations');
                    wp_die('PDF library not available. Please install TCPDF via Composer or contact administrator.');
                }
            }
            
            // Generate PDF
            error_log('PDF Generation: Attempting to generate PDF for post ' . $post_id);
            $pdf_content = $this->pdf_generator->create_listing_pdf($post_id);
            
            if (!$pdf_content) {
                error_log('PDF Generation: Failed to create PDF content');
                wp_die('Failed to generate PDF - check error logs');
            }
            
            // Get post title for filename
            $post_title = get_the_title($post_id);
            $filename = sanitize_file_name($post_title . '_listing.pdf');
            
            error_log('PDF Generation: Success! Generated PDF for post ' . $post_id . ', size: ' . strlen($pdf_content) . ' bytes');
            
            // Set headers for PDF download
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf_content));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            echo $pdf_content;
            exit;
            
        } catch (Exception $e) {
            error_log('PDF Generation Exception: ' . $e->getMessage());
            wp_die('PDF Generation Error: ' . $e->getMessage() . '<br><br><strong>Stack Trace:</strong><br><pre>' . $e->getTraceAsString() . '</pre>');
        }
    }
    
    /**
     * Handle PDF debug test (admin only)
     */
    public function handle_debug_test() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        echo "<h1>🐞 PDF Generation Debug Test</h1>";
        
        // Use specified post ID or find a post to test with
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;
        
        if ($post_id) {
            $test_post = get_post($post_id);
            if (!$test_post) {
                echo "<p>❌ Post with ID $post_id not found</p>";
                wp_die();
            }
        } else {
            // Fallback: find any published post
            $test_posts = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
            if (empty($test_posts)) {
                echo "<p>❌ No published posts found for testing</p>";
                wp_die();
            }
            $test_post = $test_posts[0];
            $post_id = $test_post->ID;
        }
        
        echo "<p>Testing with post: <strong>" . esc_html($test_post->post_title) . "</strong> (ID: $post_id)</p>";
        echo "<p>Post type: <strong>" . esc_html($test_post->post_type) . "</strong></p>";
        
        // Enable error display
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        
        try {
            $pdf_generator = new SimpleListingPDFGenerator();
            echo "<p>✅ PDF Generator class created</p>";
            
            // Capture any output/errors during PDF generation
            ob_start();
            $pdf_content = $pdf_generator->create_listing_pdf($post_id);
            $output = ob_get_clean();
            
            if ($output) {
                echo "<p><strong>Output during PDF generation:</strong></p>";
                echo "<pre style='background: #ffe6e6; padding: 10px;'>" . esc_html($output) . "</pre>";
            }
            
            if ($pdf_content) {
                echo "<p>✅ PDF generated successfully! Size: " . strlen($pdf_content) . " bytes</p>";
                echo "<p>✅ Test completed - PDF generation is working!</p>";
            } else {
                echo "<p>❌ PDF generation returned false</p>";
                
                // Check if TCPDF is available
                if (!class_exists('TCPDF')) {
                    echo "<p>❌ <strong>TCPDF class not found!</strong> This is likely the issue.</p>";
                    
                    // Show TCPDF paths we're checking
                    $tcpdf_paths = array(
                        plugin_dir_path(__FILE__) . 'vendor/tecnickcom/tcpdf/tcpdf.php',
                        plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php',
                        ABSPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php'
                    );
                    
                    echo "<p><strong>Checking TCPDF paths:</strong></p>";
                    foreach ($tcpdf_paths as $path) {
                        $exists = file_exists($path);
                        echo "<p>" . ($exists ? "✅" : "❌") . " " . esc_html($path) . "</p>";
                    }
                } else {
                    echo "<p>✅ TCPDF class is available</p>";
                }
            }
        } catch (Exception $e) {
            echo "<p>❌ Error: " . esc_html($e->getMessage()) . "</p>";
            echo "<pre>" . esc_html($e->getTraceAsString()) . "</pre>";
        } catch (Error $e) {
            echo "<p>❌ Fatal Error: " . esc_html($e->getMessage()) . "</p>";
            echo "<pre>" . esc_html($e->getTraceAsString()) . "</pre>";
        }
        
        wp_die();
    }
    
    /**
     * Add debug link for admins
     */
    public function add_debug_link_for_admins() {
        if (current_user_can('manage_options') && is_singular()) {
            $current_post_id = get_the_ID();
            $version_info = 'Simple v4ca30b8'; // Version identifier
            echo '<div id="pdf-debug-link" style="position: fixed; bottom: 20px; left: 20px; z-index: 9999; background: #333; color: white; padding: 8px 12px; border-radius: 4px; font-size: 12px;">
                <a href="#" onclick="window.open(\''. admin_url('admin-ajax.php') .'?action=pdf_debug_test&post_id=' . $current_post_id . '\', \'_blank\', \'width=800,height=600,scrollbars=yes\'); return false;" style="color: #fff; text-decoration: none;">
                    🐞 Test PDF (' . $version_info . ')
                </a>
            </div>';
        }
    }
}

// Initialize the plugin
new CompleteListingPDFPlugin();

?>

