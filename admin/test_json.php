<?php
// Simple test file to verify JSON responses
header('Content-Type: application/json');

$test_data = [
    'success' => true,
    'message' => 'JSON test successful',
    'timestamp' => date('Y-m-d H:i:s'),
    'test' => 'This is a test response'
];

echo json_encode($test_data);
?>
