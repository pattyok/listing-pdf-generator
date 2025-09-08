<?php
/**
 * Simple TCPDF installer for the PDF plugin
 * Run this script once to download TCPDF
 */

// Create vendor directory
$vendor_dir = __DIR__ . '/vendor/tecnickcom/tcpdf';
if (!file_exists($vendor_dir)) {
    mkdir($vendor_dir, 0755, true);
}

// Download TCPDF
$tcpdf_url = 'https://github.com/tecnickcom/TCPDF/archive/refs/tags/6.6.2.zip';
$zip_file = __DIR__ . '/tcpdf.zip';

echo "Downloading TCPDF...\n";
$zip_content = file_get_contents($tcpdf_url);
if ($zip_content === false) {
    die("Failed to download TCPDF\n");
}

file_put_contents($zip_file, $zip_content);

// Extract ZIP
$zip = new ZipArchive;
if ($zip->open($zip_file) === TRUE) {
    $zip->extractTo(__DIR__ . '/temp_tcpdf');
    $zip->close();
    
    // Move files to correct location
    $extracted_dir = __DIR__ . '/temp_tcpdf/TCPDF-6.6.2';
    if (is_dir($extracted_dir)) {
        // Copy all files from extracted directory to vendor directory
        shell_exec("cp -r '$extracted_dir'/* '$vendor_dir'");
        
        // Clean up
        shell_exec("rm -rf " . __DIR__ . "/temp_tcpdf");
        unlink($zip_file);
        
        echo "TCPDF installed successfully!\n";
        echo "Main file: $vendor_dir/tcpdf.php\n";
    } else {
        die("Extraction failed - directory not found\n");
    }
} else {
    die("Failed to extract ZIP file\n");
}
?>