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
            // Update demographics
            $full_name = trim($_POST['full_name']);
            $mobile_number = trim($_POST['mobile_number']);
            $gender = $_POST['gender'];
            $home_address = trim($_POST['home_address']);
            $date_of_birth = $_POST['date_of_birth'];
            
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
            // Update emergency contact
            $emergency_contact_name = trim($_POST['emergency_contact_name']);
            $emergency_contact_number = trim($_POST['emergency_contact_number']);
            $emergency_contact_relationship = trim($_POST['emergency_contact_relationship']);
            
            $sql = "UPDATE users SET 
                    emergency_contact_name = ?,
                    emergency_contact_number = ?,
                    emergency_contact_relationship = ?
                    WHERE id = ?";
                    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssi", 
                $emergency_contact_name, 
                $emergency_contact_number, 
                $emergency_contact_relationship, 
                $user_id
            );
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