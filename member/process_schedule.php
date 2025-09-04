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
$trainer_id = isset($_POST['trainer_id']) ? (int)$_POST['trainer_id'] : 0;
$day = isset($_POST['day']) ? $_POST['day'] : '';

if ($trainer_id <= 0 || empty($day)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input data']);
    exit();
}

// Create training_sessions table if it doesn't exist
$create_table_sql = "CREATE TABLE IF NOT EXISTS training_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    trainer_id INT NOT NULL,
    member_id INT NOT NULL,
    session_date DATE NOT NULL,
    status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (trainer_id) REFERENCES trainers(id),
    FOREIGN KEY (member_id) REFERENCES users(id)
)";

$conn->query($create_table_sql);

// Get next available date for the selected day
$next_date = date('Y-m-d', strtotime("next $day"));

// Check if user already has a pending or confirmed session with this trainer
$check_sql = "SELECT id FROM training_sessions 
              WHERE member_id = ? AND trainer_id = ? 
              AND status IN ('pending', 'confirmed')";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("ii", $_SESSION['user_id'], $trainer_id);
$check_stmt->execute();
$existing_session = $check_stmt->get_result()->fetch_assoc();

if ($existing_session) {
    http_response_code(400);
    echo json_encode(['error' => 'You already have a pending or confirmed session with this trainer']);
    exit();
}

// Schedule the session
$sql = "INSERT INTO training_sessions (trainer_id, member_id, session_date) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iis", $trainer_id, $_SESSION['user_id'], $next_date);

if ($stmt->execute()) {
    // Get trainer's name for the message
    $trainer_sql = "SELECT name FROM trainers WHERE id = ?";
    $trainer_stmt = $conn->prepare($trainer_sql);
    $trainer_stmt->bind_param("i", $trainer_id);
    $trainer_stmt->execute();
    $trainer = $trainer_stmt->get_result()->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'message' => "Session scheduled with " . $trainer['name'] . " for next $day (" . date('F j, Y', strtotime($next_date)) . "). Please wait for trainer confirmation."
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to schedule session']);
}
?> 