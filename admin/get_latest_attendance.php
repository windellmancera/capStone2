<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../db.php';

try {
    // Get the last check time from the request
    $last_check = isset($_GET['last_check']) ? $_GET['last_check'] : date('Y-m-d H:i:s', strtotime('-1 minute'));
    
    // Check for new attendance records since the last check
    $sql = "SELECT COUNT(*) as new_records FROM attendance 
            WHERE check_in_time > ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $last_check);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    $hasNewRecords = $result['new_records'] > 0;
    
    // Get the latest attendance record timestamp
    $latest_sql = "SELECT MAX(check_in_time) as latest_time FROM attendance";
    $latest_result = $conn->query($latest_sql)->fetch_assoc();
    $latest_time = $latest_result['latest_time'] ?? date('Y-m-d H:i:s');
    
    echo json_encode([
        'success' => true,
        'hasNewRecords' => $hasNewRecords,
        'newRecordsCount' => $result['new_records'],
        'latestTime' => $latest_time,
        'lastCheck' => $last_check
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?> 