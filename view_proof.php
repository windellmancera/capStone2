<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Get the filename from the URL
$filename = $_GET['file'] ?? '';

// Validate filename to prevent directory traversal
if (empty($filename) || strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
    http_response_code(404);
    exit('File not found');
}

// Check if file exists in the payment_proofs directory
$file_path = "uploads/payment_proofs/" . $filename;

if (!file_exists($file_path)) {
    http_response_code(404);
    exit('File not found: ' . $file_path);
}

// Get file info
$file_info = pathinfo($file_path);
$extension = strtolower($file_info['extension']);

// Only allow image files
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp'];
if (!in_array($extension, $allowed_extensions)) {
    http_response_code(403);
    exit('Invalid file type');
}

// Set appropriate headers
header('Content-Type: image/' . $extension);
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: public, max-age=3600');

// Output the file
readfile($file_path);
?> 