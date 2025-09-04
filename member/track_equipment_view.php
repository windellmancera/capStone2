<?php
session_start();
require_once '../db.php';

// Check if user is logged in and is a member
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if equipment_id is provided
if (!isset($_POST['equipment_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Equipment ID is required']);
    exit();
}

$equipment_id = intval($_POST['equipment_id']);
$user_id = $_SESSION['user_id'];

// Insert the view record
$sql = "INSERT INTO equipment_views (equipment_id, user_id) VALUES (?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $equipment_id, $user_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to record view']);
}

$stmt->close();
$conn->close(); 