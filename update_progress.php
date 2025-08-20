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
    $date_recorded = $_POST['date_recorded'] ?? date('Y-m-d');
    $weight = $_POST['weight'] ?? null;
    $body_fat = $_POST['body_fat'] ?? null;
    $muscle_mass = $_POST['muscle_mass'] ?? null;
    $chest = $_POST['chest'] ?? null;
    $waist = $_POST['waist'] ?? null;
    $arms = $_POST['arms'] ?? null;
    $legs = $_POST['legs'] ?? null;
    $notes = $_POST['notes'] ?? '';
    
    if (empty($progress_id)) {
        echo json_encode(['success' => false, 'message' => 'Progress ID is required']);
        exit();
    }
    
    if (empty($weight) && empty($body_fat) && empty($muscle_mass) && empty($chest) && empty($waist) && empty($arms) && empty($legs)) {
        echo json_encode(['success' => false, 'message' => 'Please fill in at least one measurement']);
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
    
    // Update the progress entry
    $update_sql = "UPDATE member_progress SET 
                   date_recorded = ?, 
                   weight = ?, 
                   body_fat = ?, 
                   muscle_mass = ?, 
                   chest = ?, 
                   waist = ?, 
                   arms = ?, 
                   legs = ?, 
                   notes = ? 
                   WHERE id = ? AND user_id = ?";
    
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("sdddddddsii", $date_recorded, $weight, $body_fat, $muscle_mass, $chest, $waist, $arms, $legs, $notes, $progress_id, $user_id);
    
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Progress entry updated successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating progress: ' . $update_stmt->error]);
    }
    
    $update_stmt->close();
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

$conn->close();
?> 