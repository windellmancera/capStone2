<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';

// Get user ID from session
$user_id = $_SESSION['user_id'];

// Get payment data
$amount = floatval($_POST['amount']);
$payment_method = $_POST['payment_method'];
$reference_number = $_POST['reference_number'] ?? null;
$payment_date = date('Y-m-d H:i:s');
$description = $_POST['description'] ?? "Payment for membership";
$plan_id = $_POST['plan_id'] ?? null;

// Validate payment method
if (!in_array($payment_method, ['Cash', 'GCash', 'PayMaya', 'GoTyme'])) {
    $_SESSION['error'] = "Invalid payment method.";
    header("Location: payment.php");
    exit();
}

// Validate amount
if ($amount <= 0) {
    $_SESSION['error'] = "Invalid payment amount.";
    header("Location: payment.php");
    exit();
}

// For online payments, validate required fields
if ($payment_method !== 'Cash') {
    if (empty($reference_number)) {
        $_SESSION['error'] = "Reference number is required for online payments.";
        header("Location: payment.php");
        exit();
    }

    if (!isset($_FILES['proof_of_payment']) || $_FILES['proof_of_payment']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Proof of payment is required for online payments.";
        header("Location: payment.php");
        exit();
    }
}

// Handle file upload for online payments
$proof_of_payment = null;
if ($payment_method !== 'Cash' && isset($_FILES['proof_of_payment'])) {
    $file_tmp = $_FILES['proof_of_payment']['tmp_name'];
    $file_name = $_FILES['proof_of_payment']['name'];
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    // Validate file extension
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($file_ext, $allowed_extensions)) {
        $_SESSION['error'] = "Only JPG, JPEG, PNG & GIF files are allowed.";
        header("Location: payment.php");
        exit();
    }

    // Validate file size (max 10MB)
    if ($_FILES['proof_of_payment']['size'] > 10 * 1024 * 1024) {
        $_SESSION['error'] = "File size should not exceed 10MB.";
        header("Location: payment.php");
        exit();
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '.' . $file_ext;
    $upload_path = '../uploads/payment_proofs/' . $new_filename;
    
    // Create directory if it doesn't exist
    if (!file_exists('../uploads/payment_proofs')) {
        mkdir('../uploads/payment_proofs', 0777, true);
    }
    
    // Move uploaded file
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        $_SESSION['error'] = "Error uploading proof of payment.";
        header("Location: payment.php");
        exit();
    }
    
    $proof_of_payment = $new_filename;
}

try {
    // Start transaction
    $conn->begin_transaction();

    // Insert payment record
    $sql = "INSERT INTO payment_history (
        user_id, 
        amount, 
        payment_date, 
        payment_method, 
        payment_status, 
        proof_of_payment, 
        reference_number, 
        description
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    
    if ($stmt === false) {
        throw new Exception("Error preparing statement: " . $conn->error);
    }

    // Set payment status based on method
    $payment_status = $payment_method === 'Cash' ? 'Pending' : 'Pending Verification';
    
    $stmt->bind_param("idssssss", 
        $user_id, 
        $amount, 
        $payment_date, 
        $payment_method, 
        $payment_status, 
        $proof_of_payment, 
        $reference_number, 
        $description
    );

    if (!$stmt->execute()) {
        throw new Exception("Error submitting payment: " . $stmt->error);
    }

    // If this is a plan payment, update user's selected plan
    if ($plan_id) {
        $sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $plan_id, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Error updating selected plan: " . $stmt->error);
        }
    }

    // Update user's balance (reduce outstanding balance)
    $sql = "UPDATE users SET balance = GREATEST(0, balance - ?) WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("di", $amount, $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Error updating balance: " . $stmt->error);
    }

    // Commit transaction
    $conn->commit();

    if ($payment_method === 'Cash') {
        $_SESSION['success'] = "Payment submitted successfully! Please proceed to the front desk to complete your cash payment.";
    } else {
        $_SESSION['success'] = "Payment submitted successfully! Our staff will verify your payment shortly.";
    }

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Delete uploaded file if it exists
    if ($proof_of_payment && file_exists('../uploads/payment_proofs/' . $proof_of_payment)) {
        unlink('../uploads/payment_proofs/' . $proof_of_payment);
    }
    
    $_SESSION['error'] = $e->getMessage();
}

header("Location: payment.php");
exit();
?> 