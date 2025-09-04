<?php
session_start();

// Debug logging
error_log("view_payment_proof.php called with file: " . ($_GET['file'] ?? 'none'));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    error_log("Access denied: User not logged in");
    http_response_code(403);
    exit('Access denied');
}

// Database connection
require_once '../db.php';

if (!isset($_GET['file'])) {
    error_log("No file specified");
    http_response_code(400);
    exit('No file specified');
}

$filename = $_GET['file'];
$user_id = $_SESSION['user_id'];

error_log("Processing file: $filename for user: $user_id");

// Validate filename (only allow alphanumeric, underscore, dash, and dot)
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    error_log("Invalid filename: $filename");
    http_response_code(400);
    exit('Invalid filename');
}

// Check if the payment belongs to the current user
$sql = "SELECT proof_of_payment FROM payment_history WHERE proof_of_payment = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $filename, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    error_log("Access denied: Payment not found for user $user_id, file $filename");
    http_response_code(403);
    exit('Access denied');
}

$file_path = "../uploads/payment_proofs/" . $filename;

if (!file_exists($file_path)) {
    error_log("File not found: $file_path");
    http_response_code(404);
    exit('File not found');
}

error_log("Serving file: $file_path");

// Get file info
$file_info = pathinfo($file_path);
$extension = strtolower($file_info['extension']);

// Set appropriate content type
$content_types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif'
];

if (!isset($content_types[$extension])) {
    error_log("Invalid file type: $extension");
    http_response_code(400);
    exit('Invalid file type');
}

// Set headers
header('Content-Type: ' . $content_types[$extension]);
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: public, max-age=3600');

error_log("Outputting file with content-type: " . $content_types[$extension]);

// Output the file
readfile($file_path);
?> 