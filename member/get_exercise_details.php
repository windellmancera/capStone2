<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once 'exercise_details_helper.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['exercise_name']) || empty($input['exercise_name'])) {
        echo json_encode(['success' => false, 'message' => 'Exercise name is required']);
        exit();
    }
    
    $exercise_name = trim($input['exercise_name']);
    $exercise_details = ExerciseDetailsHelper::getExerciseDetails($exercise_name);
    
    if ($exercise_details) {
        echo json_encode([
            'success' => true, 
            'exercise' => $exercise_details
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Exercise details not found'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in get_exercise_details.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while fetching exercise details'
    ]);
}
?>
