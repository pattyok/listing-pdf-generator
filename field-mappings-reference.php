<?php
/**
 * Field Mappings Reference for ELF Listing PDF Generator
 * 
 * This file documents all field mappings used in the PDF generation system
 * for the atlas_listing post type on Eat Local First website.
 * 
 * Last Updated: 2025-09-09
 */

// =============================================================================
// MAIN FIELD MAPPINGS
// =============================================================================

/**
 * Primary field mapping array used in SimpleListingPDFGenerator
 * Maps PDF template variables to WordPress custom field names
 */
$field_map = array(
    // Basic Information
    'name' => 'post_title',                    // WordPress post title
    'about' => 'post_content',                 // WordPress post content
    
    // Contact Information
    'location' => 'listing_location',          // Location/city field
    'address' => 'location_address',           // Full street address
    'email' => 'email',                        // Contact email
    'phone' => 'phone',                        // Contact phone
    'website' => 'website',                    // Business website URL
    
    // Business Details
    'growing_practices' => 'farms_fish_growing_methods', // How they grow/produce
    'retail_info' => 'listing_retail_info',    // Retail sales information
    'wholesale_info' => 'listing_wholesale_info', // Wholesale information  
    'csa_info' => 'listing_csa_info',         // CSA program details
    
    // Taxonomy Fields (handled separately)
    'business_type' => 'listing_type',         // Business category taxonomy
    'products' => 'listing_categories',        // Products/services taxonomy
    'certifications' => 'values_indicator',    // Certifications taxonomy
    'payment_methods' => 'listing_features',   // Features taxonomy (filtered)
);

// =============================================================================
// IMAGE FIELD MAPPINGS  
// =============================================================================

/**
 * Image field priority order for hero image selection
 * Uses wp_get_attachment_image_url() with attachment IDs
 */
$image_fields = array(
    // Priority 1: Primary business image
    'logo_images_primary_image',        // Main business photo/logo
    
    // Priority 2: WordPress featured image
    // get_post_thumbnail_id($post_id) - WordPress standard
    
    // Priority 3: Logo field fallback  
    'logo_images_your_logo',            // Business logo image
    
    // Priority 4: Additional gallery images
    'logo_images_additonal_images',     // Array of additional image IDs
);

// =============================================================================
// TAXONOMY MAPPINGS
// =============================================================================

/**
 * Taxonomy fields that require special handling via get_the_terms()
 */
$taxonomy_fields = array(
    'listing_type',        // Business type (Farm, Market, Restaurant, etc.)
    'listing_categories',  // Products/Services categories  
    'values_indicator',    // Certifications (Organic, Local, etc.)
    'listing_features',    // Features (includes payment methods)
);

/**
 * Payment method filtering keywords for listing_features taxonomy
 * Used to extract only payment-related terms from features
 */
$payment_keywords = array(
    'cash', 'check', 'card', 'venmo', 'snap', 'ebt', 'wic'
);

// =============================================================================
// FIELD USAGE IN PDF TEMPLATE
// =============================================================================

/**
 * How each field appears in the generated PDF:
 * 
 * HEADER SECTION:
 * - name: Large business name title
 * - business_type: Colored badge below name
 * 
 * CONTACT SECTION (Left column - 75%):
 * - location: "Location: [value]"  
 * - email: "Email: [value]"
 * - phone: "Phone: [value]"
 * - website: "Website: [value]"
 * 
 * CONTENT SECTIONS:
 * - certifications: Color-coded badges
 * - products: Styled list in blue box
 * - about: Trimmed to 100 words
 * - growing_practices: Full text section
 * - retail_info: Full text section  
 * - payment_methods: Comma-separated list
 * 
 * SIDEBAR (Right column - 25%):
 * - QR code linking to post URL
 * - location/address for contact
 * 
 * FOOTER:
 * - website: Main website link
 * - Post last modified date
 */

// =============================================================================
// DEBUGGING REFERENCE
// =============================================================================

/**
 * Common field issues and solutions:
 * 
 * ISSUE: Empty contact fields in PDF
 * SOLUTION: Verify field names match exactly (case-sensitive)
 *          Check if fields use 'email' not 'listing_email'
 * 
 * ISSUE: No hero image showing  
 * SOLUTION: Check image field names and verify attachment IDs exist
 *          Ensure images are accessible via wp_get_attachment_image_url()
 * 
 * ISSUE: Taxonomy data not showing
 * SOLUTION: Verify taxonomy names and check if terms are assigned to post
 *          Use get_the_terms() debugging in WordPress
 * 
 * ISSUE: Payment methods not filtering correctly
 * SOLUTION: Check payment_keywords array and term names in listing_features
 */

/**
 * Debug commands for field verification:
 * 
 * // Check custom field value
 * $value = get_post_meta($post_id, 'email', true);
 * 
 * // Check taxonomy terms
 * $terms = get_the_terms($post_id, 'listing_type');
 * 
 * // Check image attachment
 * $image_id = get_post_meta($post_id, 'logo_images_primary_image', true);
 * $image_url = wp_get_attachment_image_url($image_id, 'medium');
 * 
 * // Check post type
 * $post_type = get_post_type($post_id); // Should be 'atlas_listing'
 */

?>