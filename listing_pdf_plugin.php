<?php
/**
 * Plugin Name: Complete Listing PDF Generator
 * Plugin URI: https://eatlocalfirst.org
 * Description: Generates PDFs for business listings with QR codes and contact information
 * Version: 1.1.0
 * Author: Eat Local First
 * License: GPL v2 or later
 * Text Domain: complete-listing-pdf
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('COMPLETE_LISTING_PDF_VERSION', '1.1.0');
define('COMPLETE_LISTING_PDF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('COMPLETE_LISTING_PDF_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * PDF Generator for Business Listings
 */
class SimpleListingPDFGenerator {

    private $field_map;

    public function __construct() {
        $this->field_map = array(
            'name' => 'post_title',
            'location' => 'listing_location',
            'address' => 'location_address',
            'email' => 'email',
            'phone' => 'phone',
            'website' => 'website',
            'about' => 'post_content',
            'business_type' => 'listing_type',
            'products' => 'listing_categories',
            'growing_practices' => 'farms_fish_growing_methods',
            'retail_info' => 'listing_retail_info',
            'csa_info' => 'listing_csa_info',
            'listing_features' => 'listing_features',
            'payment_methods' => 'listing_features',
            'wholesale_info' => 'wholesale_info',
        );
    }

    /**
     * Main PDF generation method
     */
    public function create_listing_pdf($post_id) {
        try {
            $data = $this->extract_data($post_id);
            $qr_code = $this->generate_qr_code(get_permalink($post_id));

            $this->load_tcpdf();

            $pdf = $this->create_pdf_instance($data['name']);
            $html = $this->build_html($data, $qr_code);
            $pdf->writeHTML($html, true, false, true, false, '');

            return $pdf->Output('', 'S');

        } catch (Exception $e) {
            error_log('PDF Generation Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load TCPDF library
     */
    private function load_tcpdf() {
        if (class_exists('TCPDF')) {
            return;
        }

        $tcpdf_paths = array(
            plugin_dir_path(__FILE__) . 'vendor/tecnickcom/tcpdf/tcpdf.php',
            plugin_dir_path(__FILE__) . '../vendor/tecnickcom/tcpdf/tcpdf.php',
            ABSPATH . 'vendor/tecnickcom/tcpdf/tcpdf.php'
        );

        foreach ($tcpdf_paths as $path) {
            if (file_exists($path)) {
                require_once($path);
                return;
            }
        }

        throw new Exception('TCPDF class not available');
    }

    /**
     * Create and configure PDF instance
     */
    private function create_pdf_instance($title) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Eat Local First Directory');
        $pdf->SetTitle($title . ' - Listing');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->setCellHeightRatio(1.0);
        $pdf->SetLineWidth(0.1);
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Extract data for PDF generation
     */
    private function extract_data($post_id) {
        $post = get_post($post_id);

        $data = array(
            'post_id' => $post_id,
            'name' => $post->post_title,
            'about' => wp_strip_all_tags($post->post_content),
            'url' => get_permalink($post_id),
            'hero_image' => $this->get_hero_image($post_id),
        );

        // Extract custom fields
        foreach ($this->field_map as $key => $field_name) {
            if (in_array($key, ['name', 'about'])) continue;

            if ($this->is_taxonomy($field_name)) {
                $data[$key] = $this->get_taxonomy_data($post_id, $field_name, $key);
            } else {
                $data[$key] = get_post_meta($post_id, $field_name, true) ?: '';
            }
        }

        // Try to extract wholesale information from various sources
        $wholesale_content = $this->extract_wholesale_content($post_id);
        if ($wholesale_content) {
            $data['wholesale_info'] = $wholesale_content;
        }

        return $data;
    }

    /**
     * Get hero image with fallback priority
     */
    private function get_hero_image($post_id) {
        $image_fields = array(
            'logo_images_primary_image',
            'logo_images_your_logo',
            'logo_images_additonal_images'
        );

        // Try primary and logo fields
        foreach (array_slice($image_fields, 0, 2) as $field) {
            $image_id = get_post_meta($post_id, $field, true);
            if ($image_id) {
                $image_url = wp_get_attachment_image_url($image_id, 'medium');
                if ($image_url) return $image_url;
            }
        }

        // Try featured image
        $featured_id = get_post_thumbnail_id($post_id);
        if ($featured_id) {
            $image_url = wp_get_attachment_image_url($featured_id, 'medium');
            if ($image_url) return $image_url;
        }

        // Try gallery first image
        $gallery_ids = get_post_meta($post_id, 'logo_images_additonal_images', true);
        if ($gallery_ids && is_array($gallery_ids) && !empty($gallery_ids[0])) {
            $image_url = wp_get_attachment_image_url($gallery_ids[0], 'medium');
            if ($image_url) return $image_url;
        }

        return false;
    }

    /**
     * Check if field is a taxonomy
     */
    private function is_taxonomy($field_name) {
        return in_array($field_name, array(
            'listing_type', 'listing_categories', 'listing_features'
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

        if ($key === 'payment_methods') {
            return $this->filter_payment_methods($terms);
        }

        if ($key === 'products') {
            return $this->filter_products($terms);
        }

        return implode(', ', wp_list_pluck($terms, 'name'));
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
     * Filter and categorize products
     */
    private function filter_products($products) {
        $categories = array();

        foreach ($products as $product) {
            // Skip unwanted categories
            if ($this->should_skip_product($product->name)) {
                continue;
            }

            $category = $this->determine_product_category($product->name);

            if (!isset($categories[$category])) {
                $categories[$category] = array();
            }
            $categories[$category][] = $product->name;
        }

        return $this->format_product_categories($categories);
    }

    /**
     * Check if product should be skipped
     */
    private function should_skip_product($product_name) {
        $skip_terms = array('locally raised', 'harvested', 'grown', 'services');

        foreach ($skip_terms as $term) {
            if (stripos($product_name, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine product category
     */
    private function determine_product_category($product_name) {
        $product_lower = strtolower($product_name);

        $category_patterns = array(
            'Eggs' => array('egg'),
            'Flowers, Nursery & Trees' => array('flower', 'nursery', 'tree'),
            'Grains & Pulses' => array('grain', 'pulse', 'bean', 'wheat'),
            'Seeds & Starts' => array('seed', 'start', 'plant'),
            'Meat & Poultry' => array('meat', 'poultry', 'beef', 'chicken'),
            'Dairy' => array('dairy', 'milk', 'cheese'),
            'Seafood' => array('seafood', 'fish'),
            'Fruit & Berries' => array('fruit', 'berr', 'apple'),
        );

        foreach ($category_patterns as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($product_lower, $pattern) !== false) {
                    return $category;
                }
            }
        }

        // Check for vegetables
        if ($this->is_vegetable($product_lower)) {
            return 'Vegetables & Herbs';
        }

        return 'Other Products';
    }

    /**
     * Check if product is a vegetable or herb
     */
    private function is_vegetable($product_lower) {
        $vegetables = array(
            'arugula', 'basil', 'beets', 'broccoli', 'cabbage', 'carrots', 'kale',
            'lettuce', 'onions', 'potatoes', 'tomatoes', 'asian greens', 'bok choy',
            'bell peppers', 'brussels sprouts', 'cauliflower', 'celeriac', 'chard',
            'chives', 'collard greens', 'corn', 'cucumbers', 'daikon', 'dill',
            'eggplant', 'escarole', 'garlic', 'herbs', 'kohlrabi', 'leeks',
            'mustard greens', 'oregano', 'parsley', 'parsnips', 'peas', 'peppers',
            'popcorn', 'pumpkins', 'radicchio', 'radishes', 'rhubarb', 'salad greens',
            'shallots', 'spinach', 'squash', 'sunchokes', 'thyme', 'tomatillos', 'turnips'
        );

        foreach ($vegetables as $vegetable) {
            if (stripos($product_lower, $vegetable) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format product categories for display
     */
    private function format_product_categories($categories) {
        $formatted_output = array();

        foreach ($categories as $category => $items) {
            $items_text = implode(', ', $items);
            $formatted_output[] = '<strong>' . $category . ':</strong> ' . $items_text;
        }

        return implode('<br>', $formatted_output);
    }


    /**
     * Extract wholesale content from various sources
     */
    private function extract_wholesale_content($post_id) {
        // Try multiple potential field names for wholesale info
        $wholesale_fields = array(
            'wholesale_info',
            'wholesale_details',
            'wholesale_products',
            'products_available_wholesale',
            'wholesale_information'
        );

        foreach ($wholesale_fields as $field) {
            $content = get_post_meta($post_id, $field, true);
            if (!empty($content) && is_string($content)) {
                return wp_strip_all_tags($content);
            }
        }

        // Check post content for wholesale sections
        $post_content = get_post_field('post_content', $post_id);
        if (stripos($post_content, 'wholesale') !== false) {
            // Try to extract wholesale-related content from post body
            return $this->extract_wholesale_from_content($post_content);
        }

        return '';
    }

    /**
     * Extract wholesale information from post content
     */
    private function extract_wholesale_from_content($content) {
        // Look for wholesale sections in the content
        if (preg_match('/wholesale[^.]*[.!]*/i', $content, $matches)) {
            return wp_strip_all_tags($matches[0]);
        }
        return '';
    }

    /**
     * Generate QR code with base64 encoding
     */
    private function generate_qr_code($url) {
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($url);

        $response = wp_remote_get($qr_url, array('timeout' => 10));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $image_data = wp_remote_retrieve_body($response);
            $base64 = base64_encode($image_data);
            return 'data:image/png;base64,' . $base64;
        }

        return $qr_url;
    }

    /**
     * Build the main HTML template
     */
    private function build_html($data, $qr_code) {
        $about_content = $this->format_about_content($data['about']);
        $content_section = $this->build_content_section($data, $about_content);

        return sprintf('
        %s

        <table style="width: 100%%; background-color: #004D43; border-radius: 5px; margin-bottom: 4px;">
            <tr>
                <td style="height: 60px; text-align: center; vertical-align: middle;">
                    <div style="color: white; font-size: 18pt; font-weight: bold; line-height: 1.2;">
                        %s
                    </div>
                </td>
            </tr>
        </table>

        <table style="width: 100%%; border-collapse: collapse; margin: 4px 0;">
            <tr>
                <td style="width: 65%%; vertical-align: top; padding-right: 20px;">
                    <div style="padding: 8px;">
                        <div style="font-weight: bold; color: #004D43; margin-bottom: 6px;">Contact Information</div>
                        %s
                    </div>
                </td>
                <td style="width: 35%%; vertical-align: top;">
                    <div style="text-align: center; padding: 8px;">
                        <div style="font-size: 10pt; margin-bottom: 6px;">Scan for more details</div>
                        <img src="%s" style="width: 80px; height: 80px;" alt="QR Code">
                    </div>
                </td>
            </tr>
        </table>

        %s<tcpdf method="Ln" params="3" />
        %s<tcpdf method="Ln" params="3" />
        %s<tcpdf method="Ln" params="3" />
        %s

        <div class="footer">
            <div class="website-url">%s</div>
            <div style="margin-top: 10px; font-size: 8pt;">
                Generated from %s
            </div>
        </div>',

        $this->get_css_styles(),
        esc_html($data['name']),
        $this->build_contact_info($data),
        $qr_code,
        $content_section,
        $this->build_products_section($data),
        $this->build_wholesale_section($data),
        $this->build_growing_practices_section($data),
        esc_html($data['website'] ?: $data['url']),
        esc_html($data['url'])
        );
    }

    /**
     * Format about content with truncation
     */
    private function format_about_content($about) {
        if (empty($about)) {
            return '<span style="color: #999; font-style: italic;">No information available</span>';
        }

        $truncated = wp_trim_words($about, 100);

        if (strlen($truncated) < strlen($about)) {
            return nl2br(esc_html($truncated)) . ' Scan the QR code to learn more.';
        }

        return nl2br(esc_html($truncated));
    }

    /**
     * Build content section with image
     */
    private function build_content_section($data, $about_content) {
        if ($data['hero_image']) {
            return sprintf('
            <div style="margin: 2pt 0;">
                <table style="width: 100%%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 60%%; vertical-align: top; padding-right: 12px; text-align: left;">
                            <div class="section-title" style="margin-bottom: 6px;">About Us</div>
                            <div class="section-content" style="text-align: left; line-height: 1.3;">
                                %s
                            </div>
                        </td>
                        <td style="width: 40%%; text-align: center; padding-left: 12px; vertical-align: top;">
                            <div style="padding-top: 25px;">
                                <img src="%s" width="150" height="120" alt="Business Photo">
                            </div>
                        </td>
                    </tr>
                </table>
            </div>',
            $about_content,
            esc_url($data['hero_image']));
        }

        return sprintf('
        <div style="margin: 2pt 0;">
            <div class="section-title">About Us</div>
            <div class="section-content" style="text-align: left; line-height: 1.3;">
                %s
            </div>
        </div>',
        $about_content);
    }

    /**
     * Build contact information section
     */
    private function build_contact_info($data) {
        $contact_fields = array(
            'location' => 'Location',
            'email' => 'Email',
            'phone' => 'Phone',
            'website' => 'Website'
        );

        $contact_html = '';
        foreach ($contact_fields as $field => $label) {
            if (!empty($data[$field])) {
                $contact_html .= sprintf(
                    '<div class="contact-item"><span class="contact-label">%s:</span> %s</div>',
                    $label,
                    esc_html($data[$field])
                );
            }
        }

        return $contact_html;
    }

    /**
     * Build products section
     */
    private function build_products_section($data) {
        if (empty($data['products'])) {
            return '';
        }

        return sprintf(
            '<div class="section"><div class="section-title">Products & Services</div><div class="section-content products-list">%s</div></div>',
            $data['products']
        );
    }

    /**
     * Build wholesale section
     */
    private function build_wholesale_section($data) {
        $wholesale_content = !empty($data['wholesale_info']) ?
            nl2br(esc_html($data['wholesale_info'])) :
            'Contact us for wholesale products or scan the QR code for more details.';

        return sprintf(
            '<div class="section"><div class="section-title">Wholesale</div><div class="section-content">%s</div></div>',
            $wholesale_content
        );
    }

    /**
     * Build growing practices section
     */
    private function build_growing_practices_section($data) {
        if (empty($data['growing_practices'])) {
            return '';
        }

        return sprintf(
            '<div class="section"><div class="section-title">Growing Practices</div><div class="section-content">%s</div></div>',
            nl2br(esc_html($data['growing_practices']))
        );
    }

    /**
     * Get CSS styles for PDF
     */
    private function get_css_styles() {
        return '
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
                margin-bottom: 4px;
                text-align: center;
                padding-top: 40px;
                padding-bottom: 40px;
                padding-left: 20px;
                padding-right: 20px;
                min-height: 60px;
                border-radius: 5px;
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

            .section-title {
                font-size: 12pt;
                font-weight: bold;
                color: #004D43;
            }

            .section-content {
                font-size: 10pt;
                line-height: 1.1;
            }

            .products-list {
                padding: 6px;
            }

            .qr-title {
                font-size: 11pt;
                font-weight: bold;
                margin-bottom: 6px;
                color: #004D43;
            }

            .footer {
                margin-top: 8px;
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
        </style>';
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
        add_action('wp_footer', array($this, 'add_pdf_button_via_javascript'));
    }

    /**
     * Enqueue scripts for PDF button
     */
    public function enqueue_scripts() {
        if (is_singular() && $this->is_listing_post_type()) {
            wp_enqueue_script('jquery');
        }
    }

    /**
     * Check if current post can have PDF generated
     */
    private function is_listing_post_type() {
        $post = get_post();
        return $post && $post->post_status === 'publish';
    }

    /**
     * Check if user can generate PDF
     */
    private function user_can_generate_pdf($post_id = null) {
        if (!is_user_logged_in()) {
            return false;
        }

        if (current_user_can('manage_options') || current_user_can('edit_posts')) {
            return true;
        }

        $post_id = $post_id ?: get_the_ID();
        if (!$post_id) {
            return false;
        }

        $post = get_post($post_id);
        return $post && $post->post_author == get_current_user_id();
    }

    /**
     * Add PDF button via JavaScript
     */
    public function add_pdf_button_via_javascript() {
        if (!is_singular() || !$this->is_listing_post_type() || !$this->user_can_generate_pdf()) {
            return;
        }

        $post_id = get_the_ID();
        $nonce = wp_create_nonce('generate_pdf_nonce');
        $ajax_url = admin_url('admin-ajax.php');

        echo $this->get_pdf_button_styles();
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var pdfButton = $('<div class="pdf-generator-wrapper"><button type="button" id="generate-pdf-btn" class="pdf-generator-btn" data-post-id="<?php echo esc_js($post_id); ?>">📄 Download PDF</button></div>');

            // Try to place button next to edit button first, then fallback locations
            var inserted = false;

            // Priority 1: Look for edit button locations
            var editSelectors = [
                '.entry-meta .edit-link',
                '.post-edit-link',
                '.edit-post-link',
                '.entry-footer .edit-link',
                '.listing-actions',
                '.post-actions',
                '[class*="edit"]'
            ];

            for (var i = 0; i < editSelectors.length && !inserted; i++) {
                var editElement = $(editSelectors[i]);
                if (editElement.length > 0) {
                    editElement.last().after(pdfButton);
                    inserted = true;
                    break;
                }
            }

            // Priority 2: Fallback to content areas
            if (!inserted) {
                var contentSelectors = [
                    '.entry-content',
                    '.post-content',
                    '.listing-content',
                    'article .content',
                    'main article',
                    '.single-post article'
                ];

                for (var i = 0; i < contentSelectors.length && !inserted; i++) {
                    var contentElement = $(contentSelectors[i]);
                    if (contentElement.length > 0) {
                        contentElement.last().after(pdfButton);
                        inserted = true;
                        break;
                    }
                }
            }

            if (!inserted) {
                $('footer').first().before(pdfButton);
            }

            // Handle button click
            $(document).on('click', '#generate-pdf-btn', function(e) {
                e.preventDefault();

                var button = $(this);
                var originalText = button.text();

                button.prop('disabled', true).text('Generating...');

                var form = $('<form>', {
                    method: 'POST',
                    action: '<?php echo $ajax_url; ?>',
                    target: '_blank'
                });

                form.append($('<input>', { type: 'hidden', name: 'action', value: 'generate_listing_pdf' }));
                form.append($('<input>', { type: 'hidden', name: 'post_id', value: '<?php echo $post_id; ?>' }));
                form.append($('<input>', { type: 'hidden', name: 'nonce', value: '<?php echo $nonce; ?>' }));

                $('body').append(form);
                form.submit();
                form.remove();

                setTimeout(function() {
                    button.prop('disabled', false).text(originalText);
                }, 2000);
            });
        });
        </script>
        <?php
    }

    /**
     * Get CSS styles for PDF button
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
            }

            .pdf-generator-btn:hover {
                background: linear-gradient(135deg, #6AA338 0%, #004D43 100%);
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0, 77, 67, 0.3);
            }

            .pdf-generator-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
                background: #999 !important;
            }
        </style>";
    }

    /**
     * Handle PDF generation AJAX request
     */
    public function handle_pdf_generation() {
        try {
            // Validate request
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'generate_pdf_nonce')) {
                wp_die('Security check failed');
            }

            $post_id = intval($_POST['post_id']);
            if (!$post_id || !get_post($post_id)) {
                wp_die('Invalid post ID');
            }

            if (!$this->user_can_generate_pdf($post_id)) {
                wp_die('Permission denied');
            }

            // Generate PDF
            $pdf_content = $this->pdf_generator->create_listing_pdf($post_id);

            if (!$pdf_content) {
                wp_die('Failed to generate PDF');
            }

            // Send PDF
            $post_title = get_the_title($post_id);
            $filename = sanitize_file_name($post_title . '_listing.pdf');

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf_content));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            echo $pdf_content;
            exit;

        } catch (Exception $e) {
            error_log('PDF Generation Exception: ' . $e->getMessage());
            wp_die('PDF Generation Error: ' . $e->getMessage());
        }
    }
}

// Initialize the plugin
new CompleteListingPDFPlugin();
?>