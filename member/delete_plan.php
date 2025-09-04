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
    // Validate plan_id
    if (!isset($_POST['plan_id']) || empty($_POST['plan_id'])) {
        throw new Exception('Plan ID is required');
    }

    $plan_id = (int)$_POST['plan_id'];

    // Verify the plan belongs to the user
    $check_sql = "SELECT id FROM member_plans WHERE id = ? AND user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $plan_id, $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows === 0) {
        throw new Exception('Plan not found or you do not have permission to delete it');
    }

    // Delete the plan
    $delete_sql = "DELETE FROM member_plans WHERE id = ? AND user_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ii", $plan_id, $user_id);
    
    if ($delete_stmt->execute()) {
        $response = ['success' => true, 'message' => 'Plan deleted successfully!'];
    } else {
        throw new Exception('Failed to delete plan');
    }

} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
} catch (Error $e) {
    $response = ['success' => false, 'message' => 'An unexpected error occurred'];
}

$conn->close();
echo json_encode($response);
?> 