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
     * Build the universal HTML template
     */
    private function build_html($data, $qr_code) {
		error_log(print_r($data, true));
        return sprintf('
        <style>
            @page { size: letter; margin: 15mm; }
            body { 
                font-family: Arial, sans-serif; 
                font-size: 11pt; 
                line-height: 1.4; 
                color: #333; 
                margin: 0; 
                padding: 0; 
            }
            
            .header {
                background: linear-gradient(135deg, #004D43 0%%, #6AA338 100%%);
                color: white;
                padding: 20px;
                text-align: center;
                margin-bottom: 20px;
                border-radius: 8px;
            }
            
            .header-title {
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 5px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .header-subtitle {
                font-size: 12pt;
                opacity: 0.9;
            }
            
            .content-wrapper {
                display: flex;
                gap: 20px;
            }
            
            .main-content {
                flex: 2;
            }
            
            .sidebar {
                flex: 1;
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                height: fit-content;
            }
            
            .business-name {
                font-size: 22pt;
                font-weight: bold;
                color: #004D43;
                margin-bottom: 10px;
                border-bottom: 3px solid #6AA338;
                padding-bottom: 8px;
            }
            
            .business-type {
                background: #6AA338;
                color: white;
                padding: 5px 12px;
                border-radius: 15px;
                font-size: 9pt;
                font-weight: bold;
                display: inline-block;
                margin-bottom: 15px;
                text-transform: uppercase;
            }
            
            .contact-section {
                background: #f0f0f0;
                padding: 15px;
                border-radius: 6px;
                margin: 15px 0;
                border-left: 4px solid #6AA338;
            }
            
            .contact-item {
                margin-bottom: 8px;
                font-size: 11pt;
            }
            
            .contact-item:last-child {
                margin-bottom: 0;
            }
            
            .contact-label {
                font-weight: bold;
                color: #004D43;
                display: inline-block;
                width: 70px;
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
                text-align: justify;
            }
            
            .products-list {
                background: #f0f8ff;
                padding: 12px;
                border-radius: 6px;
                border: 1px solid #d6e9f0;
            }
            
            .certification-badges {
                margin: 15px 0;
            }
            
            .certification-badge {
                background: linear-gradient(135deg, #6AA338 0%%, #004D43 100%%);
                color: white;
                padding: 6px 12px;
                border-radius: 20px;
                font-size: 9pt;
                font-weight: bold;
                margin: 3px 5px 3px 0;
                display: inline-block;
                text-transform: uppercase;
            }
            
            .qr-section {
                text-align: center;
                padding: 15px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                margin-bottom: 15px;
            }
            
            .qr-title {
                font-size: 12pt;
                font-weight: bold;
                margin-bottom: 10px;
                color: #004D43;
            }
            
            .qr-image {
                width: 100px;
                height: 100px;
            }
            
            .location-info {
                background: white;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 10pt;
                text-align: center;
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
        
        <div class="content-wrapper">
            <div class="main-content">
                <div class="business-name">%s</div>
                
                %s
                
                <div class="contact-section">
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
            </div>
            
            <div class="sidebar">
                <div class="qr-section">
                    <div class="qr-title">Visit Online</div>
                    <img src="%s" alt="QR Code" class="qr-image">
                </div>
                
                <div class="location-info">
                    <strong>Location</strong><br>
                    %s
                </div>
            </div>
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
        !empty($data['location']) ? '<div class="contact-item"><span class="contact-label">Location:</span> ' . esc_html($data['location']) . '</div>' : '',
        !empty($data['email']) ? '<div class="contact-item"><span class="contact-label">Email:</span> ' . esc_html($data['email']) . '</div>' : '',
        !empty($data['phone']) ? '<div class="contact-item"><span class="contact-label">Phone:</span> ' . esc_html($data['phone']) . '</div>' : '',
        !empty($data['website']) ? '<div class="contact-item"><span class="contact-label">Website:</span> ' . esc_html($data['website']) . '</div>' : '',
        !empty($data['certifications']) ? '<div class="section"><div class="section-title">Certifications</div><div class="certification-badges">' . $this->format_certifications($data['certifications']) . '</div></div>' : '',
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
            $badges .= '<span class="certification-badge">' . esc_html(trim($cert)) . '</span>';
        }
        
        return $badges;
    }
}

?>