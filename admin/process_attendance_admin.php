<?php
session_start();
header('Content-Type: application/json');
require_once '../db.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

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
    
    if (!isset($qr_info['user_id']) || !isset($qr_info['payment_id']) || 
        !isset($qr_info['plan_duration']) || !isset($qr_info['timestamp']) || 
        !isset($qr_info['hash'])) {
        throw new Exception('Invalid QR code data');
    }
    
    // Verify hash
    $expected_hash = hash('sha256', 
        $qr_info['user_id'] . 
        $qr_info['payment_id'] . 
        $qr_info['plan_duration'] . 
        $qr_info['timestamp'] . 
        'ALMO_FITNESS_SECRET'
    );
    
    if ($qr_info['hash'] !== $expected_hash) {
        throw new Exception('Invalid QR code signature');
    }
    
    // Check if QR code is not expired (valid for 5 minutes)
    if (time() - $qr_info['timestamp'] > 300) {
        throw new Exception('QR code has expired. Please generate a new one.');
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Verify active membership
        $verify_sql = "SELECT ph.payment_status, mp.duration, mp.name as plan_name,
                             DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date,
                             u.username, u.email, u.full_name
                      FROM payment_history ph
                      INNER JOIN users u ON ph.user_id = u.id
                      INNER JOIN membership_plans mp ON u.selected_plan_id = mp.id
                      WHERE ph.user_id = ? AND ph.id = ? 
                      AND ph.payment_status = 'Approved'
                      AND mp.duration >= 30
                      AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) >= CURDATE()";
        
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $qr_info['user_id'], $qr_info['payment_id']);
        $verify_stmt->execute();
        $result = $verify_stmt->get_result();
        $membership = $result->fetch_assoc();
        
        if (!$membership) {
            throw new Exception('Invalid or expired membership. Member must have an approved monthly or annual plan.');
        }
        
        // Check if membership is still valid
        if (strtotime($membership['expiry_date']) < time()) {
            throw new Exception('Membership has expired. Please renew the membership plan.');
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
        $attendance_sql = "INSERT INTO attendance (user_id, check_in_time, plan_id, payment_id) 
                          VALUES (?, NOW(), ?, ?)";
        $attendance_stmt = $conn->prepare($attendance_sql);
        $attendance_stmt->bind_param("iii", 
            $qr_info['user_id'], 
            $membership['plan_id'], 
            $qr_info['payment_id']
        );
        
        if (!$attendance_stmt->execute()) {
            throw new Exception('Failed to record attendance');
        }
        
        // Get the inserted attendance ID
        $attendance_id = $conn->insert_id;
        
        // Commit transaction
        $conn->commit();
        
        // Prepare response data
        $display_name = $membership['full_name'] ?? $membership['username'] ?? $membership['email'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Attendance recorded successfully',
            'data' => [
                'attendance_id' => $attendance_id,
                'user' => $display_name,
                'check_in_time' => date('Y-m-d H:i:s'),
                'plan' => $membership['plan_name'],
                'expiry_date' => $membership['expiry_date']
            ]
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?> 