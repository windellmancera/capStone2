<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once 'db.php';

// Check if it's a GET request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $user_id = $_SESSION['user_id'];
    $progress_id = $_GET['progress_id'] ?? null;
    
    if (empty($progress_id)) {
        echo json_encode(['success' => false, 'message' => 'Progress ID is required']);
        exit();
    }
    
    // Get the progress entry
    $sql = "SELECT * FROM member_progress WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $progress_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Progress entry not found or access denied']);
        exit();
    }
    
    $progress_entry = $result->fetch_assoc();
    $stmt->close();
    
    echo json_encode(['success' => true, 'data' => $progress_entry]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?> 