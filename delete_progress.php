<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once 'db.php';

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    $progress_id = $_POST['progress_id'] ?? null;
    
    if (empty($progress_id)) {
        echo json_encode(['success' => false, 'message' => 'Progress ID is required']);
        exit();
    }
    
    // Verify the progress entry belongs to the current user
    $check_sql = "SELECT id FROM member_progress WHERE id = ? AND user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $progress_id, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Progress entry not found or access denied']);
        exit();
    }
    $check_stmt->close();
    
    // Delete the progress entry
    $delete_sql = "DELETE FROM member_progress WHERE id = ? AND user_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("ii", $progress_id, $user_id);
    
    if ($delete_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Progress entry deleted successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting progress: ' . $delete_stmt->error]);
    }
    
    $delete_stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?> 