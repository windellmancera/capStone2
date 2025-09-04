<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $update_type = $_POST['update_type'] ?? '';
    
    try {
        if ($update_type === 'demographics') {
            // Validate demographics data
            $full_name = trim($_POST['full_name'] ?? '');
            $mobile_number = trim($_POST['mobile_number'] ?? '');
            $gender = $_POST['gender'] ?? '';
            $home_address = trim($_POST['home_address'] ?? '');
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            
            // Basic validation
            if (empty($full_name) || strlen($full_name) < 2) {
                throw new Exception('Full name must be at least 2 characters long');
            }
            
            if (empty($mobile_number) || !preg_match('/^[0-9+\-\s()]{7,15}$/', $mobile_number)) {
                throw new Exception('Please enter a valid mobile number');
            }
            
            if (empty($gender) || !in_array($gender, ['male', 'female', 'other'])) {
                throw new Exception('Please select a valid gender');
            }
            
            if (empty($date_of_birth)) {
                throw new Exception('Date of birth is required');
            }
            
            // Validate age (must be at least 13)
            $birth_date = new DateTime($date_of_birth);
            $today = new DateTime();
            $age = $today->diff($birth_date)->y;
            if ($age < 13) {
                throw new Exception('You must be at least 13 years old');
            }
            
            if (empty($home_address) || strlen($home_address) < 10) {
                throw new Exception('Please provide a complete home address');
            }
            
            $sql = "UPDATE users SET 
                    full_name = ?,
                    mobile_number = ?,
                    gender = ?,
                    home_address = ?,
                    date_of_birth = ?
                    WHERE id = ?";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", 
                $full_name, 
                $mobile_number, 
                $gender, 
                $home_address, 
                $date_of_birth, 
                $user_id
            );
            
        } elseif ($update_type === 'emergency_contact') {
            // Validate emergency contact data
            $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
            $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
            $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
            
            // Basic validation
            if (empty($emergency_contact_name) || strlen($emergency_contact_name) < 2) {
                throw new Exception('Emergency contact name must be at least 2 characters long');
            }
            
            if (empty($emergency_contact_number) || !preg_match('/^[0-9+\-\s()]{7,15}$/', $emergency_contact_number)) {
                throw new Exception('Please enter a valid emergency contact number');
            }
            
            if (empty($emergency_contact_relationship) || !in_array($emergency_contact_relationship, ['spouse', 'parent', 'sibling', 'child', 'friend', 'relative', 'other'])) {
                throw new Exception('Please select a valid relationship');
            }
            
            $sql = "UPDATE users SET 
                    emergency_contact_name = ?,
                    emergency_contact_number = ?,
                    emergency_contact_relationship = ?
                    WHERE id = ?";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssss", 
                $emergency_contact_name, 
                $emergency_contact_number, 
                $emergency_contact_relationship, 
                $user_id
            );
        } else {
            throw new Exception('Invalid update type');
        }
        
        if (isset($stmt) && $stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Profile updated successfully!';
        } else {
            throw new Exception($conn->error);
        }
        
    } catch (Exception $e) {
        $response['message'] = 'Error updating profile: ' . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode($response); 