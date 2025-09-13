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
            'wholesale_info' => null, // Will be handled by custom extraction
            'csa_info' => 'listing_csa_info',
            'listing_features' => 'listing_features', // taxonomy
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
        
        // DIRECT DEBUG: Let's see EXACTLY what's in wholesale_info field
        error_log("PDF Generation: DIRECT DEBUG - Checking wholesale_info field directly");
        $wholesale_direct = get_post_meta($post_id, 'wholesale_info', true);
        error_log("PDF Generation: wholesale_info field content: " . var_export($wholesale_direct, true));
        
        // If it's a field ID, let's get the actual field value
        if (!empty($wholesale_direct) && preg_match('/^field_[a-f0-9]+$/', $wholesale_direct)) {
            error_log("PDF Generation: wholesale_info contains field ID: " . $wholesale_direct);
            // Try to get the actual field value using get_field()
            if (function_exists('get_field')) {
                $acf_wholesale = get_field('wholesale_info', $post_id);
                error_log("PDF Generation: ACF get_field('wholesale_info') result: " . var_export($acf_wholesale, true));
                $data['wholesale_info'] = $acf_wholesale ?: '';
            } else {
                error_log("PDF Generation: ACF get_field() not available");
                $data['wholesale_info'] = '';
            }
        } else {
            $data['wholesale_info'] = $wholesale_direct ?: '';
        }
        
        // If still empty or field ID, do a comprehensive search
        if (empty($data['wholesale_info']) || preg_match('/^field_[a-f0-9]+$/', $data['wholesale_info'])) {
            error_log("PDF Generation: No direct wholesale found, doing comprehensive search...");
            
            // Debug: Log ALL post meta fields
            $all_meta = get_post_meta($post_id);
            error_log("PDF Generation: All post meta fields: " . print_r(array_keys($all_meta), true));
            
            // Search post content
            $post_content = get_post_field('post_content', $post_id);
            if (!empty($post_content)) {
                $extracted_content = $this->extract_wholesale_from_html($post_content);
                if ($extracted_content) {
                    $data['wholesale_info'] = $extracted_content;
                    error_log("PDF Generation: Found wholesale in post_content");
                }
            }
            
            // Search all meta fields
            if (empty($data['wholesale_info'])) {
                foreach ($all_meta as $meta_key => $meta_value) {
                    if (!empty($meta_value[0]) && is_string($meta_value[0]) && strlen($meta_value[0]) > 100) {
                        if (stripos($meta_value[0], 'wholesale') !== false || stripos($meta_value[0], 'products available') !== false) {
                            error_log("PDF Generation: Checking field '{$meta_key}' - contains wholesale keywords");
                            $extracted_content = $this->extract_wholesale_from_html($meta_value[0]);
                            if ($extracted_content) {
                                $data['wholesale_info'] = $extracted_content;
                                error_log("PDF Generation: Found wholesale content in field '{$meta_key}'");
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        error_log("PDF Generation: Final wholesale_info result: " . (!empty($data['wholesale_info']) ? substr($data['wholesale_info'], 0, 200) . "... (length: " . strlen($data['wholesale_info']) . ")" : "EMPTY"));
        
        // Wholesale info is now handled with a static message in the template
        $data['wholesale_info'] = 'static'; // Just a placeholder since we use static text
        
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
        
        // Special handling for products - filter out "Services"
        if ($key === 'products') {
            return $this->filter_products($terms);
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
     * Filter out "Services" category from products and format subcategories
     */
    private function filter_products($products) {
        $filtered_products = array();
        $subcategories = array();
        
        foreach ($products as $product) {
            // Skip any term that contains "Services" (case insensitive)
            if (stripos($product->name, 'services') === false) {
                $product_name = $product->name;
                
                // Check if this looks like a subcategory (longer descriptive names)
                // Common subcategory patterns: contains "Locally", "Raised", "Grown", "Harvested", etc.
                $subcategory_keywords = array('locally', 'raised', 'harvested', 'grown', 'organic', 'certified', 'fresh', 'wholesale');
                $is_subcategory = false;
                
                foreach ($subcategory_keywords as $keyword) {
                    if (stripos($product_name, $keyword) !== false && strlen($product_name) > 15) {
                        $is_subcategory = true;
                        break;
                    }
                }
                
                if ($is_subcategory) {
                    $subcategories[] = '<strong>' . $product_name . ':</strong>';
                } else {
                    $filtered_products[] = $product_name;
                }
            }
        }
        
        // Format output: subcategories with line breaks, products with commas
        $formatted_parts = array();
        
        // Add subcategories first (each on own line)
        foreach ($subcategories as $subcategory) {
            $formatted_parts[] = $subcategory;
        }
        
        // Add products as comma-separated list if there are any
        if (!empty($filtered_products)) {
            $products_line = implode(', ', $filtered_products);
            $formatted_parts[] = $products_line;
        }
        
        return implode('<br>', $formatted_parts);
    }
    
    /**
     * Extract wholesale content from HTML under "Products available for wholesale" h3
     */
    private function extract_wholesale_from_html($html_content) {
        if (empty($html_content)) {
            return false;
        }
        
        error_log("PDF Generation: Analyzing HTML content for wholesale information (length: " . strlen($html_content) . ")");
        error_log("PDF Generation: HTML preview: " . substr($html_content, 0, 500) . "...");
        
        // Method 1: Look for the complete wholesale tabpanel section with ID
        $pattern_tabpanel_id = '/<div[^>]*id="tabpanel-wholesale"[^>]*>(.*?)<\/div(?:\s[^>]*)?>(?:\s*<\/div>)*/is';
        if (preg_match($pattern_tabpanel_id, $html_content, $matches)) {
            $tabpanel_content = $matches[1];
            error_log("PDF Generation: Found wholesale tabpanel section by ID");
            
            $content = $this->process_wholesale_tabpanel_content($tabpanel_content);
            if ($content) {
                return $content;
            }
        }
        
        // Method 2: Look for tabpanel with wholesale class
        $pattern_tabpanel_class = '/<div[^>]*class="[^"]*tabpanel[^"]*wholesale[^"]*"[^>]*>(.*?)<\/div>/is';
        if (preg_match($pattern_tabpanel_class, $html_content, $matches)) {
            $tabpanel_content = $matches[1];
            error_log("PDF Generation: Found wholesale tabpanel section by class");
            
            $content = $this->process_wholesale_tabpanel_content($tabpanel_content);
            if ($content) {
                return $content;
            }
        }
        
        // Method 3: Look for the specific div with "products-available-for-wholesale" class
        $pattern_products_div = '/<div[^>]*class="[^"]*products-available-for-wholesale[^"]*"[^>]*>(.*?)<\/div>/is';
        if (preg_match($pattern_products_div, $html_content, $matches)) {
            $products_content = $matches[1];
            error_log("PDF Generation: Found products-available-for-wholesale div");
            
            // Process the products content
            $content = $this->clean_wholesale_content($products_content);
            if (!empty($content)) {
                error_log("PDF Generation: Successfully extracted wholesale content from products div");
                return "Products available for wholesale:\n" . $content;
            }
        }
        
        // Method 4: Look for the specific h3 pattern: <h3 class="h-large">Products available for wholesale</h3>
        $pattern_h3 = '/<h3[^>]*class="h-large"[^>]*>Products available for wholesale<\/h3>\s*(.*?)(?=<\/div>|<h[1-6]|$)/is';
        if (preg_match($pattern_h3, $html_content, $matches)) {
            $content = $matches[1];
            $content = $this->clean_wholesale_content($content);
            
            if (!empty($content)) {
                error_log("PDF Generation: Successfully extracted wholesale content from h3 section");
                return "Products available for wholesale:\n" . $content;
            }
        }
        
        // Method 5: General wholesale patterns
        $general_patterns = array(
            '/<h3[^>]*>Products available for wholesale<\/h3>\s*(.*?)(?=<h[1-6]|<\/div>|$)/is',
            '/<h2[^>]*>Wholesale Info<\/h2>\s*(.*?)(?=<h[1-6]|<\/div>|$)/is',
            '/<h3[^>]*>[^<]*wholesale[^<]*<\/h3>\s*(.*?)(?=<h[1-6]|<\/div>|$)/is'
        );
        
        foreach ($general_patterns as $index => $pattern) {
            if (preg_match($pattern, $html_content, $matches)) {
                $content = $matches[1];
                $content = $this->clean_wholesale_content($content);
                
                if (!empty($content)) {
                    error_log("PDF Generation: Successfully extracted wholesale content from general pattern #" . ($index + 1));
                    return $content;
                }
            }
        }
        
        error_log("PDF Generation: Could not extract wholesale content from HTML section");
        return false;
    }
    
    /**
     * Process content from wholesale tabpanel
     */
    private function process_wholesale_tabpanel_content($tabpanel_content) {
        $content = '';
        
        // Get content after the h2 title, before any listing-section divs
        if (preg_match('/<h2[^>]*>Wholesale Info<\/h2>\s*(.*?)(?=<div[^>]*class="listing-section"|$)/is', $tabpanel_content, $main_matches)) {
            $main_content = $this->clean_wholesale_content($main_matches[1]);
            if (!empty($main_content)) {
                $content .= $main_content;
            }
        }
        
        // Also get the "Products available for wholesale" section specifically
        if (preg_match('/<div[^>]*class="[^"]*products-available-for-wholesale[^"]*"[^>]*>(.*?)<\/div>/is', $tabpanel_content, $products_matches)) {
            $products_content = $products_matches[1];
            // Remove the h3 title and get the content
            $products_content = preg_replace('/<h3[^>]*>.*?<\/h3>/', '', $products_content);
            $products_content = $this->clean_wholesale_content($products_content);
            
            if (!empty($products_content)) {
                if (!empty($content)) {
                    $content .= "\n\nProducts available for wholesale:\n" . $products_content;
                } else {
                    $content = "Products available for wholesale:\n" . $products_content;
                }
            }
        }
        
        if (!empty($content)) {
            error_log("PDF Generation: Successfully extracted wholesale content from tabpanel: " . substr($content, 0, 200) . "...");
            return $content;
        }
        
        return false;
    }
    
    /**
     * Clean wholesale content - remove HTML tags and normalize whitespace
     */
    private function clean_wholesale_content($content) {
        if (empty($content)) {
            return '';
        }
        
        // Remove any remaining field IDs that might have slipped through
        $content = preg_replace('/field_[a-f0-9]+/', '', $content);
        
        // Strip HTML tags but keep basic structure
        $content = strip_tags($content, '<p><br><ul><li><strong><b><em><i><span>');
        
        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        
        // Remove empty paragraphs and clean up
        $content = preg_replace('/<p[^>]*>\s*<\/p>/', '', $content);
        $content = preg_replace('/(<br\s*\/?>\s*){3,}/', '<br><br>', $content);
        
        $content = trim($content);
        
        return $content;
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
                    <img src="%s" style="max-height: 100px; max-width: 100%%; width: auto;" alt="Business Image">
                </div>',
                esc_url($data['hero_image'])
            );
        } else {
            // Placeholder when no image
            $hero_image_section = '<div style="text-align: center; margin: 8px 0; color: #999; font-style: italic;">No image available</div>';
        }
        
        return sprintf('
        <style>
            body { 
                font-family: helvetica, Arial, sans-serif; 
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
                color: #004D43;
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
                margin: 0px 0;
            }
            
            .section-title {
                font-size: 14pt;
                font-weight: bold;
                color: #004D43;
                margin-bottom: 10px;
            }
            
            .section-content {
                font-size: 11pt;
                line-height: 1.5;
            }
            
            .products-list {
                padding: 12px;
            }
            
            .certification-badge {
                color: #004D43;
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
        
        <table style="width: 100%%; border-collapse: collapse; margin: 15px 0;">
            <tr>
                <td style="width: 50%%; vertical-align: top; padding-right: 20px;">
                    <div style="padding: 15px;">
                        %s
                    </div>
                </td>
                <td style="width: 50%%; vertical-align: top;">
                    <div style="padding: 15px;">
                        %s
                    </div>
                </td>
            </tr>
        </table>
        
        <table style="width: 100%%; border-collapse: collapse; margin: 15px 0;">
            <tr>
                <td style="width: 65%%; vertical-align: top; padding-right: 20px;">
                    <div style="padding: 15px;">
                        <div style="font-weight: bold; color: #004D43; margin-bottom: 10px;">Contact Information</div>
                        %s
                        %s
                        %s
                        %s
                    </div>
                </td>
                <td style="width: 35%%; vertical-align: top;">
                    <div style="text-align: center; padding: 15px;">
                        <div class="qr-title">Scan for more details</div>
                        <img src="%s" style="width: 100px; height: 100px;" alt="QR Code">
                    </div>
                </td>
            </tr>
        </table>
        
        %s
        
        %s
        
        %s
        
        %s
        
        %s
        
        <div class="footer">
            <div class="website-url">%s</div>
            <div>Updated: %s</div>
            <div style="margin-top: 10px; font-size: 8pt;">
                Generated from Eat Local First • Visit %s
            </div>
        </div>',
        
        // Data substitutions
        esc_html($data['name']),
        '',
        $hero_image_section,
        !empty($data['about']) ? '<div class="section-title">About Us</div><div class="section-content">' . nl2br(esc_html(wp_trim_words($data['about'], 100))) . '</div>' : '<div class="section-title">About Us</div><div class="section-content" style="color: #999; font-style: italic;">No information available</div>',
        !empty($data['location']) ? '<div class="contact-item"><span class="contact-label">Location:</span> ' . esc_html($data['location']) . '</div>' : '',
        !empty($data['email']) ? '<div class="contact-item"><span class="contact-label">Email:</span> ' . esc_html($data['email']) . '</div>' : '',
        !empty($data['phone']) ? '<div class="contact-item"><span class="contact-label">Phone:</span> ' . esc_html($data['phone']) . '</div>' : '',
        !empty($data['website']) ? '<div class="contact-item"><span class="contact-label">Website:</span> ' . esc_html($data['website']) . '</div>' : '',
        $qr_code,
        !empty($data['csa_info']) ? '<div class="section"><div class="section-title">CSA Info</div><div class="section-content">' . nl2br(esc_html($data['csa_info'])) . '</div></div>' : '',
        !empty($data['products']) ? '<div class="section"><div class="section-title">Products & Services</div><div class="section-content products-list">' . $data['products'] . '</div></div>' : '',
        '<div class="section"><div class="section-title">Wholesale</div><div class="section-content">Contact us for wholesale products or scan the QR code for more details.</div></div>',
        !empty($data['certifications']) ? '<div class="section"><div class="section-title">Certifications</div><div>' . $this->format_certifications($data['certifications']) . '</div></div>' : '',
        !empty($data['growing_practices']) ? '<div class="section"><div class="section-title">Growing Practices</div><div class="section-content">' . nl2br(esc_html($data['growing_practices'])) . '</div></div>' : '',
        !empty($data['retail_info']) ? '<div class="section"><div class="section-title">Retail Information</div><div class="section-content">' . nl2br(esc_html($data['retail_info'])) . '</div></div>' : '',
        esc_html($data['website'] ?: $data['url']),
        esc_html($data['updated']),
        esc_html($data['url'])
        );
    }
    
    /**
     * Format certifications as badges - filtered for Environmental Sustainability and Fair Labor only
     */
    private function format_certifications($certifications) {
        if (empty($certifications)) return '';
        
        $certs = explode(', ', $certifications);
        $badges = '';
        
        // Filter for specific certification categories
        $allowed_categories = array('environmental sustainability', 'fair labor');
        
        foreach ($certs as $cert) {
            $cert_lower = strtolower(trim($cert));
            $include_cert = false;
            
            // Check if certification contains any of the allowed categories
            foreach ($allowed_categories as $category) {
                if (stripos($cert_lower, $category) !== false) {
                    $include_cert = true;
                    break;
                }
            }
            
            if ($include_cert) {
                $badges .= '<span class="certification-badge">' . esc_html(trim($cert)) . '</span> ';
            }
        }
        
        return $badges;
    }
    
    /**
     * Simple fallback HTML template for debugging
     */
    private function build_simple_html($data, $qr_code) {
        return sprintf('
        <style>
            body { font-family: helvetica, Arial, sans-serif; font-size: 12pt; }
            .header { background-color: #6AA338; color: white; padding: 15px; text-align: center; margin-bottom: 15px; }
            .header h1 { font-weight: bold; color: white; margin: 0; }
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
            <p><strong>Scan for more details:</strong></p>
            <img src="%s" style="width: 100px; height: 100px;" alt="QR Code">
        </div>
        
        <div style="margin-top: 20px; font-size: 10pt; text-align: center;">
            Generated from Eat Local First • Visit %s • Updated: %s
        </div>',
        
        esc_html($data['name']),
        esc_html($data['location'] ?: 'Not specified'),
        esc_html($data['email'] ?: 'Not specified'), 
        esc_html($data['phone'] ?: 'Not specified'),
        esc_html($data['website'] ?: 'Not specified'),
        $qr_code,
        esc_html($data['url']),
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

