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
    'plan_duration' => $membership['plan_duration'],
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . $membership['payment_id'] . time() . 'ALMO_FITNESS_SECRET')
];

$qr_filename = "qr_" . $user_id . "_" . time() . ".png";
$qr_path = "../uploads/qr_codes/" . $qr_filename;

// Create QR code directory if it doesn't exist
if (!is_dir("../uploads/qr_codes")) {
    mkdir("../uploads/qr_codes", 0777, true);
}

// Function to try different QR APIs
function generateQRWithAPI($data, $api_type = 'qrserver') {
    $qr_content = json_encode($data);
    
    switch ($api_type) {
        case 'qrserver':
            // QR Server API (most reliable)
            $url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qr_content);
            break;
            
        case 'google':
            // Google Charts API
            $url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($qr_content) . "&choe=UTF-8";
            break;
            
        case 'goqr':
            // GoQR API
            $url = "https://api.qr-code-generator.de/v1/create/?text=" . urlencode($qr_content) . "&size=300";
            break;
            
        case 'qr-code-generator':
            // QR Code Generator API
            $url = "https://api.qrcode-monkey.com/qr/custom?size=300&data=" . urlencode($qr_content);
            break;
            
        default:
            return false;
    }
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'AlmoFitness/1.0'
        ]
    ]);
    
    $image = file_get_contents($url, false, $context);
    return $image !== false ? $image : false;
}

try {
    // Try multiple APIs in order of reliability
    $apis = ['qrserver', 'google', 'goqr', 'qr-code-generator'];
    $qr_image = false;
    $used_api = '';
    
    foreach ($apis as $api) {
        $qr_image = generateQRWithAPI($qr_data, $api);
        if ($qr_image !== false) {
            $used_api = $api;
            break;
        }
    }
    
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
                'qr_url' => "../uploads/qr_codes/" . $qr_filename,
                'api_used' => $used_api,
                'message' => 'QR code generated successfully using ' . $used_api . ' API!'
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
            'error' => 'All QR code APIs are currently unavailable. Please try again later.'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Error generating QR code: ' . $e->getMessage()
    ]);
}
?> 