<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];

// Get user's active membership details
$membership_sql = "SELECT ph.id as payment_id, mp.id as plan_id, mp.duration as plan_duration, 
                         ph.payment_date, mp.name as plan_name
                  FROM payment_history ph
                  INNER JOIN users u ON ph.user_id = u.id
                  INNER JOIN membership_plans mp ON u.selected_plan_id = mp.id
                  WHERE ph.user_id = ? 
                  AND ph.payment_status = 'Approved'
                  ORDER BY ph.payment_date DESC 
                  LIMIT 1";

$stmt = $conn->prepare($membership_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();

// Check if user has an active membership
if (!$membership) {
    echo json_encode([
        'success' => false, 
        'error' => 'No approved membership found. Please wait for admin approval of your payment.'
    ]);
    exit();
}

// Create QR code data
$qr_data = [
    'user_id' => $user_id,
    'payment_id' => $membership['payment_id'],
    'plan_name' => $membership['plan_name'],
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . $membership['payment_id'] . time() . 'ALMO_FITNESS_SECRET')
];

$qr_filename = "qr_" . $user_id . "_" . time() . ".svg";
$qr_path = "../uploads/qr_codes/" . $qr_filename;

// Create QR code directory if it doesn't exist
if (!is_dir("../uploads/qr_codes")) {
    mkdir("../uploads/qr_codes", 0777, true);
}

try {
    // Generate simple QR code using Google Charts API
    $qr_content = json_encode($qr_data);
    $qr_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_content) . "&choe=UTF-8";
    
    // Download the QR code
    $qr_image = file_get_contents($qr_url);
    
    if ($qr_image !== false) {
        // Save QR code
        file_put_contents($qr_path, $qr_image);
        
        // Update user's QR code in database
        $update_sql = "UPDATE users SET qr_code = ? WHERE id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("si", $qr_filename, $user_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'filename' => $qr_filename,
                'message' => 'QR code generated successfully!'
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'error' => 'Failed to update database with QR code filename.'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'error' => 'Failed to generate QR code image.'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error generating QR code: ' . $e->getMessage()
    ]);
}
?> 