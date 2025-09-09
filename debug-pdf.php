<?php
/**
 * PDF Debug Viewer
 * 
 * Place this file in your WordPress root directory and access it via browser
 * Shows PDF generation logs and helps diagnose issues
 */

// Load WordPress
require_once('wp-config.php');
require_once('wp-load.php');

// Security check - only allow admins
if (!current_user_can('manage_options')) {
    die('Access denied. Admin privileges required.');
}

echo "<h1>🐞 PDF Generator Debug Viewer</h1>";

// Check if plugin is active
if (!class_exists('CompleteListingPDFPlugin')) {
    echo "<p>❌ PDF Plugin not active</p>";
    exit;
}

// Function to find error logs
function find_error_logs() {
    $possible_logs = array();
    
    // WordPress debug log
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $debug_log = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log)) {
            $possible_logs['WordPress Debug Log'] = $debug_log;
        }
    }
    
    // Common server locations
    $server_logs = array(
        'Root error_log' => ABSPATH . 'error_log',
        'wp-content error_log' => WP_CONTENT_DIR . '/error_log',
        'Plugin error_log' => plugin_dir_path(__FILE__) . 'error_log',
    );
    
    foreach ($server_logs as $name => $path) {
        if (file_exists($path)) {
            $possible_logs[$name] = $path;
        }
    }
    
    return $possible_logs;
}

// Show error log locations
echo "<h2>📂 Error Log Locations</h2>";
$logs = find_error_logs();
if (empty($logs)) {
    echo "<p>❌ No error logs found in common locations</p>";
    echo "<p>Check your hosting control panel for error logs</p>";
} else {
    foreach ($logs as $name => $path) {
        echo "<p>✅ <strong>$name:</strong> $path</p>";
    }
}

// Show recent PDF-related errors
echo "<h2>🔍 Recent PDF Generation Logs</h2>";
foreach ($logs as $name => $path) {
    echo "<h3>$name</h3>";
    if (filesize($path) > 1024 * 1024) { // If file > 1MB
        echo "<p><em>Log file is large, showing last 50 lines:</em></p>";
        $lines = file($path);
        $recent_lines = array_slice($lines, -50);
    } else {
        $recent_lines = file($path);
    }
    
    $pdf_lines = array();
    foreach ($recent_lines as $line) {
        if (strpos($line, 'PDF Generation') !== false) {
            $pdf_lines[] = htmlspecialchars($line);
        }
    }
    
    if (empty($pdf_lines)) {
        echo "<p><em>No PDF generation logs found</em></p>";
    } else {
        echo "<pre style='background: #f5f5f5; padding: 10px; overflow-x: auto;'>";
        echo implode('', array_slice($pdf_lines, -20)); // Show last 20 PDF log lines
        echo "</pre>";
    }
    echo "<hr>";
}

// Test PDF generation button
if (isset($_GET['test_pdf'])) {
    echo "<h2>🧪 Testing PDF Generation</h2>";
    
    // Find a post to test with
    $test_post = get_posts(array('numberposts' => 1, 'post_status' => 'publish'));
    if (empty($test_post)) {
        echo "<p>❌ No published posts found for testing</p>";
    } else {
        $post_id = $test_post[0]->ID;
        echo "<p>Testing with post: <strong>" . $test_post[0]->post_title . "</strong> (ID: $post_id)</p>";
        
        try {
            $pdf_generator = new SimpleListingPDFGenerator();
            echo "<p>✅ PDF Generator class created</p>";
            
            // Enable error display for this test
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
            
            $pdf_content = $pdf_generator->create_listing_pdf($post_id);
            
            if ($pdf_content) {
                echo "<p>✅ PDF generated successfully! Size: " . strlen($pdf_content) . " bytes</p>";
                echo "<p><a href='?download_test_pdf=$post_id'>Download Test PDF</a></p>";
            } else {
                echo "<p>❌ PDF generation failed</p>";
            }
        } catch (Exception $e) {
            echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        }
    }
}

// Download test PDF
if (isset($_GET['download_test_pdf'])) {
    $post_id = intval($_GET['download_test_pdf']);
    $pdf_generator = new SimpleListingPDFGenerator();
    $pdf_content = $pdf_generator->create_listing_pdf($post_id);
    
    if ($pdf_content) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="test-pdf.pdf"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }
}

echo "<h2>🎯 Actions</h2>";
echo "<p><a href='?test_pdf=1' style='background: #0073aa; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px;'>🧪 Test PDF Generation</a></p>";
echo "<p><a href='" . $_SERVER['PHP_SELF'] . "' style='background: #666; color: white; padding: 10px 15px; text-decoration: none; border-radius: 3px;'>🔄 Refresh Logs</a></p>";

echo "<h2>💡 WordPress Debug Settings</h2>";
echo "<p><strong>WP_DEBUG:</strong> " . (defined('WP_DEBUG') && WP_DEBUG ? '✅ Enabled' : '❌ Disabled') . "</p>";
echo "<p><strong>WP_DEBUG_LOG:</strong> " . (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? '✅ Enabled' : '❌ Disabled') . "</p>";
echo "<p><strong>WP_DEBUG_DISPLAY:</strong> " . (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY ? '✅ Enabled' : '❌ Disabled') . "</p>";

if (!defined('WP_DEBUG') || !WP_DEBUG) {
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0;'>";
    echo "<p><strong>⚠️ Recommendation:</strong> Enable WordPress debugging by adding these lines to wp-config.php:</p>";
    echo "<pre>define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);</pre>";
    echo "</div>";
}

echo "<p><em>Delete this debug file after troubleshooting for security.</em></p>";
?>