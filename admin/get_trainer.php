<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../db.php';

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Trainer ID is required']);
    exit();
}

$trainer_id = intval($_GET['id']);

$sql = "SELECT * FROM trainers WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $trainer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Trainer not found']);
    exit();
}

$trainer = $result->fetch_assoc();

// Convert numeric values to appropriate types
$trainer['id'] = intval($trainer['id']);
$trainer['experience_years'] = intval($trainer['experience_years']);
$trainer['hourly_rate'] = floatval($trainer['hourly_rate']);

header('Content-Type: application/json');
echo json_encode($trainer);
?> 