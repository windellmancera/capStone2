<?php
/**
 * Payment Status Helper Functions
 * Provides utility functions for managing payment status and user data
 */

/**
 * Get user payment status and related information
 */
function getUserPaymentStatus($conn, $user_id) {
    $sql = "SELECT u.*, mp.name as plan_name, mp.price as plan_price, mp.duration as plan_duration,
                   mp.features as plan_features, mp.description as plan_description,
                   (SELECT payment_status FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved' ORDER BY payment_date DESC LIMIT 1) as payment_status,
                   (SELECT payment_date FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved' ORDER BY payment_date DESC LIMIT 1) as payment_date,
                   (SELECT SUM(amount) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved') as total_paid,
                   (SELECT COUNT(*) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved') as completed_payments
            FROM users u 
            LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
            WHERE u.id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error preparing getUserPaymentStatus statement: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate membership end date based on payment date and plan duration
    if ($user && $user['payment_status'] === 'Approved' && $user['payment_date'] && $user['plan_duration']) {
        $user['membership_end_date'] = date('Y-m-d', strtotime($user['payment_date'] . ' + ' . $user['plan_duration'] . ' days'));
    }
    
    return $user;
}

/**
 * Get recent payments for a user
 */
function getRecentPayments($conn, $user_id, $limit = 5) {
    $sql = "SELECT * FROM payment_history 
            WHERE user_id = ? 
            ORDER BY payment_date DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error preparing getRecentPayments statement: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    
    $stmt->close();
    return $payments;
}

/**
 * Get QR code for user
 */
function getQRCode($user) {
    if (!$user || !isset($user['qr_code'])) {
        return null;
    }
    
    $qr_code = $user['qr_code'];
    
    // If QR code doesn't exist, generate one
    if (empty($qr_code)) {
        // This would typically call generate_qr.php or similar
        return null;
    }
    
    return $qr_code;
}

/**
 * Check if user has active membership
 */
function hasActiveMembership($user) {
    if (!$user) {
        return false;
    }
    
    // Check if user has a selected plan
    if (!isset($user['selected_plan_id']) || empty($user['selected_plan_id'])) {
        return false;
    }
    
    // Check if there's an approved payment
    if (isset($user['payment_status']) && $user['payment_status'] === 'Approved') {
        return true;
    }
    
    // Check if membership end date is in the future
    if (isset($user['membership_end_date']) && $user['membership_end_date'] >= date('Y-m-d')) {
        return true;
    }
    
    return false;
}

/**
 * Get membership status
 */
function getMembershipStatus($user) {
    if (!hasActiveMembership($user)) {
        return 'inactive';
    }
    
    // Check payment status
    if (isset($user['payment_status'])) {
        switch ($user['payment_status']) {
            case 'Approved':
                return 'active';
            case 'Completed':
                return 'pending_approval';
            case 'Pending':
                return 'pending';
            case 'Failed':
                return 'failed';
            default:
                return 'unknown';
        }
    }
    
    return 'pending_approval'; // Changed default to pending_approval instead of active
}

/**
 * Update user QR code
 */
function updateUserQRCode($conn, $user_id, $qr_code) {
    $update_sql = "UPDATE users SET qr_code = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    
    if (!$stmt) {
        error_log("Error preparing updateUserQRCode statement: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("si", $qr_code, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

/**
 * Get payment history for user
 */
function getPaymentHistory($conn, $user_id, $limit = 10) {
    $sql = "SELECT ph.*, mp.name as plan_name 
            FROM payment_history ph
            LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
            WHERE ph.user_id = ? 
            ORDER BY ph.payment_date DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error preparing getPaymentHistory statement: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    
    $stmt->close();
    return $payments;
}

/**
 * Check if user can access gym (based on membership status)
 */
function canAccessGym($user) {
    $status = getMembershipStatus($user);
    return $status === 'active'; // Only allow access when payment is approved
}

/**
 * Get membership plan details
 */
function getMembershipPlan($conn, $plan_id) {
    $sql = "SELECT * FROM membership_plans WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Error preparing getMembershipPlan statement: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $plan = $result->fetch_assoc();
    $stmt->close();
    
    return $plan;
}
?> 