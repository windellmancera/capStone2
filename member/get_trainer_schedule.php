<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get trainer ID from request
$trainer_id = isset($_GET['trainer_id']) ? (int)$_GET['trainer_id'] : 0;

if ($trainer_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid trainer ID']);
    exit();
}

// Get trainer schedule
$sql = "SELECT day_of_week, 
               TIME_FORMAT(start_time, '%h:%i %p') as start_time,
               TIME_FORMAT(end_time, '%h:%i %p') as end_time
        FROM trainer_schedules 
        WHERE trainer_id = ?
        ORDER BY FIELD(day_of_week, 
            'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
            'Friday', 'Saturday', 'Sunday')";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $trainer_id);
$stmt->execute();
$result = $stmt->get_result();

$schedule = [];
while ($row = $result->fetch_assoc()) {
    $schedule[] = $row;
}

// Return schedule as JSON
header('Content-Type: application/json');
echo json_encode(['schedule' => $schedule]);
?> 