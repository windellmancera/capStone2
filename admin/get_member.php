<?php
// Prevent any output before headers
ob_start();

// Start session
session_start();

// Set error reporting to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);

// Set JSON header immediately
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if member ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
    exit();
}

$member_id = intval($_GET['id']);

try {
    // Include database connection
    if (!file_exists('../db.php')) {
        throw new Exception('Database configuration file not found');
    }
    
    require_once '../db.php';
    
    // Check if database connection is successful
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }
    
    // Test database connection
    if (!$conn->ping()) {
        throw new Exception('Database connection lost');
    }
    
    // Get comprehensive member details
    $member_query = "
        SELECT 
            u.*,
            mp.name as plan_name,
            mp.duration as plan_duration,
            mp.price as plan_price,
            (
                SELECT payment_status FROM payment_history 
                WHERE user_id = u.id 
                ORDER BY payment_date DESC LIMIT 1
            ) as latest_payment_status,
            (
                SELECT payment_date FROM payment_history 
                WHERE user_id = u.id 
                ORDER BY payment_date DESC LIMIT 1
            ) as latest_payment_date,
            (
                SELECT COUNT(*) FROM attendance WHERE user_id = u.id
            ) as attendance_count,
            (
                SELECT COUNT(*) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved'
            ) as payment_count
        FROM users u
        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
        WHERE u.id = ? AND u.role = 'member'
    ";
    
    $stmt = $conn->prepare($member_query);
    if (!$stmt) {
        throw new Exception('Failed to prepare member query: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $member_id);
    if (!$stmt->execute()) {
        throw new Exception('Failed to execute member query: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception('Failed to get result set: ' . $stmt->error);
    }
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Member not found']);
        exit();
    }
    
    $member = $result->fetch_assoc();
    if (!$member) {
        throw new Exception('Failed to fetch member data');
    }
    
    // Calculate membership status
    $today = date('Y-m-d');
    $membership_status = 'Unknown';
    $expiration_date = 'N/A';
    
    // Calculate expiry date
    if ($member['membership_end_date']) {
        $expiration_date = $member['membership_end_date'];
        if ($expiration_date <= $today) {
            $membership_status = 'Expired';
        } else {
            $membership_status = 'Active';
        }
    } elseif ($member['latest_payment_status'] === 'Approved' && 
              $member['latest_payment_date'] && 
              $member['plan_duration']) {
        $calculated_expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
        $expiration_date = $calculated_expiry;
        if ($calculated_expiry <= $today) {
            $membership_status = 'Expired';
        } else {
            $membership_status = 'Active';
        }
    } elseif ($member['selected_plan_id'] && $member['latest_payment_status'] === 'Approved') {
        $membership_status = 'Active';
        $expiration_date = 'Ongoing';
    } elseif ($member['selected_plan_id']) {
        $membership_status = 'Pending';
        $expiration_date = 'N/A';
    } else {
        $membership_status = 'No Plan';
        $expiration_date = 'N/A';
    }
    
    // Get recent attendance records
    $attendance_records = [];
    try {
        $attendance_query = "
            SELECT 
                DATE(check_in_time) as check_date,
                TIME(check_in_time) as check_time
            FROM attendance 
            WHERE user_id = ? 
            ORDER BY check_in_time DESC 
            LIMIT 10
        ";
        
        $attendance_stmt = $conn->prepare($attendance_query);
        if ($attendance_stmt) {
            $attendance_stmt->bind_param("i", $member_id);
            $attendance_stmt->execute();
            $attendance_result = $attendance_stmt->get_result();
            
            if ($attendance_result) {
                while ($attendance = $attendance_result->fetch_assoc()) {
                    $attendance_records[] = date('M d, Y', strtotime($attendance['check_date'])) . ' at ' . 
                                           date('g:i A', strtotime($attendance['check_time']));
                }
            }
            $attendance_stmt->close();
        }
    } catch (Exception $e) {
        // Attendance query failed, continue with empty records
        error_log("Attendance query failed: " . $e->getMessage());
    }
    
    $attendance_record = !empty($attendance_records) ? implode('<br>', $attendance_records) : 'No attendance records';
    
    // Get payment history
    $payment_history = [];
    try {
        $payment_query = "
            SELECT 
                amount,
                payment_date,
                payment_status,
                payment_method
            FROM payment_history 
            WHERE user_id = ? 
            ORDER BY payment_date DESC 
            LIMIT 5
        ";
        
        $payment_stmt = $conn->prepare($payment_query);
        if ($payment_stmt) {
            $payment_stmt->bind_param("i", $member_id);
            $payment_stmt->execute();
            $payment_result = $payment_stmt->get_result();
            
            if ($payment_result) {
                while ($payment = $payment_result->fetch_assoc()) {
                    $payment_history[] = [
                        'amount' => $payment['amount'],
                        'date' => date('M d, Y', strtotime($payment['payment_date'])),
                        'status' => $payment['payment_status'],
                        'method' => $payment['payment_method']
                    ];
                }
            }
            $payment_stmt->close();
        }
    } catch (Exception $e) {
        // Payment query failed, continue with empty history
        error_log("Payment query failed: " . $e->getMessage());
    }
    
    // Prepare response data
    $response_data = [
        'success' => true,
        'full_name' => $member['full_name'] ?: $member['username'],
        'username' => $member['username'],
        'email' => $member['email'] ?: 'N/A',
        'contact_number' => $member['phone'] ?: 'N/A',
        'profile_picture' => $member['profile_picture'] ?: 'N/A',
        'join_date' => date('M d, Y', strtotime($member['created_at'])),
        'membership_type' => $member['plan_name'] ?: 'No Plan Selected',
        'membership_status' => $membership_status,
        'expiration_date' => $expiration_date !== 'N/A' ? date('M d, Y', strtotime($expiration_date)) : 'N/A',
        'plan_price' => $member['plan_price'] ? number_format($member['plan_price'], 2) : 'N/A',
        'plan_duration' => $member['plan_duration'] ? $member['plan_duration'] . ' days' : 'N/A',
        'payment_status' => $member['latest_payment_status'] ?: 'No Payment',
        'last_payment_date' => $member['latest_payment_date'] ? date('M d, Y', strtotime($member['latest_payment_date'])) : 'N/A',
        'attendance_count' => $member['attendance_count'],
        'payment_count' => $member['payment_count'],
        'attendance_record' => $attendance_record,
        'payment_history' => $payment_history,
        'balance' => $member['balance'] ?: 0.00
    ];
    
    // Clear any output buffer
    ob_clean();
    
    // Send JSON response
    echo json_encode($response_data);
    
} catch (Exception $e) {
    // Clear any output buffer
    ob_clean();
    
    // Log error
    error_log("Error in get_member.php: " . $e->getMessage());
    
    // Send error response
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred while fetching member details: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    // Clear any output buffer
    ob_clean();
    
    // Log error
    error_log("Fatal error in get_member.php: " . $e->getMessage());
    
    // Send error response
    echo json_encode([
        'success' => false, 
        'message' => 'A system error occurred while fetching member details'
    ]);
}

// Close database connection if it exists
if (isset($conn) && $conn) {
    $conn->close();
}

// End output buffer and flush
ob_end_flush();
?>
