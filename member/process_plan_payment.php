<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $plan_id = $_POST['plan_id'] ?? null;
    $amount = $_POST['amount'] ?? 0;
    $payment_method = $_POST['payment_method'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $description = $_POST['description'] ?? 'Membership Plan Payment';
    
    // Validate required fields
    if (!$plan_id || !$amount || !$payment_method) {
        $_SESSION['error'] = "Please fill in all required fields.";
        header("Location: payment.php");
        exit();
    }
    
    // Get plan details
    $plan_sql = "SELECT * FROM membership_plans WHERE id = ?";
    $plan_stmt = $conn->prepare($plan_sql);
    $plan_stmt->bind_param("i", $plan_id);
    $plan_stmt->execute();
    $plan = $plan_stmt->get_result()->fetch_assoc();
    $plan_stmt->close();
    
    if (!$plan) {
        $_SESSION['error'] = "Invalid plan selected.";
        header("Location: payment.php");
        exit();
    }
    
    // Handle file upload for proof of payment
    $proof_of_payment = null;
    if (isset($_FILES['proof_of_payment']) && $_FILES['proof_of_payment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = "../uploads/payment_proofs/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['proof_of_payment']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $filename = "payment_" . $user_id . "_" . time() . "." . $file_extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['proof_of_payment']['tmp_name'], $filepath)) {
                $proof_of_payment = $filename;
            }
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert payment record
        $payment_sql = "INSERT INTO payment_history (user_id, amount, payment_date, payment_method, payment_status, description, reference_number, proof_of_payment) 
                       VALUES (?, ?, CURDATE(), ?, 'Pending', ?, ?, ?)";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("idssss", $user_id, $amount, $payment_method, $description, $reference_number, $proof_of_payment);
        $payment_stmt->execute();
        $payment_id = $conn->insert_id;
        $payment_stmt->close();
        
        // Update user's selected plan to the paid plan
        $update_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ii", $plan_id, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = "Payment submitted successfully! Your plan will be activated once payment is confirmed by admin.";
        header("Location: payment.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = "Error processing payment: " . $e->getMessage();
        header("Location: payment.php");
        exit();
    }
} else {
    header("Location: payment.php");
    exit();
}
?> 