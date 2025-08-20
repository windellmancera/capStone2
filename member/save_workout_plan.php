<?php
// Check if session is already active before starting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../db.php';
require_once 'predictive_analysis_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'member') {
    header('Location: member_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';

// Check if workout plan data was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['workout_plan'])) {
    try {
        // Decode the JSON workout plan data
        $workout_plan_data = json_decode($_POST['workout_plan'], true);
        
        if ($workout_plan_data) {
            // Prepare the workout plan data for storage
            $plan_data = [
                'frequency' => $workout_plan_data['workout_plan']['frequency'],
                'duration' => $workout_plan_data['workout_plan']['duration'],
                'schedule' => $workout_plan_data['workout_plan']['split']['schedule'],
                'exercise_types' => $workout_plan_data['exercise_types'],
                'intensity_level' => $workout_plan_data['intensity_level'],
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Check if user already has a saved workout plan
            $check_query = "SELECT id FROM member_workout_plans WHERE user_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing plan
                $update_query = "UPDATE member_workout_plans SET 
                    plan_data = ?, 
                    updated_at = NOW() 
                    WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_query);
                $plan_json = json_encode($plan_data);
                $update_stmt->bind_param("si", $plan_json, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_message = "Your workout plan has been updated successfully!";
                } else {
                    throw new Exception("Failed to update workout plan");
                }
            } else {
                // Insert new plan
                $insert_query = "INSERT INTO member_workout_plans 
                    (user_id, plan_data, created_at, updated_at) 
                    VALUES (?, ?, NOW(), NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                $plan_json = json_encode($plan_data);
                $insert_stmt->bind_param("is", $user_id, $plan_json);
                
                if ($insert_stmt->execute()) {
                    $success_message = "Your workout plan has been saved successfully!";
                } else {
                    throw new Exception("Failed to save workout plan");
                }
            }
            
            // Store success message in session for display in modal
            $_SESSION['recommendations_success_message'] = $success_message;
            
        } else {
            throw new Exception("Invalid workout plan data");
        }
        
    } catch (Exception $e) {
        $_SESSION['recommendations_error_message'] = "Error saving workout plan: " . $e->getMessage();
    }
}

// Redirect back to the previous page
$redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'profile.php';
header('Location: ' . $redirect_url);
exit();
?> 