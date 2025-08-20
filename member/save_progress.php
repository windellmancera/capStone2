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
    $date_recorded = $_POST['date_recorded'] ?? date('Y-m-d');
    $weight = $_POST['weight'] ?? null;
    $body_fat = $_POST['body_fat'] ?? null;
    $muscle_mass = $_POST['muscle_mass'] ?? null;
    $chest = $_POST['chest'] ?? null;
    $waist = $_POST['waist'] ?? null;
    $arms = $_POST['arms'] ?? null;
    $legs = $_POST['legs'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (empty($weight) && empty($body_fat) && empty($muscle_mass) && empty($chest) && empty($waist) && empty($arms) && empty($legs)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in at least one measurement']);
        exit();
    }
    
    // Prepare SQL statement
    $sql = "INSERT INTO member_progress (user_id, date_recorded, weight, body_fat, muscle_mass, chest, waist, arms, legs, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isddddddds", 
        $user_id, 
        $date_recorded, 
        $weight, 
        $body_fat, 
        $muscle_mass, 
        $chest, 
        $waist, 
        $arms, 
        $legs, 
        $notes
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Progress entry saved successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error saving progress: ' . $stmt->error]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?> 