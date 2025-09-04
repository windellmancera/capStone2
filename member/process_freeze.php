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

// Get user's freeze credits
$sql = "SELECT freeze_credits FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user['freeze_credits'] <= 0) {
    $_SESSION['error'] = "You have no freeze credits remaining for this year.";
    header("Location: payment.php");
    exit();
}

// Process freeze request
$start_date = $_POST['start_date'];
$end_date = $_POST['end_date'];
$reason = $_POST['reason'];

// Validate dates
$start = new DateTime($start_date);
$end = new DateTime($end_date);
$today = new DateTime();

if ($start < $today) {
    $_SESSION['error'] = "Start date cannot be in the past.";
    header("Location: payment.php");
    exit();
}

if ($end <= $start) {
    $_SESSION['error'] = "End date must be after start date.";
    header("Location: payment.php");
    exit();
}

$interval = $start->diff($end);
if ($interval->days > 30) {
    $_SESSION['error'] = "Freeze period cannot exceed 30 days.";
    header("Location: payment.php");
    exit();
}

// Check for existing freeze requests
$sql = "SELECT COUNT(*) as count FROM membership_freeze 
        WHERE user_id = ? AND status IN ('Pending', 'Approved') 
        AND (
            (start_date BETWEEN ? AND ?) OR 
            (end_date BETWEEN ? AND ?) OR
            (start_date <= ? AND end_date >= ?)
        )";
$stmt = $conn->prepare($sql);
$stmt->bind_param("issssss", 
    $user_id, 
    $start_date, 
    $end_date,
    $start_date,
    $end_date,
    $start_date,
    $end_date
);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row['count'] > 0) {
    $_SESSION['error'] = "You already have an overlapping freeze request for this period.";
    header("Location: payment.php");
    exit();
}

// Insert freeze request
$sql = "INSERT INTO membership_freeze (user_id, start_date, end_date, reason, status) 
        VALUES (?, ?, ?, ?, 'Pending')";
$stmt = $conn->prepare($sql);
$stmt->bind_param("isss", $user_id, $start_date, $end_date, $reason);

if ($stmt->execute()) {
    // Deduct freeze credit
    $sql = "UPDATE users SET freeze_credits = freeze_credits - 1 WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    $_SESSION['success'] = "Membership freeze request submitted successfully! Our staff will review your request.";
} else {
    $_SESSION['error'] = "Error submitting freeze request: " . $stmt->error;
}

header("Location: payment.php");
exit();
?> 