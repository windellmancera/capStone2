<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Please log in to submit feedback']);
    exit();
}

// Validate input
if (!isset($_POST['message']) || empty(trim($_POST['message']))) {
    echo json_encode(['error' => 'Feedback message is required']);
    exit();
}

$user_id = $_SESSION['user_id'];
$message = trim($_POST['message']);
$category = isset($_POST['category']) ? $_POST['category'] : 'general';

// Validate category
$valid_categories = ['facilities', 'services', 'system', 'general'];
if (!in_array($category, $valid_categories)) {
    $category = 'general';
}

try {
    // Insert new feedback
    $insert_sql = "INSERT INTO gym_feedback (user_id, message, category) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("iss", $user_id, $message, $category);

    if (!$stmt->execute()) {
        throw new Exception("Error executing feedback query");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your feedback!'
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Error submitting feedback: ' . $e->getMessage()]);
}

// Close connections
if (isset($stmt)) $stmt->close();
$conn->close(); 