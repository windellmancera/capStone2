<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please log in to enroll in classes']);
    exit();
}

// Check if class_id was provided
if (!isset($_POST['class_id'])) {
    echo json_encode(['success' => false, 'message' => 'No class specified']);
    exit();
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$class_id = $_POST['class_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Check if user is already enrolled
    $check_sql = "SELECT id FROM class_enrollments WHERE user_id = ? AND class_id = ? AND status = 'Active'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $user_id, $class_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception('You are already enrolled in this class');
    }

    // Check class capacity
    $capacity_sql = "SELECT c.max_capacity, COUNT(e.id) as current_enrollments 
                    FROM classes c 
                    LEFT JOIN class_enrollments e ON c.id = e.class_id AND e.status = 'Active'
                    WHERE c.id = ?
                    GROUP BY c.id, c.max_capacity";
    $capacity_stmt = $conn->prepare($capacity_sql);
    $capacity_stmt->bind_param("i", $class_id);
    $capacity_stmt->execute();
    $capacity_result = $capacity_stmt->get_result();
    $capacity_data = $capacity_result->fetch_assoc();

    if (!$capacity_data) {
        throw new Exception('Class not found');
    }

    if ($capacity_data['current_enrollments'] >= $capacity_data['max_capacity']) {
        throw new Exception('Class is full');
    }

    // Enroll user in the class
    $enroll_sql = "INSERT INTO class_enrollments (user_id, class_id, status) VALUES (?, ?, 'Active')";
    $enroll_stmt = $conn->prepare($enroll_sql);
    $enroll_stmt->bind_param("ii", $user_id, $class_id);
    $enroll_stmt->execute();

    // Commit transaction
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Successfully enrolled in class']);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?> 