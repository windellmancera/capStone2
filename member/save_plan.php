<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

// Database connection
require_once '../db.php';

$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

try {
    // Validate required fields
    $required_fields = ['plan_date', 'title', 'workout_type', 'duration'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $plan_date = $_POST['plan_date'];
    $title = trim($_POST['title']);
    $workout_type = $_POST['workout_type'];
    $duration = (int)$_POST['duration'];
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $preferred_time = isset($_POST['preferred_time']) ? $_POST['preferred_time'] : null;
    $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $plan_date)) {
        throw new Exception('Invalid date format');
    }

    // Validate duration
    if ($duration < 15 || $duration > 180) {
        throw new Exception('Duration must be between 15 and 180 minutes');
    }

    // Check if plan already exists for this date and user
    $check_sql = "SELECT id FROM member_plans WHERE user_id = ? AND plan_date = ?";
    if ($plan_id) {
        $check_sql .= " AND id != ?";
    }
    
    $check_stmt = $conn->prepare($check_sql);
    if ($plan_id) {
        $check_stmt->bind_param("isi", $user_id, $plan_date, $plan_id);
    } else {
        $check_stmt->bind_param("is", $user_id, $plan_date);
    }
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        throw new Exception('You already have a plan for this date. Please edit the existing plan instead.');
    }

    if ($plan_id) {
        // Update existing plan
        $sql = "UPDATE member_plans SET 
                title = ?, 
                workout_type = ?, 
                duration = ?, 
                description = ?, 
                preferred_time = ?,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssissii", $title, $workout_type, $duration, $description, $preferred_time, $plan_id, $user_id);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Plan updated successfully!'];
        } else {
            throw new Exception('Failed to update plan');
        }
    } else {
        // Insert new plan
        $sql = "INSERT INTO member_plans (user_id, plan_date, title, workout_type, duration, description, preferred_time, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssiss", $user_id, $plan_date, $title, $workout_type, $duration, $description, $preferred_time);
        
        if ($stmt->execute()) {
            $response = ['success' => true, 'message' => 'Plan created successfully!'];
        } else {
            throw new Exception('Failed to create plan');
        }
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
} catch (Error $e) {
    $response = ['success' => false, 'message' => 'An unexpected error occurred'];
}

$conn->close();
echo json_encode($response);
?> 