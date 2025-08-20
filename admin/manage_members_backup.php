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
$total_members = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'")->fetch_assoc()['count'];
$active_members = $conn->query("
    SELECT COUNT(*) as count
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN (
        SELECT user_id, payment_status, payment_date
        FROM payment_history
        WHERE payment_status = 'Approved'
        ORDER BY payment_date DESC
    ) ph ON ph.user_id = u.id
    WHERE u.role != 'admin'
      AND ph.payment_status = 'Approved'
      AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) >= CURDATE()
")->fetch_assoc()['count'];
$today_checkins = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM attendance WHERE DATE(check_in_time) = CURDATE()")->fetch_assoc()['count'];
$expired_members = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin' AND membership_end_date IS NOT NULL AND membership_end_date < CURDATE()")->fetch_assoc()['count'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_status') {
            $member_id = $_POST['member_id'];
            $status = $_POST['status'];
            
            $sql = "UPDATE users SET status = ? WHERE id = ?";
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
                // Delete member's attendance records
                $delete_attendance = "DELETE FROM attendance WHERE user_id = ?";
                $stmt = $conn->prepare($delete_attendance);
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                
                // Delete member's payment records
                $delete_payments = "DELETE FROM payments WHERE user_id = ?";
                $stmt = $conn->prepare($delete_payments);
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                
                // Delete member's profile picture if exists
                $get_profile_pic = "SELECT profile_picture FROM users WHERE id = ?";
                $stmt = $conn->prepare($get_profile_pic);
                $stmt->bind_param("i", $member_id);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($profile_data = $result->fetch_assoc()) {
                    if ($profile_data['profile_picture'] && file_exists('../' . $profile_data['profile_picture'])) {
                        unlink('../' . $profile_data['profile_picture']);
                    }
                }
                
                // Delete member's QR code if exists
                $qr_path = "../uploads/qr_codes/qr_" . $member_id . "_*.{png,svg}";
                array_map('unlink', glob($qr_path, GLOB_BRACE));
                
                // Finally, delete the user account
                $delete_user = "DELETE FROM users WHERE id = ? AND role != 'admin'";
                $stmt = $conn->prepare($delete_user);
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
                <div class="relative">
                    <button class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors">
                        <i class="fas fa-bell text-lg"></i>
                    </button>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
                <div class="bg-white rounded-lg shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-800 mb-1">Total Members</h3>
                    <p class="text-2xl font-bold text-red-600">
                        <?php echo $total_members; ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-800 mb-1">Active Members</h3>
                    <p class="text-2xl font-bold text-green-600">
                        <?php echo $active_members; ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-800 mb-1">Today's Check-ins</h3>
                    <p class="text-2xl font-bold text-blue-600">
                        <?php echo $today_checkins; ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-4">
                    <h3 class="text-sm font-semibold text-gray-800 mb-1">Expired Memberships</h3>
                    <p class="text-2xl font-bold text-yellow-600">
                        <?php echo $expired_members; ?>
                    </p>
                </div>
            </div>

            <!-- Enhanced Members List -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-4 py-3 border-b border-gray-200">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center space-y-2 md:space-y-0">
                        <div class="flex items-center space-x-2">
                            <div class="bg-red-100 p-1.5 rounded-lg">
                                <i class="fas fa-users text-red-600"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-800">Member List</h2>
                            <span class="bg-red-100 text-red-800 text-xs font-medium px-2 py-0.5 rounded-full">
                                <?php echo $total_members; ?> members
                            </span>
                        </div>
                        <div class="flex flex-col sm:flex-row space-y-1 sm:space-y-0 sm:space-x-3 w-full md:w-auto">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-search text-gray-400"></i>
                                </div>
                                <input type="text" id="searchMember" placeholder="Search members..." 
                                       class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 w-full">
                            </div>
                            <select id="membershipFilter" class="px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500">
                            <option value="">All Memberships</option>
                            <option value="active">Active</option>
                            <option value="expired">Expired</option>
                            <option value="none">No Membership</option>
                        </select>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gradient-to-r from-gray-50 to-gray-100">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Member</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Membership</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Join Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Expired Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Activity</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            if ($members && $members->num_rows > 0): 
                                while($member = $members->fetch_assoc()): 
                            ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-200">
                                    <td class="px-4 py-3">
                                        <div class="flex items-center">
                                            <?php if ($member['profile_picture']): ?>
                                                <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($member['profile_picture']); ?>" 
                                                     alt="<?php echo htmlspecialchars($member['username']); ?>" 
                                                     class="w-10 h-10 rounded-full object-cover mr-3 border-2 border-gray-200">
                                            <?php else: ?>
                                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center mr-3 border-2 border-gray-200">
                                                    <span class="text-white text-sm font-bold">
                                                        <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="font-semibold text-gray-900 text-base"><?php echo htmlspecialchars($member['username']); ?></div>
                                                <div class="text-sm text-gray-500 flex items-center">
                                                    <i class="fas fa-envelope text-xs mr-1"></i>
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
                                            // Calculate expiry date if payment is approved and plan is set
                                            $expiry = null;
                                            if ($member['latest_payment_status'] === 'Approved' && $member['latest_payment_date'] && $member['plan_duration']) {
                                                $expiry = date('Y-m-d', strtotime($member['latest_payment_date'] . ' + ' . $member['plan_duration'] . ' days'));
                                            }
                                            ?>
                                            <?php if ($expiry): ?>
                                                <div class="text-sm text-gray-500">
                                                    Expires: <?php echo date('M d, Y', strtotime($expiry)); ?>
                                                </div>
                                            <?php elseif ($member['membership_end_date']): ?>
                                                <div class="text-sm text-gray-500">
                                                    Expires: <?php echo date('M d, Y', strtotime($member['membership_end_date'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php
                                        $today = date('Y-m-d');
                                        $is_active = false;
                                        if ($member['latest_payment_status'] === 'Approved' && $expiry && $expiry >= $today) {
                                            $is_active = true;
                                        } elseif ($member['membership_end_date'] && $member['membership_end_date'] >= $today) {
                                            $is_active = true;
                                        }
                                        $status = $is_active ? 'active' : 'inactive';
                                        $statusClass = $is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?php echo $statusClass; ?> border">
                                            <span class="w-1.5 h-1.5 rounded-full mr-1.5 <?php echo $status === 'active' ? 'bg-green-400' : 'bg-gray-400'; ?>"></span>
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center text-sm text-gray-600">
                                            <i class="fas fa-calendar-plus text-gray-400 mr-1.5"></i>
                                            <?php echo date('M d, Y', strtotime($member['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if ($member['membership_end_date']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-calendar-times text-gray-400 mr-1.5"></i>
                                                <span class="<?php echo strtotime($member['membership_end_date']) < time() ? 'text-red-600 font-medium' : 'text-gray-900'; ?>">
                                                <?php echo date('M d, Y', strtotime($member['membership_end_date'])); ?>
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
                                        <div class="flex space-x-1.5">
                                            <button onclick="openMemberModal(<?php echo $member['id']; ?>)" 
                                                    class="inline-flex items-center px-2 py-1.5 bg-blue-500 text-white text-xs font-medium rounded-md hover:bg-blue-600 transition-all duration-200 transform hover:scale-105 shadow-sm">
                                                <i class="fas fa-eye mr-1"></i>
                                                View
                                            </button>
                                            <form action="" method="POST" class="inline delete-form" onsubmit="return confirm('Are you sure you want to delete this member? This action cannot be undone.');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                                <button type="submit" class="inline-flex items-center px-2 py-1.5 bg-red-500 text-white text-xs font-medium rounded-md hover:bg-red-600 transition-all duration-200 transform hover:scale-105 shadow-sm">
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
                                    <td colspan="6" class="px-4 py-3 text-center text-gray-500">No members found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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

            memberRows.forEach(row => {
                const memberName = row.querySelector('.font-medium').textContent.toLowerCase();
                const memberEmail = row.querySelector('.text-gray-500').textContent.toLowerCase();
                const memberStatus = row.querySelector('.rounded-full').textContent.toLowerCase();

                const matchesSearch = memberName.includes(searchTerm) || memberEmail.includes(searchTerm);
                const matchesFilter = !filterValue || 
                    (filterValue === 'active' && memberStatus === 'active') ||
                    (filterValue === 'expired' && memberStatus === 'expired') ||
                    (filterValue === 'none' && memberStatus === 'inactive');

                row.style.display = matchesSearch && matchesFilter ? '' : 'none';
            });
        }

        searchInput.addEventListener('input', filterMembers);
        membershipFilter.addEventListener('change', filterMembers);

        // Add JavaScript for delete confirmation
        document.addEventListener('DOMContentLoaded', function() {
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
                                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 4.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
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
                                                       <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
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
                        content.innerHTML = `<div class='text-center text-red-500'>${data.error || 'Failed to load member details.'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    content.innerHTML = `<div class='text-center text-red-500'>Failed to load member details. Error: ${error.message}</div>`;
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
    </script>
</body>
</html>