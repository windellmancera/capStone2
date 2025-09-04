<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

// Database connection
require_once '../db.php';

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    
    // Get form data
    $exercise_name = $_POST['exercise_name'] ?? '';
    $weight = $_POST['weight'] ?? null;
    $reps = $_POST['reps'] ?? null;
    $sets = $_POST['sets'] ?? null;
    $date_performed = $_POST['date_performed'] ?? date('Y-m-d');
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (empty($exercise_name)) {
        echo json_encode(['success' => false, 'message' => 'Please enter exercise name']);
        exit();
    }
    
    // Prepare SQL statement
    $sql = "INSERT INTO workout_performance (user_id, exercise_name, weight, reps, sets, date_performed, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isdiiss", 
        $user_id, 
        $exercise_name, 
        $weight, 
        $reps, 
        $sets, 
        $date_performed, 
        $notes
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Workout performance saved successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving workout: ' . $stmt->error]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?> 