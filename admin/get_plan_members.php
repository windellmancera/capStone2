<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require '../db.php';

// Check if plan_id is provided
if (!isset($_GET['plan_id']) || !is_numeric($_GET['plan_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid plan ID']);
    exit();
}

$plan_id = intval($_GET['plan_id']);

try {
    // Get members for this specific plan
    $sql = "SELECT 
                u.id,
                u.username,
                u.email,
                u.profile_picture,
                u.created_at,
                u.membership_end_date,
                CASE 
                    WHEN u.membership_end_date IS NULL OR u.membership_end_date > CURDATE() THEN 'Active'
                    ELSE 'Expired'
                END as membership_status,
                mp.name as plan_name,
                mp.price as plan_price,
                mp.duration as plan_duration
            FROM users u
            LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
            WHERE u.role = 'member' 
            AND u.selected_plan_id = ?
            ORDER BY u.username ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $members = [];
    while ($row = $result->fetch_assoc()) {
        // Format dates for display
        $row['created_at'] = date('M d, Y', strtotime($row['created_at']));
        if ($row['membership_end_date']) {
            $row['membership_end_date'] = date('M d, Y', strtotime($row['membership_end_date']));
        }
        
        // Ensure profile picture path is correct
        if ($row['profile_picture'] && !empty(trim($row['profile_picture']))) {
            // Profile pictures are stored in uploads/profile_pictures/ directory
            $profile_path = "../uploads/profile_pictures/" . $row['profile_picture'];
            
            // Debug: Log the profile picture path
            error_log("Profile picture path for user {$row['username']}: {$profile_path}");
            
            // Check if the file actually exists
            if (file_exists($profile_path)) {
                $row['profile_picture'] = "uploads/profile_pictures/" . $row['profile_picture'];
                error_log("Profile picture found for user {$row['username']}: {$row['profile_picture']}");
            } else {
                error_log("Profile picture NOT found for user {$row['username']}: {$profile_path}");
                $row['profile_picture'] = null; // Set to null if file doesn't exist
            }
        } else {
            error_log("No profile picture for user {$row['username']}");
            $row['profile_picture'] = null;
        }
        
        $members[] = $row;
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'members' => $members,
        'total_count' => count($members)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching plan members: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error fetching members. Please try again.'
    ]);
}

$conn->close();
?>
