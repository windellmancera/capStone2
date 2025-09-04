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
$is_ajax = isset($_POST['update_type']) && $_POST['update_type'] === 'bmi_data';

// Add error logging for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to avoid breaking JSON response
ini_set('log_errors', 1);

// Check if required database columns exist
function checkDatabaseColumns($conn) {
    $required_columns = ['body_fat', 'muscle_mass', 'waist', 'hip', 'training_level', 'training_frequency', 'training_notes'];
    $missing_columns = [];
    
    $result = $conn->query("DESCRIBE users");
    $existing_columns = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
    }
    
    foreach ($required_columns as $column) {
        if (!in_array($column, $existing_columns)) {
            $missing_columns[] = $column;
        }
    }
    
    return $missing_columns;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_type = isset($_POST['update_type']) ? $_POST['update_type'] : '';
    
    if ($update_type === 'bmi_data') {
        // Check if database columns exist
        $missing_columns = checkDatabaseColumns($conn);
        if (!empty($missing_columns)) {
            // Try to update only basic fields if advanced columns are missing
            $height = isset($_POST['height']) ? floatval($_POST['height']) : null;
            $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : null;
            
            if (!empty($height) && !empty($weight)) {
                // Validate basic fields
                if ($height < 100 || $height > 250) {
                    $error = "Please enter a valid height between 100-250 cm.";
                } elseif ($weight < 30 || $weight > 300) {
                    $error = "Please enter a valid weight between 30-300 kg.";
                } else {
                    // Update only basic fields
                    $sql = "UPDATE users SET height = ?, weight = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    
                    if ($stmt) {
                        $stmt->bind_param("ddi", $height, $weight, $user_id);
                        
                        if ($stmt->execute()) {
                            $success = true;
                            $height_m = $height / 100;
                            $bmi = $weight / ($height_m * $height_m);
                            
                            if ($is_ajax) {
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'message' => 'Basic fitness data updated successfully! Note: Advanced tracking features require database upgrade.',
                                    'bmi' => round($bmi, 1),
                                    'height' => $height,
                                    'weight' => $weight
                                ]);
                                exit();
                            }
                        } else {
                            $error = "Failed to update basic fitness data: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error = "Database error: " . $conn->error;
                    }
                }
            } else {
                $error = "Height and weight are required.";
            }
        } else {
        // Handle comprehensive fitness tracking form submission
        $height = isset($_POST['height']) ? floatval($_POST['height']) : null;
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : null;
        $body_fat = isset($_POST['body_fat']) ? floatval($_POST['body_fat']) : null;
        $muscle_mass = isset($_POST['muscle_mass']) ? floatval($_POST['muscle_mass']) : null;
        $waist = isset($_POST['waist']) ? floatval($_POST['waist']) : null;
        $hip = isset($_POST['hip']) ? floatval($_POST['hip']) : null;
        $training_level = isset($_POST['training_level']) ? $_POST['training_level'] : null;
        $training_frequency = isset($_POST['training_frequency']) ? $_POST['training_frequency'] : null;
        $training_notes = isset($_POST['training_notes']) ? trim($_POST['training_notes']) : null;
        
        // Validate required fields
        if (empty($height) || empty($weight)) {
            $error = "Please fill in both height and weight fields.";
        } elseif ($height < 100 || $height > 250) {
            $error = "Please enter a valid height between 100-250 cm.";
        } elseif ($weight < 30 || $weight > 300) {
            $error = "Please enter a valid weight between 30-300 kg.";
        } else {
            // Calculate BMI
            $height_m = $height / 100;
            $bmi = $weight / ($height_m * $height_m);
            
            // Additional BMI validation
            if ($bmi < 10 || $bmi > 60) {
                $error = "The calculated BMI seems unrealistic. Please check your height and weight values.";
            } else {
                // Validate optional fields if provided
                if ($body_fat !== null && ($body_fat < 5 || $body_fat > 50)) {
                    $error = "Body fat percentage must be between 5-50%.";
                } elseif ($muscle_mass !== null && ($muscle_mass < 20 || $muscle_mass > 60)) {
                    $error = "Muscle mass percentage must be between 20-60%.";
                } elseif ($waist !== null && ($waist < 50 || $waist > 200)) {
                    $error = "Waist measurement must be between 50-200 cm.";
                } elseif ($hip !== null && ($hip < 60 || $hip > 250)) {
                    $error = "Hip measurement must be between 60-250 cm.";
                } else {
                    // Update user's comprehensive fitness data
                    $sql = "UPDATE users SET 
                            height = ?, 
                            weight = ?,
                            body_fat = ?,
                            muscle_mass = ?,
                            waist = ?,
                            hip = ?,
                            training_level = ?,
                            training_frequency = ?,
                            training_notes = ?,
                            updated_at = NOW()
                            WHERE id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    
                    if ($stmt) {
                        $stmt->bind_param("ddddddsssi", 
                            $height, $weight, $body_fat, $muscle_mass, 
                            $waist, $hip, $training_level, $training_frequency, 
                            $training_notes, $user_id
                        );
                        
                        if ($stmt->execute()) {
                            $success = true;
                            
                            // Clear any cached recommendations to force refresh
                            if (isset($_SESSION['cached_recommendations'])) {
                                unset($_SESSION['cached_recommendations']);
                            }
                            
                            // Store the new fitness data
                            $_SESSION['new_bmi'] = round($bmi, 1);
                            $_SESSION['new_body_fat'] = $body_fat;
                            $_SESSION['new_muscle_mass'] = $muscle_mass;
                            $_SESSION['new_training_level'] = $training_level;
                            
                            // Log the comprehensive fitness update for analytics
                            error_log("Comprehensive fitness data updated for user $user_id - Height: $height cm, Weight: $weight kg, BMI: " . round($bmi, 1) . ", Body Fat: $body_fat%, Muscle Mass: $muscle_mass%");
                            
                            if ($is_ajax) {
                                // Return JSON response for AJAX request
                                header('Content-Type: application/json');
                                echo json_encode([
                                    'success' => true,
                                    'message' => 'Fitness data updated successfully!',
                                    'bmi' => round($bmi, 1),
                                    'height' => $height,
                                    'weight' => $weight,
                                    'body_fat' => $body_fat,
                                    'muscle_mass' => $muscle_mass,
                                    'waist' => $waist,
                                    'hip' => $hip,
                                    'training_level' => $training_level,
                                    'training_frequency' => $training_frequency
                                ]);
                                exit();
                            } else {
                                header("Location: profile.php?fitness_updated=1&bmi=" . round($bmi, 1));
                                exit();
                            }
                        } else {
                            $error = "Failed to update fitness data. Database error: " . $stmt->error;
                            error_log("Database error updating fitness data for user $user_id: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $error = "Failed to prepare database statement. Database error: " . $conn->error;
                        error_log("Database prepare error for user $user_id: " . $conn->error);
                    }
                }
            }
        }
        } // Close the else block for database column check
    } else {
        // Handle regular fitness goals form submission
        // Get form data
        $height = isset($_POST['height']) ? floatval($_POST['height']) : null;
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : null;
        $target_weight = isset($_POST['target_weight']) ? floatval($_POST['target_weight']) : null;
        $fitness_goal = isset($_POST['fitness_goal']) ? $_POST['fitness_goal'] : null;
        $experience_level = isset($_POST['experience_level']) ? $_POST['experience_level'] : null;
        $preferred_workout_type = isset($_POST['preferred_workout_type']) ? $_POST['preferred_workout_type'] : null;
        $activity_level = isset($_POST['activity_level']) ? $_POST['activity_level'] : null;
        $has_medical_condition = isset($_POST['has_medical_condition']) ? intval($_POST['has_medical_condition']) : 0;
        $medical_conditions = isset($_POST['medical_conditions']) ? trim($_POST['medical_conditions']) : null;
        
        // Validate required fields
        if (empty($height) || empty($weight) || empty($fitness_goal) || empty($experience_level) || empty($activity_level)) {
            $error = "Please fill in all required fields (Height, Current Weight, Fitness Goal, Experience Level, and Activity Level).";
        } elseif ($height < 100 || $height > 250) {
            $error = "Please enter a valid height between 100-250 cm.";
        } elseif ($weight < 30 || $weight > 300) {
            $error = "Please enter a valid weight between 30-300 kg.";
        } else {
            // Calculate BMI for validation
            $height_m = $height / 100;
            $bmi = $weight / ($height_m * $height_m);
            
            // Additional BMI validation
            if ($bmi < 10 || $bmi > 60) {
                $error = "The calculated BMI seems unrealistic. Please check your height and weight values.";
            } else {
                // Update user's fitness data
                $sql = "UPDATE users SET 
                        height = ?, 
                        weight = ?, 
                        target_weight = ?, 
                        fitness_goal = ?, 
                        experience_level = ?, 
                        preferred_workout_type = ?,
                        activity_level = ?,
                        has_medical_condition = ?,
                        medical_conditions = ?
                        WHERE id = ?";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ddddsssssi", 
                        $height, $weight, $target_weight, $fitness_goal, 
                        $experience_level, $preferred_workout_type, $activity_level,
                        $has_medical_condition, $medical_conditions, $user_id
                    );
                    
                    if ($stmt->execute()) {
                        $success = true;
                        
                        // Clear any cached recommendations to force refresh
                        if (isset($_SESSION['cached_recommendations'])) {
                            unset($_SESSION['cached_recommendations']);
                        }
                        
                        // Store a flag to show that recommendations should be refreshed
                        $_SESSION['recommendations_refreshed'] = true;
                        
                        // Store the new fitness data for immediate recommendation update
                        $_SESSION['new_fitness_goal'] = $fitness_goal;
                        $_SESSION['new_experience_level'] = $experience_level;
                        $_SESSION['new_preferred_workout_type'] = $preferred_workout_type;
                        $_SESSION['new_activity_level'] = $activity_level;
                        $_SESSION['new_bmi'] = round($bmi, 1);
                        
                        // Log the fitness update for analytics
                        error_log("Fitness data updated for user $user_id - BMI: $bmi, Goal: $fitness_goal, Level: $experience_level");
                        
                        header("Location: profile.php?fitness_updated=1&refresh_recommendations=1&bmi=" . round($bmi, 1));
                        exit();
                    } else {
                        $error = "Failed to update fitness data. Database error: " . $stmt->error;
                        error_log("Database error updating fitness data for user $user_id: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    $error = "Failed to prepare database statement. Database error: " . $conn->error;
                    error_log("Database prepare error for user $user_id: " . $conn->error);
                }
            }
        }
    }
}

// If there was an error, handle it appropriately
if (!empty($error)) {
    if ($is_ajax) {
        // Return JSON error response for AJAX request
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $error
        ]);
        exit();
    } else {
        // Redirect back with error message for regular form
        header("Location: profile.php?error=" . urlencode($error));
        exit();
    }
}
?> 