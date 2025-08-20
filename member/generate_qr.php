<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

require_once '../db.php';
require_once '../vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Label\Label;

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

// Check if user has an active membership and it's not a daily plan
if (!$membership || $membership['plan_duration'] < 30) {
    echo json_encode([
        'success' => false, 
        'error' => 'QR code generation is only available for approved monthly and annual membership plans. Please wait for admin approval of your payment.'
    ]);
    exit();
}

// Create QR code data with encrypted information
$qr_data = [
    'user_id' => $user_id,
    'payment_id' => $membership['payment_id'],
    'plan_duration' => $membership['plan_duration'],
    'timestamp' => time(),
    'hash' => hash('sha256', $user_id . $membership['payment_id'] . $membership['plan_duration'] . time() . 'ALMO_FITNESS_SECRET')
];

$qr_filename = "qr_" . $user_id . "_" . time() . ".png";
$qr_path = "../uploads/qr_codes/" . $qr_filename;

// Create QR code directory if it doesn't exist
if (!is_dir("../uploads/qr_codes")) {
    mkdir("../uploads/qr_codes", 0777, true);
}

try {
    // Create QR code
    $qrCode = QrCode::create(json_encode($qr_data))
        ->setSize(300)
        ->setMargin(10)
        ->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh)
        ->setForegroundColor(new Color(55, 55, 55))
        ->setBackgroundColor(new Color(255, 255, 255))
        ->setEncoding(new Encoding('UTF-8'));

    // Create writer
    $writer = new PngWriter();
    
    // Add logo
    $logo = null;
    if (file_exists('../image/almo.jpg')) {
        $logo = Logo::create('../image/almo.jpg')
            ->setResizeToWidth(50);
    }
    
    // Generate QR code
    $result = $writer->write($qrCode, $logo);
    
    // Save QR code
    $result->saveToFile($qr_path);
    
    // Update user's QR code in database
    $update_sql = "UPDATE users SET qr_code = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("si", $qr_filename, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'filename' => $qr_filename,
            'expiry' => date('Y-m-d H:i:s', strtotime($membership['payment_date'] . ' + ' . $membership['plan_duration'] . ' days'))
        ]);
    } else {
        throw new Exception('Failed to update user QR code');
    }
    
} catch (Exception $e) {
    error_log("QR Code Generation Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR code']);
}
?> 