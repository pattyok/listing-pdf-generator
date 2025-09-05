<?php
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
            'address' => 'listing_full_address', 
            'email' => 'listing_email',
            'phone' => 'listing_phone',
            'website' => 'listing_website',
            'about' => 'post_content',
            'business_type' => 'listing_type', // taxonomy
            'products' => 'listing_categories', // taxonomy
            'certifications' => 'values_indicator', // taxonomy
            'growing_practices' => 'listing_growing_practices',
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
            $data = $this->extract_data($post_id);
            $qr_code = $this->generate_qr_code(get_permalink($post_id));
            
            if (!class_exists('TCPDF')) {
                require_once(plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php');
            }
            
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Eat Local First Directory');
            $pdf->SetTitle($data['name'] . ' - Listing');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 15);
            $pdf->AddPage();
            
            $html = $this->build_html($data, $qr_code);
            $pdf->writeHTML($html, true, false, true, false, '');
            
            return $pdf->Output('', 'S');
            
        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
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
        
        // Extract custom fields
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
        // Priority 1: Primary image field
        $primary_image = get_post_meta($post_id, 'primary_image', true);
        if ($primary_image && $this->verify_image_accessible($primary_image)) {
            return $primary_image;
        }
        
        // Priority 2: Featured image
        $featured_id = get_post_thumbnail_id($post_id);
        if ($featured_id) {
            $image_url = wp_get_attachment_image_url($featured_id, 'medium');
            if ($image_url && $this->verify_image_accessible($image_url)) {
                return $image_url;
            }
        }
        
        // Priority 3: Logo field as fallback
        $logo_image = get_post_meta($post_id, 'logo', true);
        if ($logo_image && $this->verify_image_accessible($logo_image)) {
            return $logo_image;
        }
        
        // Priority 4: Atlas gallery first image
        $gallery_ids = get_post_meta($post_id, '_atlas_gallery', true);
        if ($gallery_ids && is_array($gallery_ids) && !empty($gallery_ids[0])) {
            $image_url = wp_get_attachment_image_url($gallery_ids[0], 'medium');
            if ($image_url && $this->verify_image_accessible($image_url)) {
                return $image_url;
            }
        }
        
        // Priority 5: First attached image
        $attachments = get_attached_media('image', $post_id);
        if (!empty($attachments)) {
            $first_attachment = reset($attachments);
            $image_url = wp_get_attachment_image_url($first_attachment->ID, 'medium');
            if ($image_url && $this->verify_image_accessible($image_url)) {
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
     * Check if field is a taxonomy
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
     * Build the universal HTML template - FIXED for better TCPDF compatibility
     */
    private function build_html($data, $qr_code) {
        // Hero image section
        $hero_image_section = '';
        if ($data['hero_image']) {
            $hero_image_section = sprintf(
                '<div style="text-align: center; margin: 15px 0;">
                    <img src="%s" style="max-width: 200px; height: auto;" alt="Business Image">
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
            
            .header {
                background-color: #004D43;
                color: white;
                padding: 20px;
                text-align: center;
                margin-bottom: 20px;
            }
            
            .header-title {
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 5px;
            }
            
            .header-subtitle {
                font-size: 12pt;
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
        
        <div class="header">
            <div class="header-title">Fresh • Local • Sustainable</div>
            <div class="header-subtitle">Farm & Business Directory</div>
        </div>
        
        <div class="business-name">%s</div>
        
        %s
        
        %s
        
        <div class="contact-section">
            <div style="font-weight: bold; color: #004D43; margin-bottom: 10px;">Contact Information</div>
            %s
            %s
            %s
            %s
        </div>
        
        %s
        
        %s
        
        %s
        
        %s
        
        %s
        
        %s
        
        <div class="qr-section">
            <div class="qr-title">Visit Online</div>
            <img src="%s" style="width: 100px; height: 100px;" alt="QR Code">
            <br><strong>Location:</strong> %s
        </div>
        
        <div class="footer">
            <div class="website-url">%s</div>
            <div>Updated: %s</div>
            <div style="margin-top: 10px; font-size: 8pt;">
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
        !empty($data['certifications']) ? '<div class="section"><div class="section-title">Certifications</div><div>' . $this->format_certifications($data['certifications']) . '</div></div>' : '',
        !empty($data['products']) ? '<div class="section"><div class="section-title">Products & Services</div><div class="section-content products-list">' . nl2br(esc_html($data['products'])) . '</div></div>' : '',
        !empty($data['about']) ? '<div class="section"><div class="section-title">About Us</div><div class="section-content">' . nl2br(esc_html(wp_trim_words($data['about'], 100))) . '</div></div>' : '',
        !empty($data['growing_practices']) ? '<div class="section"><div class="section-title">Growing Practices</div><div class="section-content">' . nl2br(esc_html($data['growing_practices'])) . '</div></div>' : '',
        !empty($data['retail_info']) ? '<div class="section"><div class="section-title">Retail Information</div><div class="section-content">' . nl2br(esc_html($data['retail_info'])) . '</div></div>' : '',
        !empty($data['payment_methods']) ? '<div class="section"><div class="section-title">Payment Methods</div><div class="section-content">' . esc_html($data['payment_methods']) . '</div></div>' : '',
        $qr_code,
        esc_html($data['location'] ?: $data['address'] ?: 'Location not specified'),
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
}

?>
