<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';

$message = '';
$messageClass = '';

// Calculate dashboard statistics
$total_members = 0;
$active_members = 0;
$today_checkins = 0;
$expired_members = 0;

try {
    $total_members = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'")->fetch_assoc()['count'];
} catch (Exception $e) {
    $total_members = 0;
}

// Function to get detailed member statistics for verification
function getDetailedMemberStats($conn) {
    $stats = [];
    
    // Total members (excluding admin)
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'");
    $stats['total_members'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Active members with detailed breakdown
    $active_query = "
        SELECT 
            COUNT(*) as count,
            SUM(CASE WHEN u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE() THEN 1 ELSE 0 END) as with_valid_end_date,
            SUM(CASE WHEN u.membership_end_date IS NULL AND u.selected_plan_id IS NOT NULL THEN 1 ELSE 0 END) as with_plan_no_end_date,
            SUM(CASE WHEN u.payment_status = 'Approved' THEN 1 ELSE 0 END) as status_active,
            SUM(CASE WHEN u.payment_status = 'Rejected' THEN 1 ELSE 0 END) as status_inactive
        FROM users u
        WHERE u.role = 'member'
    ";
    $result = $conn->query($active_query);
    $stats['active_breakdown'] = $result ? $result->fetch_assoc() : [];
    
    // Members with recent approved payments
    $payment_query = "
        SELECT COUNT(DISTINCT u.id) as count
        FROM users u
        WHERE u.role = 'member'
        AND EXISTS (
            SELECT 1 FROM payment_history ph 
            WHERE ph.user_id = u.id 
            AND ph.payment_status = 'Approved'
            AND ph.payment_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
        )
    ";
    $result = $conn->query($payment_query);
    $stats['with_recent_payments'] = $result ? $result->fetch_assoc()['count'] : 0;
    
    // Members by membership status
    $membership_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN u.membership_end_date IS NOT NULL AND u.membership_end_date > CURDATE() THEN 1 ELSE 0 END) as active_memberships,
            SUM(CASE WHEN u.membership_end_date IS NOT NULL AND u.membership_end_date <= CURDATE() THEN 1 ELSE 0 END) as expired_memberships,
            SUM(CASE WHEN u.membership_end_date IS NULL THEN 1 ELSE 0 END) as no_end_date
        FROM users u
        WHERE u.role = 'member'
    ";
    $result = $conn->query($membership_query);
    $stats['membership_breakdown'] = $result ? $result->fetch_assoc() : [];
    
    return $stats;
}

// Function to manually count active members using exact same logic as display
function countActiveMembersManually($conn) {
    $active_count = 0;
    
    $members_query = "
        SELECT 
            u.*, 
            mp.name as plan_name,
            mp.duration as plan_duration,
            (
                SELECT payment_status FROM payment_history 
                WHERE user_id = u.id 
                ORDER BY payment_date DESC LIMIT 1
            ) as latest_payment_status,
            (
                SELECT payment_date FROM payment_history 
                WHERE user_id = u.id 
                ORDER BY payment_date DESC LIMIT 1
            ) as latest_payment_date
        FROM users u
        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
        WHERE u.role = 'member'
    ";
    
    $result = $conn->query($members_query);
    if ($result) {
        while ($member = $result->fetch_assoc()) {
            $today = date('Y-m-d');
            $user_status = $member['payment_status'] ?? 'Approved';
            
            // Calculate expiry date for status check (same logic as display)
            $status_expiry = null;
            if ($member['membership_end_date']) {
                $status_expiry = $member['membership_end_date'];
            } elseif ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
                $status_expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
            }
            
            // Determine status using exact same logic as display
            if ($user_status === 'Rejected') {
                // Skip rejected users
                continue;
            } elseif ($status_expiry && $status_expiry <= $today) {
                // Skip expired memberships
                continue;
            } elseif ($status_expiry && $status_expiry > $today) {
                // Valid membership
                $active_count++;
            } elseif ($member['selected_plan_id'] && $member['latest_payment_status'] === 'Approved') {
                // Has plan and approved payment
                $active_count++;
            }
            // Note: pending and no_plan are not counted as active
        }
    }
    
    return $active_count;
}

// Get detailed stats for verification
$detailed_stats = getDetailedMemberStats($conn);

// Get manual count for verification
$manual_active_count = countActiveMembersManually($conn);

// Use the manual count for perfect accuracy
$active_members = $manual_active_count;

try {
    $today_checkins = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM attendance WHERE DATE(check_in_time) = CURDATE()")->fetch_assoc()['count'];
} catch (Exception $e) {
    $today_checkins = 0;
}

// Fixed expired members calculation
try {
    $expired_result = $conn->query("
        SELECT COUNT(*) as count 
        FROM users u
        WHERE u.role != 'admin' 
          AND (
              (u.membership_end_date IS NOT NULL AND u.membership_end_date <= CURDATE()) OR
              (u.payment_status = 'Rejected')
          )
    ");
    $expired_members = $expired_result ? $expired_result->fetch_assoc()['count'] : 0;
} catch (Exception $e) {
    $expired_members = 0;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_status') {
            $member_id = $_POST['member_id'];
            $status = $_POST['status'];
            
            $sql = "UPDATE users SET payment_status = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("si", $status, $member_id);
            
            if ($stmt->execute()) {
                $message = "Member status updated successfully!";
                $messageClass = 'success';
            } else {
                $message = "Error updating member status: " . $conn->error;
                $messageClass = 'error';
            }
        } elseif ($_POST['action'] === 'delete' && isset($_POST['member_id'])) {
            $member_id = $_POST['member_id'];
            
            // Start transaction to ensure all deletions are successful
            $conn->begin_transaction();
            
            try {
                // Helper function to safely execute delete queries
                function safeDelete($conn, $query, $member_id) {
                    $stmt = $conn->prepare($query);
                    if ($stmt === false) {
                        // Table might not exist, skip this deletion
                        return true;
                    }
                    $stmt->bind_param("i", $member_id);
                    return $stmt->execute();
                }
                
                // Delete member's feedback records
                safeDelete($conn, "DELETE FROM feedback WHERE user_id = ?", $member_id);
                
                // Delete member's gym feedback records
                safeDelete($conn, "DELETE FROM gym_feedback WHERE user_id = ?", $member_id);
                
                // Delete member's notifications
                safeDelete($conn, "DELETE FROM notifications WHERE user_id = ?", $member_id);
                
                // Delete member's notification settings
                safeDelete($conn, "DELETE FROM notification_settings WHERE user_id = ?", $member_id);
                
                // Delete member's member notes
                safeDelete($conn, "DELETE FROM member_notes WHERE user_id = ?", $member_id);
                
                // Delete member's member plans
                safeDelete($conn, "DELETE FROM member_plans WHERE user_id = ?", $member_id);
                
                // Delete member's workout plans
                safeDelete($conn, "DELETE FROM workout_plans WHERE user_id = ?", $member_id);
                
                // Delete member's exercise progress
                safeDelete($conn, "DELETE FROM exercise_progress WHERE user_id = ?", $member_id);
                
                // Delete member's workout_performance
                safeDelete($conn, "DELETE FROM workout_performance WHERE user_id = ?", $member_id);
                
                // Delete member's fitness data
                safeDelete($conn, "DELETE FROM fitness_data WHERE user_id = ?", $member_id);
                
                // Delete member's member analytics
                safeDelete($conn, "DELETE FROM member_analytics WHERE user_id = ?", $member_id);
                
                // Delete member's equipment views
                safeDelete($conn, "DELETE FROM equipment_views WHERE user_id = ?", $member_id);
                
                // Delete member's password reset tokens
                safeDelete($conn, "DELETE FROM password_reset_tokens WHERE user_id = ?", $member_id);
                
                // Delete member's attendance records
                safeDelete($conn, "DELETE FROM attendance WHERE user_id = ?", $member_id);
                
                // Delete member's payment records
                safeDelete($conn, "DELETE FROM payments WHERE user_id = ?", $member_id);
                
                // Delete member's payment history
                safeDelete($conn, "DELETE FROM payment_history WHERE user_id = ?", $member_id);
                
                // Delete member's profile picture if exists
                $get_profile_pic = "SELECT profile_picture FROM users WHERE id = ?";
                $stmt = $conn->prepare($get_profile_pic);
                if ($stmt !== false) {
                    $stmt->bind_param("i", $member_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($profile_data = $result->fetch_assoc()) {
                        if ($profile_data['profile_picture'] && file_exists('../' . $profile_data['profile_picture'])) {
                            unlink('../' . $profile_data['profile_picture']);
                        }
                    }
                }
                
                // Delete member's QR code if exists
                $qr_path = "../uploads/qr_codes/qr_" . $member_id . "_*.{png,svg}";
                array_map('unlink', glob($qr_path, GLOB_BRACE));
                
                // Finally, delete the user account
                $delete_user = "DELETE FROM users WHERE id = ? AND role != 'admin'";
                $stmt = $conn->prepare($delete_user);
                if ($stmt === false) {
                    throw new Exception("Failed to prepare user deletion query.");
                }
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                
                if ($stmt->affected_rows > 0) {
                    $conn->commit();
                    $message = "Member and all associated data deleted successfully!";
                    $messageClass = 'success';
                } else {
                    throw new Exception("Member not found or is an admin.");
                }
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error deleting member: " . $e->getMessage();
                $messageClass = 'error';
            }
        }
    }
}

// Get current user data for the top bar
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $user_sql = "SELECT username, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $result = $user_stmt->get_result();
    $current_user = $result->fetch_assoc();
}

// Fetch all members with their membership details
$members = $conn->query("
    SELECT 
        u.*, 
        mp.name as plan_name,
        mp.duration as plan_duration,
        (
            SELECT payment_status FROM payment_history 
            WHERE user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_status,
        (
            SELECT payment_date FROM payment_history 
            WHERE user_id = u.id 
            ORDER BY payment_date DESC LIMIT 1
        ) as latest_payment_date,
        (
            SELECT COUNT(*) FROM attendance WHERE user_id = u.id
        ) as attendance_count,
        (
            SELECT COUNT(*) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved'
        ) as payment_count
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role != 'admin'
    ORDER BY u.created_at DESC
") or die("Error fetching members: " . $conn->error);

if (!$members) {
    die("Error fetching members: " . $conn->error);
}

// Default profile picture and display name
$profile_picture = '../uploads/profile_pictures/default.jpg';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Manage Members';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: #4B5563;
            border-radius: 3px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: #374151;
        }
        
        /* Enhanced table styling */
        .member-table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .member-table th {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 2px solid #e2e8f0;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.75rem;
        }
        
        .member-table tbody tr {
            transition: all 0.2s ease-in-out;
        }
        
        .member-table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced status badges */
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            transition: all 0.2s ease-in-out;
        }
        
        .status-active {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-expired {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #374151;
            border: 1px solid #e5e7eb;
        }
        
        /* Enhanced action buttons */
        .action-btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .btn-view:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }
        
        .btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-delete:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }
        
        /* Enhanced statistics cards */
        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease-in-out;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 140px;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        /* Clickable statistics cards */
        .stat-card.cursor-pointer {
            cursor: pointer;
        }
        
        .stat-card.cursor-pointer:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 25px -5px rgba(0, 0, 0, 0.15);
            border-color: #ef4444;
        }
        
        /* Enhanced search and filter */
        .search-input {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 2px solid #e2e8f0;
            border-radius: 0.75rem;
            transition: all 0.2s ease-in-out;
        }
        
        /* Statistics grid alignment */
        .statistics-grid {
            align-items: stretch;
        }
        
        .statistics-grid > * {
            align-self: stretch;
        }

        /* Table scrollable styles */
        .overflow-y-auto {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .overflow-y-auto::-webkit-scrollbar {
            width: 8px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        /* Sticky header styles */
        .sticky {
            position: sticky;
        }
        
        .top-0 {
            top: 0;
        }
            display: flex;
            flex-direction: column;
        }
        
        .search-input:focus {
        
        /* Notification Animation Classes */
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: .5;
            }
        }
        
        .animate-bounce {
            animation: bounce 1s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% {
                transform: translate3d(0,0,0);
            }
            40%, 43% {
                transform: translate3d(0,-30px,0);
            }
            70% {
                transform: translate3d(0,-15px,0);
            }
            90% {
                transform: translate3d(0,-4px,0);
            }
        }
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        /* Enhanced member avatar */
        .member-avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            transition: all 0.2s ease-in-out;
        }
        
        .member-avatar:hover {
            border-color: #ef4444;
            transform: scale(1.05);
        }
        
        /* Enhanced date display */
        .date-display {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .date-display i {
            color: #9ca3af;
        }
        
        /* Enhanced activity display */
        .activity-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .activity-item i {
            width: 1rem;
            text-align: center;
        }

        /* Table scrollable styles */
        .overflow-y-auto {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .overflow-y-auto::-webkit-scrollbar {
            width: 8px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        /* Sticky header styles */
        .sticky {
            position: sticky;
        }
        
        .top-0 {
            top: 0;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed top-0 left-0 w-64 h-screen text-white flex flex-col rounded-r-xl shadow-xl transition-all duration-300" style="background: linear-gradient(to bottom, #18181b 0%, #7f1d1d 100%);">
        <div id="sidebarHeader" class="px-6 py-5 border-b border-gray-700 flex items-center space-x-2 relative">
            <img src="../image/almo.jpg" alt="Almo Fitness Gym Logo" class="w-8 h-8 rounded-full object-cover shadow sidebar-logo-img cursor-pointer" style="min-width:2rem;">
            <span class="text-lg font-bold tracking-tight whitespace-nowrap sidebar-logo-text" style="font-family: 'Segoe UI', 'Inter', sans-serif;">Almo Fitness Gym</span>
            <button id="sidebarToggle" class="ml-2 p-2 rounded-full hover:bg-gray-700/40 transition-all duration-300 focus:outline-none sidebar-toggle-btn flex items-center justify-center absolute right-4" title="Collapse sidebar" style="top:50%;transform:translateY(-50%);">
                <i class="fas fa-chevron-left transition-transform duration-300"></i>
            </button>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto sidebar-scroll">
            <a href="dashboard.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-home w-6 text-center"></i> <span>Dashboard</span>
            </a>
            <a href="manage_members.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_members.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-users w-6 text-center"></i> <span>Members</span>
            </a>
            <a href="manage_membership.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_membership.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-id-card w-6 text-center"></i> <span>Membership</span>
            </a>
            <a href="manage_trainers.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_trainers.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-user-tie w-6 text-center"></i> <span>Trainers</span>
            </a>
            <a href="manage_equipment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_equipment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-cogs w-6 text-center"></i> <span>Equipment</span>
            </a>
            <a href="manage_announcements.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_announcements.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-bullhorn w-6 text-center"></i> <span>Announcements</span>
            </a>
            <a href="manage_payments.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_payments.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-credit-card w-6 text-center"></i> <span>Payments</span>
            </a>
            <a href="manage_feedback.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_feedback.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-comments w-6 text-center"></i> <span>Feedback</span>
            </a>
            <a href="reports.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-chart-bar w-6 text-center"></i> <span>Reports</span>
            </a>
            <a href="attendance_history.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-clock w-6 text-center"></i> <span>Attendance History</span>
            </a>
        </nav>
        <div class="px-4 py-5 border-t border-gray-700 mt-auto flex flex-col space-y-2 sidebar-bottom">
            <a href="../logout.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 hover:bg-gray-700 hover:text-white sidebar-logout">
                <i class="fas fa-sign-out-alt w-6 text-center"></i> <span class="sidebar-bottom-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Top Bar -->
    <div class="w-full flex justify-center items-center mt-6 mb-2">
        <header class="shadow-2xl drop-shadow-2xl px-12 py-5 flex justify-between items-center w-full max-w-7xl rounded-2xl bg-clip-padding" style="background: linear-gradient(to right, #18181b 0%, #7f1d1d 100%);">
            <div class="flex items-center">
                <span class="text-lg sm:text-xl font-semibold text-white mr-8" style="font-family: 'Segoe UI', 'Inter', sans-serif; letter-spacing: 0.01em;">
                    <?php echo $page_title ?? 'Manage Members'; ?>
                </span>
            </div>
            <div class="flex items-center space-x-10">
                <!-- Real-Time Notification System -->
                <div class="relative">
                    <button id="notificationBtn" class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors relative">
                        <i class="fas fa-bell text-lg"></i>
                        <!-- Notification Badge -->
                        <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold hidden">
                            0
                        </span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50 max-h-96 overflow-y-auto">
                        <div class="p-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                <div class="flex items-center space-x-2">
                                    <button onclick="markAllAsRead()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                        Mark all read
                                    </button>
                                    <button onclick="clearAllNotifications()" class="text-sm text-red-600 hover:text-red-800 font-medium">
                                        Clear all
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="notificationList" class="p-2">
                            <!-- Notifications will be loaded here -->
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-bell text-3xl mb-3"></i>
                                <p>No notifications yet</p>
                            </div>
                        </div>
                        
                        <div class="p-3 border-t border-gray-100 bg-gray-50">
                            <div class="text-xs text-gray-500 text-center">
                                Real-time updates every 3 seconds
                            </div>
                            <!-- Debug Info -->
                            <div id="debugInfo" class="mt-2 text-xs text-gray-400 text-center hidden">
                                <div>Status: <span id="connectionStatus">Connecting...</span></div>
                                <div>Last Update: <span id="connectionStatus">Never</span></div>
                                <div>Notifications: <span id="notificationCount">0</span></div>
                            </div>
                            <button onclick="toggleDebug()" class="text-xs text-blue-600 hover:text-blue-800 mt-1">
                                Toggle Debug
                            </button>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <button id="profileDropdown" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-700/30 transition-colors">
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($display_name); ?></h3>
                            <p class="text-sm text-gray-200 drop-shadow">Admin</p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-300 text-sm transition-transform duration-200" id="dropdownArrow"></i>
                    </button>
                    
                    <!-- Dropdown Menu -->
                    <div id="profileMenu" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50">
                        <div class="py-2">
                            <a href="profile.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                <i class="fas fa-user mr-3 text-gray-500"></i>
                                Profile
                            </a>
                            <a href="settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                <i class="fas fa-cog mr-3 text-gray-500"></i>
                                Settings
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                                <i class="fas fa-sign-out-alt mr-3"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
    </div>

    <!-- Main Content -->
    <main class="ml-64 mt-16 p-6 transition-all duration-300">
        <div class="max-w-7xl mx-auto">
            <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Member Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6 statistics-grid">
                <a href="#member-list" onclick="showAllMembers()" class="block h-full">
                    <div class="stat-card p-6 cursor-pointer">
                        <div class="flex flex-col h-full">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-600">
                                    Total Members
                                </h3>
                                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-users text-red-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="flex-1 flex items-end">
                                <p class="text-3xl font-bold text-red-600">
                                    <?php echo $total_members; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
                <a href="#member-list" onclick="filterByStatus('active')" class="block h-full">
                    <div class="stat-card p-6 cursor-pointer">
                        <div class="flex flex-col h-full">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-600">
                                    Active Members
                                </h3>
                                <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="flex-1 flex items-end">
                                <p class="text-3xl font-bold text-green-600">
                                    <?php echo $active_members; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
                <a href="#member-list" onclick="filterByTodayCheckins(); return false;" class="block h-full">
                    <div class="stat-card p-6 cursor-pointer">
                        <div class="flex flex-col h-full">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-600">
                                    Today's Check-ins
                                </h3>
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-sign-in-alt text-blue-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="flex-1 flex items-end">
                                <p class="text-3xl font-bold text-blue-600">
                                    <?php echo $today_checkins; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
                <a href="#member-list" onclick="filterByStatus('expired')" class="block h-full">
                    <div class="stat-card p-6 cursor-pointer">
                        <div class="flex flex-col h-full">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-600">
                                    Expired Memberships
                                </h3>
                                <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-exclamation-triangle text-orange-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="flex-1 flex items-end">
                                <p class="text-3xl font-bold text-orange-600">
                                    <?php echo $expired_members; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="#member-list" onclick="filterByStatus('none')" class="block h-full">
                    <div class="stat-card p-6 cursor-pointer">
                        <div class="flex flex-col h-full">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-600">
                                    No Membership
                                </h3>
                                <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-user-slash text-gray-600 text-xl"></i>
                                </div>
                            </div>
                            <div class="flex-1 flex items-end">
                                <p class="text-3xl font-bold text-gray-600">
                                    <?php 
                                    // Count members with no plan or pending status
                                    $no_membership_result = $conn->query("
                                        SELECT COUNT(*) as count 
                                        FROM users u 
                                        WHERE u.role = 'member' 
                                        AND (u.selected_plan_id IS NULL OR u.selected_plan_id = '')
                                    ");
                                    $no_membership_count = $no_membership_result ? $no_membership_result->fetch_assoc()['count'] : 0;
                                    echo $no_membership_count;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Enhanced Members List -->
            <div id="member-list" class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden" style="scroll-behavior: smooth;">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center space-y-4 md:space-y-0">
                        <div class="flex items-center space-x-3">
                            <div class="bg-red-100 p-2 rounded-xl">
                                <i class="fas fa-users text-red-600 text-lg"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800">Member List</h2>
                                <p class="text-sm text-gray-600">Manage and monitor all gym members</p>
                            </div>

                        </div>
                        <div class="flex flex-col sm:flex-row space-y-2 sm:space-y-0 sm:space-x-3 w-full md:w-auto">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" id="searchMember" placeholder="Search members..." 
                                       class="search-input pl-12 pr-4 py-3 w-full">
                            </div>
                            <select id="membershipFilter" class="search-input px-4 py-3 focus:outline-none">
                                <option value="all">All Members</option>
                                <option value="active">Active</option>
                                <option value="expired">Expired</option>
                                <option value="none">No Membership Plan</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <div class="max-h-96 overflow-y-auto" style="scroll-behavior: smooth;">
                        <table class="w-full member-table">
                            <thead class="sticky top-0 z-10 bg-white">
                                <tr>
                                    <th class="px-6 py-4 text-left">Member</th>
                                    <th class="px-6 py-4 text-left">Membership</th>
                                    <th class="px-6 py-4 text-left">Status</th>
                                    <th class="px-6 py-4 text-left">Today's Check-in</th>
                                    <th class="px-6 py-4 text-left">Join Date</th>
                                    <th class="px-6 py-4 text-left">Expiry Date</th>
                                    <th class="px-6 py-4 text-left">Activity</th>
                                    <th class="px-6 py-4 text-left">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                            <?php 
                            if ($members && $members->num_rows > 0): 
                                while($member = $members->fetch_assoc()): 
                            ?>
                                <tr class="hover:bg-gray-50 transition-all duration-200">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <?php if ($member['profile_picture']): ?>
                                                <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($member['profile_picture']); ?>" 
                                                     alt="<?php echo htmlspecialchars($member['username']); ?>" 
                                                     class="member-avatar object-cover mr-4">
                                            <?php else: ?>
                                                <div class="member-avatar bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center mr-4">
                                                    <span class="text-white text-sm font-bold">
                                                        <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-semibold text-gray-900 text-lg"><?php echo htmlspecialchars($member['username']); ?></div>
                                                <div class="text-sm text-gray-500 flex items-center">
                                                    <i class="fas fa-envelope text-xs mr-2"></i>
                                                    <?php echo htmlspecialchars($member['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div>
                                            <div class="font-medium text-sm">
                                                <?php echo $member['plan_name'] ? htmlspecialchars($member['plan_name']) : 'No Plan'; ?>
                                            </div>
                                            <?php 
                                            // Calculate expiry date - prioritize membership_end_date, then calculate from payment
                                            $expiry = null;
                                            if ($member['membership_end_date']) {
                                                $expiry = $member['membership_end_date'];
                                            } elseif ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
                                                $expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
                                            }
                                            ?>
                                            <?php if ($expiry): ?>
                                                <div class="text-sm text-gray-500">
                                                    Expires: <?php echo date('M d, Y', strtotime($expiry)); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $today = date('Y-m-d');
                                                                                    $status = 'expired';
                                        $statusClass = 'bg-gray-100 text-gray-800';
                                        
                                        // Calculate expiry date for status check
                                        $status_expiry = null;
                                        if ($member['membership_end_date']) {
                                            $status_expiry = $member['membership_end_date'];
                                        } elseif ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
                                            $status_expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
                                        }
                                        
                                        // More accurate status determination
                                        $user_status = $member['payment_status'] ?? 'Approved'; // Default to active if status not set
                                        
                                        if ($user_status === 'Rejected') {
                                            // User explicitly marked as rejected
                                            $status = 'expired';
                                            $statusClass = 'bg-red-100 text-red-800';
                                        } elseif ($status_expiry && $status_expiry <= $today) {
                                            // Membership has expired
                                            $status = 'expired';
                                            $statusClass = 'bg-red-100 text-red-800';
                                        } elseif ($status_expiry && $status_expiry > $today) {
                                            // Membership is still valid
                                            $status = 'active';
                                            $statusClass = 'bg-green-100 text-green-800';
                                        } elseif ($member['selected_plan_id'] && $member['latest_payment_status'] === 'Approved') {
                                            // Has a plan and approved payment but no expiry date (unlimited or ongoing)
                                            $status = 'active';
                                            $statusClass = 'bg-green-100 text-green-800';
                                        } elseif ($member['selected_plan_id']) {
                                            // Has a plan but no payment or expired payment
                                            $status = 'pending';
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                        } else {
                                            // No plan selected
                                            $status = 'no_plan';
                                            $statusClass = 'bg-gray-100 text-gray-800';
                                        }
                                        ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $statusClass; ?> border">
                                            <span class="w-1.5 h-1.5 rounded-full mr-1.5 <?php 
                                                echo $status === 'active' ? 'bg-green-400' : 
                                                    ($status === 'expired' ? 'bg-red-400' : 
                                                    ($status === 'pending' ? 'bg-yellow-400' : 'bg-gray-400')); 
                                            ?>"></span>
                                            <?php 
                                                $status_display = match($status) {
                                                    'active' => 'Active',
                                                    'expired' => 'Expired',
                                                    'pending' => 'Pending',
                                                    'no_plan' => 'No Plan',
                                                    default => 'Expired'
                                                };
                                                echo $status_display;
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        // Check if member checked in today
                                        $today_checkin_sql = "SELECT COUNT(*) as today_count FROM attendance WHERE user_id = ? AND DATE(check_in_time) = CURDATE()";
                                        $today_stmt = $conn->prepare($today_checkin_sql);
                                        $today_stmt->bind_param("i", $member['id']);
                                        $today_stmt->execute();
                                        $today_result = $today_stmt->get_result();
                                        $today_count = $today_result->fetch_assoc()['today_count'];
                                        $today_stmt->close();
                                        
                                        if ($today_count > 0): ?>
                                            <div class="flex items-center text-sm text-green-600">
                                                <i class="fas fa-check-circle text-green-500 mr-1.5"></i>
                                                <span class="font-medium">Checked in today</span>
                                            </div>
                                        <?php else: ?>
                                            <div class="flex items-center text-sm text-gray-500">
                                                <i class="fas fa-times-circle text-gray-400 mr-1.5"></i>
                                                <span>No check-in today</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center text-sm text-gray-600">
                                            <i class="fas fa-calendar-plus text-gray-400 mr-1.5"></i>
                                            <?php 
                                            // Use membership_start_date if available, otherwise use created_at
                                            $join_date = $member['membership_start_date'] ? $member['membership_start_date'] : $member['created_at'];
                                            echo date('M d, Y', strtotime($join_date)); 
                                            ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php 
                                        // Calculate expiry date for display - prioritize membership_end_date
                                        $display_expiry = null;
                                        if ($member['membership_end_date']) {
                                            $display_expiry = $member['membership_end_date'];
                                        } elseif ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
                                            $display_expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
                                        }
                                        
                                        if ($display_expiry): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-calendar-times text-gray-400 mr-1.5"></i>
                                                <span class="<?php echo strtotime($display_expiry) < time() ? 'text-red-600 font-medium' : 'text-gray-900'; ?>">
                                                <?php echo date('M d, Y', strtotime($display_expiry)); ?>
                                            </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-sm">No membership</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="space-y-0.5">
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-sign-in-alt text-blue-500 mr-1.5"></i>
                                                <span class="text-gray-700"><?php echo $member['attendance_count'] ?? 0; ?> check-ins</span>
                                            </div>
                                            <div class="flex items-center text-sm">
                                                <i class="fas fa-credit-card text-green-500 mr-1.5"></i>
                                                <span class="text-gray-700"><?php echo $member['payment_count'] ?? 0; ?> payments</span>
                                            </div>
                                        </div>
                                    </td>
                                                                         <td class="px-4 py-3">
                                         <div class="flex items-center justify-center space-x-2">
                                             <button onclick="openMemberModal(<?php echo $member['id']; ?>)" 
                                                     class="action-btn btn-view text-xs">
                                                 <i class="fas fa-eye mr-1"></i>
                                                 View
                                             </button>
                                             <form action="" method="POST" class="inline delete-form" onsubmit="return confirm('Are you sure you want to delete this member? This action cannot be undone.');">
                                                 <input type="hidden" name="action" value="delete">
                                                 <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                 <button type="submit" class="action-btn btn-delete text-xs">
                                                     <i class="fas fa-trash mr-1"></i>
                                                     Delete
                                                 </button>
                                             </form>
                                         </div>
                                     </td>
                                </tr>
                            <?php 
                                endwhile; 
                            else: 
                            ?>
                                <tr>
                                    <td colspan="8" class="px-4 py-3 text-center text-gray-500">No members found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Member Details Modal -->
    <div id="memberDetailsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 hidden">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-lg p-6 relative">
            <button id="closeMemberModal" class="absolute top-2 right-2 text-gray-400 hover:text-gray-700 text-2xl">&times;</button>
            <h2 class="text-xl font-semibold mb-4">Member Details</h2>
            <div id="memberDetailsContent" class="space-y-2">
                <div class="text-center text-gray-500">Loading...</div>
            </div>
        </div>
    </div>

    <script>
        // Profile Dropdown Toggle
        const profileDropdown = document.getElementById('profileDropdown');
        const profileMenu = document.getElementById('profileMenu');
        const dropdownArrow = document.getElementById('dropdownArrow');

        profileDropdown.addEventListener('click', () => {
            profileMenu.classList.toggle('opacity-0');
            profileMenu.classList.toggle('invisible');
            profileMenu.classList.toggle('scale-95');
            dropdownArrow.classList.toggle('rotate-180');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!profileDropdown.contains(e.target)) {
                profileMenu.classList.add('opacity-0', 'invisible', 'scale-95');
                dropdownArrow.classList.remove('rotate-180');
            }
        });

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const toggleIcon = sidebarToggle.querySelector('i');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            toggleIcon.classList.toggle('rotate-180');
            
            // Toggle visibility of text elements
            document.querySelectorAll('.sidebar-logo-text, nav span, .sidebar-bottom-text').forEach(el => {
                el.classList.toggle('hidden');
            });
            
            // Toggle main content margin
            const mainContent = document.querySelector('main');
            if (mainContent) {
                mainContent.classList.toggle('ml-64');
                mainContent.classList.toggle('ml-20');
            }
        });

        // Member Search and Filter
        const searchInput = document.getElementById('searchMember');
        const membershipFilter = document.getElementById('membershipFilter');
        const memberRows = document.querySelectorAll('tbody tr');

        function filterMembers() {
            const searchTerm = searchInput.value.toLowerCase();
            const filterValue = membershipFilter.value;
            
            console.log('Filtering members - Search:', searchTerm, 'Filter:', filterValue);

            memberRows.forEach((row, index) => {
                // Try multiple selectors to find the elements
                const memberName = row.querySelector('.font-semibold') || row.querySelector('div.font-semibold');
                const memberEmail = row.querySelector('.text-gray-500') || row.querySelector('div.text-gray-500');
                const memberStatus = row.querySelector('span.rounded-full') || row.querySelector('.rounded-full');
                
                // Get the text content from the status element
                let statusText = '';
                if (memberStatus) {
                    // Get the text content and clean it up
                    statusText = memberStatus.textContent.trim().toLowerCase();
                    // Remove any extra whitespace and newlines
                    statusText = statusText.replace(/\s+/g, ' ').trim();
                }

                // Debug logging for each row
                console.log(`Row ${index}: Status text found: "${statusText}"`);

                if (!memberName || !memberEmail || !memberStatus) {
                    console.log(`Row ${index}: Missing required elements`);
                    return;
                }

                const nameText = memberName.textContent.toLowerCase();
                const emailText = memberEmail.textContent.toLowerCase();

                const matchesSearch = nameText.includes(searchTerm) || emailText.includes(searchTerm);
                const matchesFilter = filterValue === 'all' || 
                    (filterValue === 'active' && statusText === 'active') ||
                    (filterValue === 'expired' && statusText === 'expired') ||
                    (filterValue === 'none' && (statusText === 'no plan' || statusText === 'pending'));
                
                // Debug logging for filter matching
                console.log(`Row ${index}: Filter "${filterValue}" matches: ${matchesFilter}, Status: "${statusText}"`);

                row.style.display = matchesSearch && matchesFilter ? '' : 'none';
            });
            
            // Log the results
            const visibleRows = document.querySelectorAll('tbody tr[style=""]').length;
            const hiddenRows = document.querySelectorAll('tbody tr[style="display: none;"]').length;
            console.log(`Filter results - Visible: ${visibleRows}, Hidden: ${hiddenRows}`);
        }
        searchInput.addEventListener('input', filterMembers);
        membershipFilter.addEventListener('change', filterMembers);

        // Function to filter members by status when clicking statistics cards
        function filterByStatus(status) {
            console.log('Filtering by status:', status);
            
            // Set the membership filter dropdown to the selected status
            membershipFilter.value = status;
            
            // Clear the search input when filtering by status
            searchInput.value = '';
            
            // Trigger the filter function
            filterMembers();
            
            // Scroll to the member list
            document.getElementById('member-list').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
        }
        
        // Function to show all members (reset filters)
        function showAllMembers() {
            console.log('Showing all members');
            
            // Reset filter dropdown
            membershipFilter.value = 'all';
            
            // Clear search input
            searchInput.value = '';
            
            // Show all rows
            memberRows.forEach(row => {
                row.style.display = '';
            });
            
            // Scroll to member list
            document.getElementById('member-list').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
            
            console.log(`All ${memberRows.length} members are now visible`);
        }
        
        // Function to filter members by today's check-ins
        function filterByTodayCheckins() {
            console.log('Filtering by today\'s check-ins');
            
            // Clear search input
            searchInput.value = '';
            
            // Filter rows to show only members who checked in today
            memberRows.forEach((row, index) => {
                // Look for the "Today's Check-in" column (4th column, index 3)
                const todayCheckinCell = row.querySelector('td:nth-child(4)');
                
                if (todayCheckinCell) {
                    const checkinText = todayCheckinCell.textContent.toLowerCase();
                    const hasTodayCheckin = checkinText.includes('checked in today');
                    
                    // Show row if it has today's check-in
                    row.style.display = hasTodayCheckin ? '' : 'none';
                } else {
                    // If no check-in info found, hide the row
                    row.style.display = 'none';
                }
            });
            
            // Scroll to the member list
            document.getElementById('member-list').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
            
            console.log('Filtered to show today\'s check-ins');
        }
        

        

        

        

        


        // Add JavaScript for delete confirmation
        document.addEventListener('DOMContentLoaded', function() {
            // Set default filter to show all members
            membershipFilter.value = 'all';
            
            const deleteForms = document.querySelectorAll('.delete-form');
            deleteForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to delete this member? This action will:\n\n' +
                               '- Remove their account permanently\n' +
                               '- Delete all their attendance records\n' +
                               '- Delete all their payment history\n' +
                               '- Remove their profile picture and QR code\n\n' +
                               'This action cannot be undone.')) {
                        e.preventDefault();
                    }
                });
            });
        });

        // Modal logic for member details
        function openMemberModal(memberId) {
            console.log('Opening modal for member ID:', memberId);
            const modal = document.getElementById('memberDetailsModal');
            const content = document.getElementById('memberDetailsContent');
            modal.classList.remove('hidden');
            content.innerHTML = '<div class="text-center text-gray-500">Loading...</div>';
            
            const url = `get_member.php?id=${memberId}`;
            console.log('Fetching from URL:', url);
            
            fetch(url)
                .then(res => {
                    console.log('Response status:', res.status);
                    console.log('Response headers:', res.headers);
                    
                    // Check if response is JSON
                    const contentType = res.headers.get('content-type');
                    console.log('Content-Type:', contentType);
                    
                    if (!contentType || !contentType.includes('application/json')) {
                        // Log the actual response text for debugging
                        return res.text().then(text => {
                            console.error('Response is not JSON. Raw response:', text);
                            throw new Error('Response is not JSON. Server might be returning HTML or an error page.');
                        });
                    }
                    
                    if (!res.ok) {
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    
                    return res.json();
                })
                .then(data => {
                    console.log('Response data:', data);
                    if (data.success) {
                        const statusBadgeClass = data.membership_status === 'Active' ? 'bg-green-100 text-green-800' : 
                                               data.membership_status === 'Expired' ? 'bg-red-100 text-red-800' : 
                                               'bg-gray-100 text-gray-800';
                        
                        const paymentBadgeClass = data.payment_status === 'Approved' ? 'bg-green-100 text-green-800' :
                                                data.payment_status === 'Pending' ? 'bg-yellow-100 text-yellow-800' :
                                                data.payment_status === 'Rejected' ? 'bg-red-100 text-red-800' :
                                                'bg-gray-100 text-gray-800';
                        
                        content.innerHTML = `
                            <div class="space-y-6">
                                <!-- Header with Avatar -->
                                <div class="flex items-center space-x-4 pb-4 border-b border-gray-200">
                                    <div class="w-16 h-16 rounded-full overflow-hidden flex items-center justify-center">
                                        ${data.profile_picture && data.profile_picture !== 'N/A' ? 
                                            `<img src="../uploads/profile_pictures/${data.profile_picture}" alt="${data.full_name}" class="w-full h-full object-cover">` :
                                            `<div class="w-full h-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-2xl font-bold">
                                                ${data.full_name.charAt(0).toUpperCase()}
                                            </div>`
                                        }
                                    </div>
                                    <div>
                                        <h3 class="text-xl font-bold text-gray-900">${data.full_name}</h3>
                                        <p class="text-gray-600">Member since ${data.join_date}</p>
                                    </div>
                                </div>
                                
                                <!-- Personal Information -->
                                <div class="space-y-4">
                                    <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                        </svg>
                                        Personal Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 002 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <div>
                                                <p class="text-sm text-gray-500">Email</p>
                                                <p class="font-medium text-gray-900">${data.email}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                            </svg>
                                            <div>
                                                <p class="text-sm text-gray-500">Contact Number</p>
                                                <p class="font-medium text-gray-900">${data.contact_number}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Membership Details -->
                                <div class="space-y-4">
                                    <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Membership Details
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                                            <div class="flex items-center justify-between mb-2">
                                                <p class="text-sm text-blue-600 font-medium">Membership Type</p>
                                            </div>
                                            <p class="text-lg font-semibold text-blue-900">${data.membership_type}</p>
                                        </div>
                                        <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                                            <div class="flex items-center justify-between mb-2">
                                                <p class="text-sm text-green-600 font-medium">Status</p>
                                            </div>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusBadgeClass}">
                                                ${data.membership_status}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 002 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <div>
                                                <p class="text-sm text-gray-500">Join Date</p>
                                                <p class="font-medium text-gray-900">${data.join_date}</p>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                            <div>
                                                <p class="text-sm text-gray-500">Expiration Date</p>
                                                <p class="font-medium text-gray-900">${data.expiration_date}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Payment & Activity -->
                                <div class="space-y-4">
                                    <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                                        </svg>
                                        Payment & Activity
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="p-4 bg-purple-50 rounded-lg border border-purple-200">
                                            <div class="flex items-center justify-between mb-2">
                                                <p class="text-sm text-purple-600 font-medium">Payment Status</p>
                                            </div>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${paymentBadgeClass}">
                                                ${data.payment_status}
                                            </span>
                                        </div>
                                        <div class="p-4 bg-orange-50 rounded-lg border border-orange-200">
                                            <div class="flex items-center justify-between mb-2">
                                                <p class="text-sm text-orange-600 font-medium">Attendance</p>
                                            </div>
                                            <p class="text-lg font-semibold text-orange-900">
                                                ${data.attendance_record && data.attendance_record !== 'No attendance records' ? 
                                                  data.attendance_record.split('<br>').length + ' check-ins' : 
                                                  'No check-ins'}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Attendance -->
                                ${data.attendance_record && data.attendance_record !== 'No attendance records' ? `
                                <div class="space-y-4">
                                    <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                        <svg class="w-5 h-5 mr-2 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                        </svg>
                                        Recent Attendance
                                    </h4>
                                    <div class="bg-gray-50 rounded-lg p-4 max-h-32 overflow-y-auto">
                                        <div class="space-y-2 text-sm">
                                            ${data.attendance_record.split('<br>').map(record => `
                                                <div class="flex items-center space-x-2">
                                                    <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                                    <span class="text-gray-700">${record}</span>
                                                </div>
                                            `).join('')}
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                        `;
                    } else {
                        content.innerHTML = `<div class='text-center text-red-500'>${data.message || 'Failed to load member details.'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    let errorMessage = 'Failed to load member details.';
                    
                    if (error.message.includes('Response is not JSON')) {
                        errorMessage = 'Server returned an error page instead of member data. Please check the server logs.';
                    } else if (error.message.includes('HTTP error')) {
                        errorMessage = `Server error: ${error.message}`;
                    } else if (error.message.includes('Failed to fetch')) {
                        errorMessage = 'Network error: Unable to connect to the server.';
                    } else {
                        errorMessage = `Error: ${error.message}`;
                    }
                    
                    content.innerHTML = `
                        <div class='text-center text-red-500 p-4'>
                            <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
                            <p class="font-medium">${errorMessage}</p>
                            <div class="mt-2 space-y-2">
                                <button onclick="openMemberModal(${memberId})" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600">
                                    Try Again
                                </button>
                                <button onclick="testJsonEndpoint()" class="ml-2 px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">
                                    Test JSON Endpoint
                                </button>
                            </div>
                        </div>
                    `;
                });
        }
        
        // Function to test JSON endpoint
        function testJsonEndpoint() {
            console.log('Testing JSON endpoint...');
            fetch('test_json.php')
                .then(res => res.json())
                .then(data => {
                    console.log('Test JSON response:', data);
                    alert('JSON endpoint test successful! Check console for details.');
                })
                .catch(error => {
                    console.error('JSON test failed:', error);
                    alert('JSON endpoint test failed! Check console for details.');
                });
        }
        document.getElementById('closeMemberModal').onclick = function() {
            document.getElementById('memberDetailsModal').classList.add('hidden');
        };
        document.getElementById('memberDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) this.classList.add('hidden');
        });
        // Attach modal to View buttons
        document.querySelectorAll('a[href^="view_member.php?id="]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const url = new URL(this.href, window.location.origin);
                const memberId = url.searchParams.get('id');
                openMemberModal(memberId);
            });
        });
        
        // Alternative approach - attach to all View buttons
        document.addEventListener('click', function(e) {
            if (e.target.textContent === 'View' && e.target.tagName === 'A') {
                e.preventDefault();
                const href = e.target.getAttribute('href');
                if (href && href.includes('view_member.php?id=')) {
                    const memberId = href.split('=')[1];
                    openMemberModal(memberId);
                }
            }
        });
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_members.php...');
        
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationList = document.getElementById('notificationList');
        
        let notifications = [];
        let unreadCount = 0;
        let eventSource = null;
        
        if (!notificationBtn || !notificationDropdown) {
            console.error('Notification elements not found!');
        } else {
            // Connect to real-time server
            function connectToRealTimeServer() {
                if (eventSource) {
                    eventSource.close();
                }
                
                console.log('Connecting to admin real-time notification server...');
                eventSource = new EventSource('real_time_server.php');
                
                eventSource.onopen = function(event) {
                    console.log('✅ Connected to admin real-time notifications');
                    showNotificationAction('Connected to real-time notifications! 🚀', 'success');
                    updateDebugInfo('Connected', unreadCount);
                };
                
                eventSource.addEventListener('notifications', function(event) {
                    const data = JSON.parse(event.data);
                    console.log('🔄 Real-time notifications received:', data);
                    
                    notifications = data.notifications;
                    unreadCount = data.unread_count;
                    
                    updateBadge();
                    renderNotifications();
                    updateDebugInfo('Connected', unreadCount);
                });
                
                eventSource.addEventListener('count_update', function(event) {
                    const data = JSON.parse(event.data);
                    const newCount = data.unread_count;
                    if (newCount !== unreadCount) {
                        unreadCount = newCount;
                        updateBadge();
                        updateDebugInfo('Connected', unreadCount);
                    }
                });
                
                eventSource.addEventListener('error', function(event) {
                    console.error('❌ SSE Error:', event);
                    updateDebugInfo('Error - Reconnecting', unreadCount);
                    setTimeout(connectToRealTimeServer, 5000);
                });
                
                eventSource.onerror = function(event) {
                    console.error('❌ SSE Connection error:', event);
                    eventSource.close();
                    setTimeout(connectToRealTimeServer, 5000);
                };
            }
            
            // Initialize real-time connection
            connectToRealTimeServer();
            
            // Update badge
            function updateBadge() {
                if (unreadCount > 0) {
                    notificationBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    notificationBadge.classList.remove('hidden');
                    notificationBadge.classList.add('animate-pulse');
                } else {
                    notificationBadge.classList.add('hidden');
                    notificationBadge.classList.remove('animate-pulse');
                }
            }
            
            // Render notifications
            function renderNotifications() {
                if (notifications.length === 0) {
                    notificationList.innerHTML = `
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-bell text-3xl mb-3"></i>
                            <p>No notifications</p>
                        </div>
                    `;
                    return;
                }
                
                notificationList.innerHTML = notifications.map(notification => `
                    <div class="notification-item p-3 border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition-colors cursor-pointer" 
                         data-notification-id="${notification.id}" 
                         data-notification-type="${notification.type}">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center ${getTypeColor(notification.type)}">
                                    <i class="${getTypeIcon(notification.type)} text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-medium text-gray-900">${notification.title}</h4>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeBadgeColor(notification.type)}">
                                        ${notification.priority}
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mt-1">${notification.message}</p>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-gray-400">${getTimeAgo(notification.timestamp)}</span>
                                    <button onclick="event.stopPropagation(); markAsRead('${notification.id}')" 
                                            class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                        Mark read
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                // Add click event to notification items
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const id = this.dataset.notificationId;
                        const type = this.dataset.notificationType;
                        handleNotificationClick(id, type);
                    });
                });
            }
            
            // Helper functions
            function getTypeIcon(type) {
                switch (type) {
                    case 'success': return 'fas fa-check-circle';
                    case 'warning': return 'fas fa-exclamation-triangle';
                    case 'error': return 'fas fa-times-circle';
                    case 'alert': return 'fas fa-bell';
                    default: return 'fas fa-info-circle';
                }
            }
            
            function getTypeColor(type) {
                switch (type) {
                    case 'success': return 'bg-green-500';
                    case 'warning': return 'bg-yellow-500';
                    case 'error': return 'bg-red-500';
                    case 'alert': return 'bg-blue-500';
                    default: return 'bg-gray-500';
                }
            }
            
            function getTypeBadgeColor(type) {
                switch (type) {
                    case 'success': return 'bg-green-100 text-green-800';
                    case 'warning': return 'bg-yellow-100 text-yellow-800';
                    case 'error': return 'bg-red-100 text-red-800';
                    case 'alert': return 'bg-blue-100 text-blue-800';
                    default: return 'bg-gray-100 text-gray-800';
                }
            }
            
            function getTimeAgo(timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = now - timestamp;
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }
            
            function handleNotificationClick(id, type) {
                markAsRead(id);
                showNotificationAction('Notification action triggered! ✅', 'success');
                
                const item = document.querySelector(`[data-notification-id="${id}"]`);
                if (item) {
                    item.style.border = '2px solid #3b82f6';
                    item.style.backgroundColor = '#eff6ff';
                    setTimeout(() => {
                        item.style.border = '';
                        item.style.backgroundColor = '';
                    }, 2000);
                }
            }
            
            function showNotificationAction(message, type) {
                const actionDiv = document.createElement('div');
                actionDiv.className = `fixed top-4 right-4 z-50 px-4 py-2 rounded-lg shadow-lg text-white font-medium transform transition-all duration-300 ${
                    type === 'success' ? 'bg-green-500' :
                    type === 'warning' ? 'bg-yellow-500' :
                    type === 'error' ? 'bg-red-500' :
                    'bg-blue-500'
                }`;
                actionDiv.textContent = message;
                
                document.body.appendChild(actionDiv);
                
                setTimeout(() => {
                    actionDiv.style.transform = 'translateX(0)';
                }, 100);
                
                setTimeout(() => {
                    actionDiv.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        document.body.removeChild(actionDiv);
                    }, 300);
                }, 3000);
            }
            
            function toggleDropdown() {
                const isVisible = !notificationDropdown.classList.contains('invisible');
                
                if (isVisible) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                } else {
                    notificationDropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
                }
            }
            
            // Add click event
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                }
            });
            
            // Clean up on page unload
            window.addEventListener('beforeunload', function() {
                if (eventSource) {
                    eventSource.close();
                }
            });
            
            console.log('Real-time SSE notification system initialized successfully for manage_members.php!');
        }
        
        // Global notification action functions
        function markAsRead(notificationId) {
            console.log('Marking notification as read:', notificationId);
            
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `notification_id=${notificationId}&notification_type=general`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ Notification marked as read');
                    
                    if (unreadCount > 0) {
                        unreadCount--;
                        updateBadge();
                    }
                    
                    notifications = notifications.filter(n => n.id !== notificationId);
                    renderNotifications();
                    
                    showNotificationAction('Notification marked as read! ✅', 'success');
                } else {
                    console.error('❌ Failed to mark notification as read:', data.error);
                    showNotificationAction('Failed to mark as read! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error marking notification as read:', error);
                showNotificationAction('Error marking as read! ❌', 'error');
            });
        }
        
        function markAllAsRead() {
            console.log('Marking all notifications as read');
            
            fetch('notification_sync.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ All notifications marked as read');
                    
                    unreadCount = 0;
                    notifications = [];
                    updateBadge();
                    renderNotifications();
                    
                    showNotificationAction('All notifications marked as read! ✅', 'success');
                } else {
                    console.error('❌ Failed to mark all notifications as read:', data.error);
                    showNotificationAction('Failed to mark all as read! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error marking all notifications as read:', error);
                showNotificationAction('Error marking all as read! ❌', 'error');
            });
        }
        
        function clearAllNotifications() {
            console.log('Clearing all notifications');
            
            if (!confirm('Are you sure you want to clear all notifications? This action cannot be undone.')) {
                return;
            }
            
            fetch('notification_sync.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=clear_all'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ All notifications cleared');
                    
                    unreadCount = 0;
                    notifications = [];
                    updateBadge();
                    renderNotifications();
                    
                    showNotificationAction('All notifications cleared! ✅', 'success');
                } else {
                    console.error('❌ Failed to clear all notifications:', data.error);
                    showNotificationAction('Failed to clear all! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error clearing all notifications:', error);
                showNotificationAction('Error clearing all! ❌', 'error');
            });
        }
        
        // Debug functions
        function toggleDebug() {
            const debugInfo = document.getElementById('debugInfo');
            debugInfo.classList.toggle('hidden');
        }
        
        function updateDebugInfo(status, count) {
            const connectionStatus = document.getElementById('connectionStatus');
            const lastUpdate = document.getElementById('lastUpdate');
            const notificationCount = document.getElementById('notificationCount');
            
            if (connectionStatus) connectionStatus.textContent = status;
            if (lastUpdate) lastUpdate.textContent = new Date().toLocaleTimeString();
            if (notificationCount) notificationCount.textContent = count;
        }
    </script>
</body>
</html>
