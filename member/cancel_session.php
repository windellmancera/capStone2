<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$session_id = isset($data['session_id']) ? (int)$data['session_id'] : 0;

if ($session_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session ID']);
    exit();
}

// Verify the session belongs to the user and is pending
$check_sql = "SELECT ts.*, t.name as trainer_name 
              FROM training_sessions ts
              JOIN trainers t ON ts.trainer_id = t.id
              WHERE ts.id = ? AND ts.member_id = ? AND ts.status = 'pending'";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("ii", $session_id, $_SESSION['user_id']);
$check_stmt->execute();
$session = $check_stmt->get_result()->fetch_assoc();

if (!$session) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session or session cannot be cancelled']);
    exit();
}

// Cancel the session
$sql = "UPDATE training_sessions SET status = 'cancelled' WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => "Session with " . $session['trainer_name'] . " has been cancelled."
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to cancel session']);
}
?> 