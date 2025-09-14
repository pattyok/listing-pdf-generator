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
            $data = $this->extract_data($post_id);
            $qr_code = $this->generate_qr_code(get_permalink($post_id));
            
            if (!class_exists('TCPDF')) {
                $tcpdf_paths = array(
                    plugin_dir_path(__FILE__) . 'vendor/tecnickcom/tcpdf/tcpdf.php',
                    plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php',
                    ABSPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php'
                );
                
                foreach ($tcpdf_paths as $path) {
                    if (file_exists($path)) {
                        require_once($path);
                        break;
                    }
                }
                
                if (!class_exists('TCPDF')) {
                    throw new Exception('TCPDF class not available');
                }
            }
            
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Eat Local First Directory');
            $pdf->SetTitle($data['name'] . ' - Listing');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            $pdf->AddPage();
            
            // Try main template first, fall back to simple template if it fails
            try {
                $html = $this->build_html($data, $qr_code);
                $pdf->writeHTML($html, true, false, true, false, '');
            } catch (Exception $e) {
                // Fallback to simple template
                $simple_html = $this->build_simple_html($data, $qr_code);
                $pdf->writeHTML($simple_html, true, false, true, false, '');
            }
            
            $output = $pdf->Output('', 'S');
            return $output;
            
        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            return false;
        } catch (Error $e) {
            error_log('PDF Generation Fatal Error: ' . $e->getMessage());
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
        
        // Wholesale info is handled with a static message in the template
        $data['wholesale_info'] = 'static';
        
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

			if ($primary_image) {
				return $primary_image;
			}
		}
        
        // Priority 2: Featured image
        $featured_id = get_post_thumbnail_id($post_id);
        if ($featured_id) {
            $image_url = wp_get_attachment_image_url($featured_id, 'medium');
            if ($image_url) {
                return $image_url;
            }
        }
        
        // Priority 3: Logo field as fallback
        $logo_image_id = get_post_meta($post_id, 'logo_images_your_logo', true);
        if ($logo_image_id) {
            $logo_image = wp_get_attachment_image_url($logo_image_id, 'medium');
            if ($logo_image) {
                return $logo_image;
            }
        }
        
        // Priority 4: Atlas gallery first image
        $gallery_ids = get_post_meta($post_id, 'logo_images_additonal_images', true);
        if ($gallery_ids && is_array($gallery_ids) && !empty($gallery_ids[0])) {
            $image_url = wp_get_attachment_image_url($gallery_ids[0], 'medium');
            if ($image_url) {
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
     * Filter out "Services" category from products and format by categories
     */
    private function filter_products($products) {
        // Group products by parent categories
        $categories = array();
        
        foreach ($products as $product) {
            // Skip the top-level "Locally Raised, Harvested, Grown" category
            if (stripos($product->name, 'locally raised') !== false || 
                stripos($product->name, 'harvested') !== false || 
                stripos($product->name, 'grown') !== false) {
                continue;
            }
            
            // Skip services
            if (stripos($product->name, 'services') !== false) continue;
            
            // Determine category groupings based on common patterns
            $category = $this->determine_product_category($product->name);
            
            if (!isset($categories[$category])) {
                $categories[$category] = array();
            }
            $categories[$category][] = $product->name;
        }
        
        // Format output with bold category headers
        $formatted_output = array();
        foreach ($categories as $category => $items) {
            $items_text = implode(', ', $items);
            $formatted_output[] = '<strong>' . $category . ':</strong> ' . $items_text;
        }
        
        return implode('<br><br>', $formatted_output);
    }
    
    /**
     * Determine product category based on name patterns
     */
    private function determine_product_category($product_name) {
        $product_lower = strtolower($product_name);
        
        // Define category mappings
        if (stripos($product_lower, 'egg') !== false) return 'Eggs';
        if (stripos($product_lower, 'flower') !== false || stripos($product_lower, 'nursery') !== false || stripos($product_lower, 'tree') !== false) return 'Flowers, Nursery & Trees';
        if (stripos($product_lower, 'grain') !== false || stripos($product_lower, 'pulse') !== false || stripos($product_lower, 'bean') !== false || stripos($product_lower, 'wheat') !== false) return 'Grains & Pulses';
        if (stripos($product_lower, 'seed') !== false || stripos($product_lower, 'start') !== false || stripos($product_lower, 'plant') !== false) return 'Seeds & Starts';
        if (stripos($product_lower, 'meat') !== false || stripos($product_lower, 'poultry') !== false || stripos($product_lower, 'beef') !== false || stripos($product_lower, 'chicken') !== false) return 'Meat & Poultry';
        if (stripos($product_lower, 'dairy') !== false || stripos($product_lower, 'milk') !== false || stripos($product_lower, 'cheese') !== false) return 'Dairy';
        if (stripos($product_lower, 'seafood') !== false || stripos($product_lower, 'fish') !== false) return 'Seafood';
        
        // Vegetables & Herbs - most comprehensive category for individual vegetables
        $vegetables = array('arugula', 'basil', 'beets', 'broccoli', 'cabbage', 'carrots', 'kale', 'lettuce', 'onions', 'potatoes', 'tomatoes', 'asian greens', 'bok choy', 'bell peppers', 'brussels sprouts', 'cauliflower', 'celeriac', 'chard', 'chives', 'collard greens', 'corn', 'cucumbers', 'daikon', 'dill', 'eggplant', 'escarole', 'garlic', 'herbs', 'kohlrabi', 'leeks', 'mustard greens', 'oregano', 'parsley', 'parsnips', 'peas', 'peppers', 'popcorn', 'pumpkins', 'radicchio', 'radishes', 'rhubarb', 'salad greens', 'shallots', 'spinach', 'squash', 'sunchokes', 'thyme', 'tomatillos', 'turnips');
        
        foreach ($vegetables as $veg) {
            if (stripos($product_lower, $veg) !== false) {
                return 'Vegetables & Herbs';
            }
        }
        
        if (stripos($product_lower, 'fruit') !== false || stripos($product_lower, 'berr') !== false || stripos($product_lower, 'apple') !== false) return 'Fruit & Berries';
        
        // Default category for uncategorized items
        return 'Other Products';
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
     * Generate QR code with base64 encoding to avoid URL issues
     */
    private function generate_qr_code($url) {
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($url);
        
        $response = wp_remote_get($qr_url, array('timeout' => 10));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $image_data = wp_remote_retrieve_body($response);
            $base64 = base64_encode($image_data);
            return 'data:image/png;base64,' . $base64;
        }
        
        // Fallback to URL if base64 fails
        return $qr_url;
    }
    
    /**
     * Build the universal HTML template - WORKING VERSION with images
     */
    private function build_html($data, $qr_code) {
        
        // Content section with stacked layout
        if (!empty($data['about'])) {
            $full_about = $data['about'];
            $truncated_about = wp_trim_words($full_about, 100);
            
            // Check if text was actually truncated
            if (strlen($truncated_about) < strlen($full_about)) {
                $about_content = nl2br(esc_html($truncated_about)) . ' Scan the QR code to learn more.';
            } else {
                $about_content = nl2br(esc_html($truncated_about));
            }
        } else {
            $about_content = '<span style="color: #999; font-style: italic;">No information available</span>';
        }

        if ($data['hero_image']) {
            $content_section = sprintf('
            <div style="margin: 8px 0;">
                <div class="section-title" style="margin-bottom: 6px;">About Us</div>
                <table style="width: 100%%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 30%%; vertical-align: top; text-align: center; padding-right: 12px;">
                            <img src="%s" width="150" height="120" alt="Business Photo">
                        </td>
                        <td style="width: 70%%; vertical-align: top; padding-left: 12px;">
                            <div class="section-content" style="text-align: justify; line-height: 1.3;">
                                %s
                            </div>
                        </td>
                    </tr>
                </table>
            </div>', 
            esc_url($data['hero_image']), 
            $about_content);
        } else {
            $content_section = sprintf('
            <div style="margin: 8px 0;">
                <div class="section-title">About Us</div>
                <div class="section-content" style="text-align: justify; line-height: 1.3;">
                    %s
                </div>
            </div>', 
            $about_content);
        }
        
        return sprintf('
        <style>
            body { 
                font-family: helvetica, Arial, sans-serif; 
                font-size: 10pt; 
                line-height: 1.3; 
                color: #333; 
                margin: 0; 
                padding: 0; 
            }
            
            .business-name {
                font-size: 18pt;
                font-weight: bold;
                color: white;
                background-color: #004D43;
                margin-bottom: 8px;
                text-align: center;
                padding: 24px 24px;
                border-radius: 5px;
            }
            
            .business-type {
                color: #004D43;
                font-size: 10pt;
                font-weight: bold;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .contact-section {
                background-color: #f0f0f0;
                padding: 8px;
                margin: 8px 0;
                border-left: 4px solid #6AA338;
            }
            
            .contact-item {
                margin-bottom: 4px;
                font-size: 10pt;
            }
            
            .contact-label {
                font-weight: bold;
                color: #004D43;
                width: 60px;
                display: inline-block;
            }
            
            .section {
                margin: 5px 0;
            }
            
            .section-title {
                font-size: 12pt;
                font-weight: bold;
                color: #004D43;
                margin-bottom: 6px;
            }
            
            .section-content {
                font-size: 10pt;
                line-height: 1.3;
            }
            
            .products-list {
                padding: 6px;
            }
            
            .certification-badge {
                color: #004D43;
                padding: 2px 6px;
                font-size: 8pt;
                font-weight: bold;
                margin: 1px;
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
                font-size: 11pt;
                font-weight: bold;
                margin-bottom: 6px;
                color: #004D43;
            }
            
            .footer {
                margin-top: 15px;
                padding-top: 8px;
                border-top: 2px solid #e0e0e0;
                text-align: center;
                font-size: 8pt;
                color: #666;
            }
            
            .website-url {
                font-weight: bold;
                color: #6AA338;
                margin-bottom: 3px;
            }
        </style>
        
        
        <div class="business-name">%s</div>
        
        <table style="width: 100%%; border-collapse: collapse; margin: 8px 0;">
            <tr>
                <td style="width: 65%%; vertical-align: top; padding-right: 20px;">
                    <div style="padding: 8px;">
                        <div style="font-weight: bold; color: #004D43; margin-bottom: 6px;">Contact Information</div>
                        %s
                        %s
                        %s
                        %s
                    </div>
                </td>
                <td style="width: 35%%; vertical-align: top;">
                    <div style="text-align: center; padding: 8px;">
                        <div class="qr-title">Scan for more details</div>
                        <img src="%s" style="width: 80px; height: 80px;" alt="QR Code">
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
            <div style="margin-top: 10px; font-size: 8pt;">
                Generated from %s
            </div>
        </div>',
        
        // Data substitutions in correct order
        esc_html($data['name']), // Business name
        !empty($data['location']) ? '<div class="contact-item"><span class="contact-label">Location:</span> ' . esc_html($data['location']) . '</div>' : '',
        !empty($data['email']) ? '<div class="contact-item"><span class="contact-label">Email:</span> ' . esc_html($data['email']) . '</div>' : '',
        !empty($data['phone']) ? '<div class="contact-item"><span class="contact-label">Phone:</span> ' . esc_html($data['phone']) . '</div>' : '',
        !empty($data['website']) ? '<div class="contact-item"><span class="contact-label">Website:</span> ' . esc_html($data['website']) . '</div>' : '',
        $qr_code, // QR code URL/base64
        $content_section, // Content section (about us with image)
        !empty($data['products']) ? '<div class="section"><div class="section-title">Products & Services</div><div class="section-content products-list">' . $data['products'] . '</div></div>' : '',
        '<div class="section"><div class="section-title">Wholesale</div><div class="section-content">Contact us for wholesale products or scan the QR code for more details.</div></div>',
        '',
        !empty($data['growing_practices']) ? '<div class="section"><div class="section-title">Growing Practices</div><div class="section-content">' . nl2br(esc_html($data['growing_practices'])) . '</div></div>' : '',
        esc_html($data['website'] ?: $data['url']), // Footer website URL
        esc_html($data['url']) // Full URL for footer
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
            .business-name { font-size: 18pt; font-weight: bold; margin-bottom: 10px; }
            .contact { margin: 10px 0; }
            .qr-section { text-align: center; margin: 15px 0; }
        </style>
        
        <div style="background-color: #6AA338; color: white; padding: 25px 15px; text-align: center; margin-bottom: 15px; font-size: 16pt; font-weight: bold; min-height: 40px; line-height: 1.4;">
            Eat Local First Directory
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
    
    
}

// Initialize the plugin
new CompleteListingPDFPlugin();

?>

