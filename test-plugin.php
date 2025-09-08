<?php
/**
 * Test script for Complete Listing PDF Generator Plugin
 * 
 * This script helps test the plugin functionality
 * Place this file in your WordPress root directory and access it via browser
 */

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

// Check if plugin is active
if (!class_exists('CompleteListingPDFPlugin')) {
    die('❌ Plugin not active or not loaded properly');
}

echo "<h1>🧪 PDF Generator Plugin Test</h1>";

// Test 1: Check if plugin class exists
echo "<h2>✅ Test 1: Plugin Class</h2>";
echo "CompleteListingPDFPlugin class exists: " . (class_exists('CompleteListingPDFPlugin') ? '✅ YES' : '❌ NO') . "<br>";
echo "SimpleListingPDFGenerator class exists: " . (class_exists('SimpleListingPDFGenerator') ? '✅ YES' : '❌ NO') . "<br>";

// Test 2: Check if user is logged in
echo "<h2>✅ Test 2: User Status</h2>";
if (is_user_logged_in()) {
    $user = wp_get_current_user();
    echo "✅ User logged in: " . $user->display_name . " (ID: " . $user->ID . ")<br>";
} else {
    echo "❌ No user logged in<br>";
}

// Test 3: Find listing posts
echo "<h2>✅ Test 3: Listing Posts</h2>";
$listing_types = array('listing', 'business', 'farm', 'directory');
$found_listings = array();

foreach ($listing_types as $post_type) {
    $posts = get_posts(array(
        'post_type' => $post_type,
        'numberposts' => 5,
        'post_status' => 'publish'
    ));
    
    if (!empty($posts)) {
        echo "✅ Found " . count($posts) . " posts of type: " . $post_type . "<br>";
        foreach ($posts as $post) {
            $found_listings[] = $post;
            echo "&nbsp;&nbsp;- " . $post->post_title . " (ID: " . $post->ID . ")<br>";
        }
    } else {
        echo "❌ No posts found for type: " . $post_type . "<br>";
    }
}

// Test 4: Check taxonomies
echo "<h2>✅ Test 4: Taxonomy Check</h2>";
$taxonomies = array('listing_type', 'listing_categories');
foreach ($taxonomies as $taxonomy) {
    if (taxonomy_exists($taxonomy)) {
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        echo "✅ Taxonomy '{$taxonomy}' exists with " . count($terms) . " terms<br>";
    } else {
        echo "❌ Taxonomy '{$taxonomy}' does not exist<br>";
    }
}

// Test 5: Test PDF generation (if user is logged in and listings exist)
if (is_user_logged_in() && !empty($found_listings)) {
    echo "<h2>✅ Test 5: PDF Generation Test</h2>";
    $test_post = $found_listings[0];
    echo "Testing PDF generation for: " . $test_post->post_title . "<br>";
    
    try {
        $pdf_generator = new SimpleListingPDFGenerator();
        $pdf_content = $pdf_generator->create_listing_pdf($test_post->ID);
        
        if ($pdf_content) {
            echo "✅ PDF generation successful! Size: " . strlen($pdf_content) . " bytes<br>";
        } else {
            echo "❌ PDF generation failed<br>";
        }
    } catch (Exception $e) {
        echo "❌ PDF generation error: " . $e->getMessage() . "<br>";
    }
}

// Test 6: Check required dependencies
echo "<h2>✅ Test 6: Dependencies</h2>";
echo "TCPDF class available: " . (class_exists('TCPDF') ? '✅ YES' : '❌ NO') . "<br>";
echo "Composer autoload: " . (file_exists('vendor/autoload.php') ? '✅ YES' : '❌ NO') . "<br>";

// Test 7: Check plugin hooks
echo "<h2>✅ Test 7: WordPress Hooks</h2>";
global $wp_filter;
$hooks_to_check = array(
    'wp_enqueue_scripts',
    'wp_ajax_generate_listing_pdf',
    'wp_ajax_nopriv_generate_listing_pdf',
    'wp_footer'
);

foreach ($hooks_to_check as $hook) {
    if (isset($wp_filter[$hook])) {
        echo "✅ Hook '{$hook}' is registered<br>";
    } else {
        echo "❌ Hook '{$hook}' is NOT registered<br>";
    }
}

echo "<h2>🎯 Next Steps</h2>";
echo "<ol>";
echo "<li>If all tests pass, visit a listing page as the listing owner</li>";
echo "<li>Look for the floating PDF button in the top-right corner</li>";
echo "<li>Click the button to test PDF generation</li>";
echo "<li>Check that non-owners don't see the button</li>";
echo "</ol>";

echo "<p><strong>Note:</strong> Delete this test file after testing for security reasons.</p>";
?>
