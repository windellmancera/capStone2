<?php
header('Content-Type: application/json');
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['qr_data'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing QR data']);
    exit();
}

try {
    // Decode QR data
    $qr_info = json_decode($data['qr_data'], true);
    
    if (!isset($qr_info['user_id']) || !isset($qr_info['payment_id']) || !isset($qr_info['plan_duration'])) {
        throw new Exception('Invalid QR code data');
    }
    
    // Verify active membership
    $verify_sql = "SELECT ph.payment_status, mp.duration, mp.name as plan_name,
                         DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date
                  FROM payment_history ph
                  INNER JOIN membership_plans mp ON ph.plan_id = mp.id
                  WHERE ph.user_id = ? AND ph.id = ? 
                  AND ph.payment_status = 'Approved'
                  AND mp.duration >= 30"; // Only allow monthly and annual plans
    
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $qr_info['user_id'], $qr_info['payment_id']);
    $verify_stmt->execute();
    $result = $verify_stmt->get_result();
    $membership = $result->fetch_assoc();
    
    if (!$membership) {
        throw new Exception('Invalid or expired membership. Please ensure your payment has been approved and you have an active monthly or annual plan.');
    }
    
    // Check if membership is still valid
    if (strtotime($membership['expiry_date']) < time()) {
        throw new Exception('Membership has expired');
    }
    
    // Check for duplicate check-in within the last hour
    $duplicate_sql = "SELECT id FROM attendance 
                     WHERE user_id = ? 
                     AND check_in_time >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $duplicate_stmt = $conn->prepare($duplicate_sql);
    $duplicate_stmt->bind_param("i", $qr_info['user_id']);
    $duplicate_stmt->execute();
    
    if ($duplicate_stmt->get_result()->num_rows > 0) {
        throw new Exception('Already checked in within the last hour');
    }
    
    // Record attendance
    $attendance_sql = "INSERT INTO attendance (user_id, check_in_time, plan_id) 
                      SELECT ?, NOW(), plan_id 
                      FROM payment_history 
                      WHERE id = ?";
    $attendance_stmt = $conn->prepare($attendance_sql);
    $attendance_stmt->bind_param("ii", $qr_info['user_id'], $qr_info['payment_id']);
    
    if (!$attendance_stmt->execute()) {
        throw new Exception('Failed to record attendance');
    }
    
    // Get user information
    $user_sql = "SELECT username, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $qr_info['user_id']);
    $user_stmt->execute();
    $user = $user_stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'message' => 'Attendance recorded successfully',
        'data' => [
            'user' => $user['username'] ?? $user['email'],
            'check_in_time' => date('Y-m-d H:i:s'),
            'plan' => $membership['plan_name'],
            'expiry_date' => $membership['expiry_date']
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?> 