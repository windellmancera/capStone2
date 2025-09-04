<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

header('Content-Type: application/json');

require_once '../db.php';

// Load QR library if available
$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
$hasQrLib = false;
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
    $hasQrLib = true;
}

// Import QR classes if library is present (avoid fatal errors when missing)
if ($hasQrLib) {
    try {
        class_exists('Endroid\\QrCode\\QrCode');
        class_exists('Endroid\\QrCode\\Writer\\PngWriter');
        class_exists('Endroid\\QrCode\\Color\\Color');
        class_exists('Endroid\\QrCode\\Encoding\\Encoding');
        class_exists('Endroid\\QrCode\\ErrorCorrectionLevel\\ErrorCorrectionLevelHigh');
        class_exists('Endroid\\QrCode\\Logo\\Logo');
    } catch (Throwable $e) {
        $hasQrLib = false;
    }
}

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
    if ($hasQrLib && class_exists('Endroid\\QrCode\\Writer\\PngWriter')) {
        // Use Endroid QRCode library
        $qrCode = Endroid\QrCode\QrCode::create(json_encode($qr_data))
            ->setSize(300)
            ->setMargin(10)
            ->setErrorCorrectionLevel(new Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh())
            ->setForegroundColor(new Endroid\QrCode\Color\Color(55, 55, 55))
            ->setBackgroundColor(new Endroid\QrCode\Color\Color(255, 255, 255))
            ->setEncoding(new Endroid\QrCode\Encoding\Encoding('UTF-8'));

        $writer = new Endroid\QrCode\Writer\PngWriter();

        // Optional logo
        $logo = null;
        if (file_exists('../image/almo.jpg') && class_exists('Endroid\\QrCode\\Logo\\Logo')) {
            $logo = Endroid\QrCode\Logo\Logo::create('../image/almo.jpg')->setResizeToWidth(50);
        }

        $result = $writer->write($qrCode, $logo);
        $result->saveToFile($qr_path);
    } else {
        // Fallback to Google Charts
        $qr_content = json_encode($qr_data);
        $qr_url = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_content) . '&choe=UTF-8';
        $qr_image = @file_get_contents($qr_url);
        if ($qr_image === false) {
            throw new Exception('Failed to fetch QR from fallback service');
        }
        file_put_contents($qr_path, $qr_image);
    }

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
} catch (Throwable $e) {
    error_log('QR Code Generation Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to generate QR code']);
}
?> 