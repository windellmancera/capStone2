<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];
$success = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $height = isset($_POST['height']) ? floatval($_POST['height']) : null;
    $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : null;
    $target_weight = isset($_POST['target_weight']) ? floatval($_POST['target_weight']) : null;
    $fitness_goal = isset($_POST['fitness_goal']) ? $_POST['fitness_goal'] : null;
    $experience_level = isset($_POST['experience_level']) ? $_POST['experience_level'] : null;
    $preferred_workout_type = isset($_POST['preferred_workout_type']) ? $_POST['preferred_workout_type'] : null;
    
    // Validate required fields
    if (empty($height) || empty($weight) || empty($fitness_goal)) {
        $error = "Please fill in all required fields (Height, Current Weight, and Fitness Goal).";
    } else {
        // Update user's fitness data
        $sql = "UPDATE users SET 
                height = ?, 
                weight = ?, 
                target_weight = ?, 
                fitness_goal = ?, 
                experience_level = ?, 
                preferred_workout_type = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ddddssi", $height, $weight, $target_weight, $fitness_goal, $experience_level, $preferred_workout_type, $user_id);
        
        if ($stmt->execute()) {
            $success = true;
            
            // Clear any cached recommendations to force refresh
            if (isset($_SESSION['cached_recommendations'])) {
                unset($_SESSION['cached_recommendations']);
            }
            
            // Store a flag to show that recommendations should be refreshed
            $_SESSION['recommendations_refreshed'] = true;
            
            // Store the new fitness goal for immediate recommendation update
            $_SESSION['new_fitness_goal'] = $fitness_goal;
            $_SESSION['new_experience_level'] = $experience_level;
            $_SESSION['new_preferred_workout_type'] = $preferred_workout_type;
            
            header("Location: profile.php?fitness_updated=1&refresh_recommendations=1");
            exit();
        } else {
            $error = "Failed to update fitness data. Please try again.";
        }
    }
}

// If there was an error, redirect back with error message
if (!empty($error)) {
    header("Location: profile.php?error=" . urlencode($error));
    exit();
}
?> 