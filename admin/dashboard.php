<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';
require 'analytics_helper.php';

$message = '';
$messageClass = '';

// Handle QR code scanning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_data'])) {
    $qr_data = $_POST['qr_data'];
    
    // Debug: Log the received QR data
    error_log("QR Data received: " . $qr_data);
    
    try {
        // Decode QR data
        $qr_info = json_decode($qr_data, true);
        
        // Debug: Log the decoded data
        error_log("Decoded QR info: " . print_r($qr_info, true));
        
        if ($qr_info && isset($qr_info['user_id'])) {
            $user_id = $qr_info['user_id'];
            $payment_id = $qr_info['payment_id'];
            
            // Verify user exists and has active membership
            $user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration
                        FROM users u 
                        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
                        WHERE u.id = ?";
            $user_stmt = $conn->prepare($user_sql);
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user = $user_stmt->get_result()->fetch_assoc();
            
            if ($user) {
                // Check if user has active membership
                $payment_sql = "SELECT * FROM payment_history 
                               WHERE user_id = ? AND payment_status = 'Approved' 
                               ORDER BY payment_date DESC LIMIT 1";
                $payment_stmt = $conn->prepare($payment_sql);
                $payment_stmt->bind_param("i", $user_id);
                $payment_stmt->execute();
                $payment = $payment_stmt->get_result()->fetch_assoc();
                
                if ($payment) {
                    // Check if already checked in today
                    $today = date('Y-m-d');
                    $attendance_sql = "SELECT * FROM attendance 
                                      WHERE user_id = ? AND DATE(check_in_time) = ? 
                                      ORDER BY check_in_time DESC LIMIT 1";
                    $attendance_stmt = $conn->prepare($attendance_sql);
                    $attendance_stmt->bind_param("is", $user_id, $today);
                    $attendance_stmt->execute();
                    $attendance = $attendance_stmt->get_result()->fetch_assoc();
                    
                    if (!$attendance) {
                        // Check in
                        $checkin_sql = "INSERT INTO attendance (user_id, check_in_time, plan_id) VALUES (?, NOW(), ?)";
                        $checkin_stmt = $conn->prepare($checkin_sql);
                        $checkin_stmt->bind_param("ii", $user_id, $user['selected_plan_id']);
                        
                        // Debug: Log the check-in attempt
                        error_log("Attempting check-in for user_id: $user_id, plan_id: " . $user['selected_plan_id']);
                        
                        if ($checkin_stmt->execute()) {
                            $attendance_id = $conn->insert_id;
                            error_log("Check-in successful. Attendance ID: $attendance_id");
                            
                            $message = "✅ CHECK-IN SUCCESSFUL!\n\nMember: " . ($user['full_name'] ?? $user['username']) . 
                                     "\nPlan: " . $user['plan_name'] . 
                                     "\nTime: " . date('H:i:s');
                            $messageClass = 'success';
                        } else {
                            error_log("Check-in failed: " . $checkin_stmt->error);
                            $message = "❌ Check-in failed. Please try again.";
                            $messageClass = 'error';
                        }
                    } else {
                        // Already checked in today
                        $message = "ℹ️ Member already checked in today at " . date('H:i:s', strtotime($attendance['check_in_time']));
                        $messageClass = 'info';
                    }
                } else {
                    $message = "❌ Member does not have an active membership.";
                    $messageClass = 'error';
                }
            } else {
                $message = "❌ Member not found.";
                $messageClass = 'error';
            }
        } else {
            $message = "❌ Invalid QR code format.";
            $messageClass = 'error';
        }
    } catch (Exception $e) {
        error_log("QR processing error: " . $e->getMessage());
        $message = "❌ Error processing QR code: " . $e->getMessage();
        $messageClass = 'error';
    }
}

// Get all analytics data
$topPlan = getTopMembershipPlan($conn);
$peakHours = getPeakCheckInHours($conn);
$topTrainer = getTopRatedTrainer($conn);
$expiringMemberships = getUpcomingExpirations($conn);
$equipmentTrends = getEquipmentUsageTrends($conn);
$memberGrowth = getMemberGrowth($conn);
$recentActivity = getRecentActivitySummary($conn);

// Get basic statistics
$stats = [
    'total_members' => 0,
    'active_members' => 0,
    'today_attendance' => 0,
    'pending_payments' => 0
];

// Total members
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'member'");
$stats['total_members'] = $result ? $result->fetch_assoc()['count'] : 0;

// Active members (those with approved payments and non-expired memberships)
$active_result = $conn->query("
    SELECT COUNT(DISTINCT u.id) as count
    FROM users u
    INNER JOIN payment_history ph ON u.id = ph.user_id
    INNER JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role = 'member'
      AND ph.payment_status = 'Approved'
      AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) > CURDATE()
");
$stats['active_members'] = $active_result ? $active_result->fetch_assoc()['count'] : 0;

// Today's attendance
$result = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM attendance WHERE DATE(check_in_time) = CURDATE()");
$stats['today_attendance'] = $result ? $result->fetch_assoc()['count'] : 0;

// Pending payments
$result = $conn->query("SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Pending'");
$stats['pending_payments'] = $result ? $result->fetch_assoc()['count'] : 0;



// Get recent check-ins
$recent_checkins = $conn->query("
    SELECT a.check_in_time, u.username, u.email, mp.name as plan_name
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE DATE(a.check_in_time) = CURDATE()
    ORDER BY a.check_in_time DESC
    LIMIT 10
");

// Get recent members
$recent_members = $conn->query("
    SELECT u.*, mp.name as plan_name
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role = 'member'
    ORDER BY u.id DESC
    LIMIT 5
");

// Handle query errors
if (!$recent_checkins) {
    $recent_checkins = (object) ['num_rows' => 0];
}
if (!$recent_members) {
    $recent_members = (object) ['num_rows' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Almo Fitness Gym</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            background-color: #f4f6f9;
            color: #333;
        }

        .navbar {
            background-color: #333;
            padding: 1rem;
            color: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .navbar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
        }

        .navbar-menu {
            display: flex;
            gap: 1rem;
        }

        .navbar-menu a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .navbar-menu a:hover {
            background-color: #555;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #2196f3;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
        }
        
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

        .scanner-section, .recent-activity {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #preview {
            width: 100%;
            max-width: 500px;
            margin: 1rem auto;
            border-radius: 10px;
            display: block;
        }

        .camera-select {
            width: 100%;
            max-width: 500px;
            margin: 1rem auto;
            padding: 0.8rem;
            border-radius: 5px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            display: block;
        }

        .message {
            padding: 1rem;
            margin: 1rem 0;
            border-radius: 5px;
            text-align: center;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .activity-list {
            list-style: none;
        }

        .activity-list li {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-list li:last-child {
            border-bottom: none;
        }

        .activity-list .time {
            color: #666;
            font-size: 0.9rem;
        }

        .member-info {
            display: flex;
            flex-direction: column;
        }

        .member-name {
            font-weight: bold;
        }

        .member-plan {
            font-size: 0.8rem;
            color: #666;
        }

        h1, h2 {
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .view-all {
            color: #2196f3;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .view-all:hover {
            text-decoration: underline;
        }

        .logout-btn {
            background-color: #dc3545;
            color: white !important;
        }

        .logout-btn:hover {
            background-color: #c82333 !important;
        }

        @media (max-width: 768px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            
            .navbar-container {
                flex-direction: column;
                gap: 1rem;
            }
            
            .navbar-menu {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

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

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .insight-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .insight-card:hover {
            transform: translateY(-5px);
        }

        .insight-title {
            font-size: 1.1rem;
            font-weight: bold;
            color: #333;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .insight-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2196f3;
            margin-bottom: 0.5rem;
        }

        .insight-detail {
            color: #666;
            font-size: 0.9rem;
        }

        .scrollable-list {
            max-height: 200px;
            overflow-y: auto;
            margin-top: 1rem;
        }

        .list-item {
            padding: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .list-item:last-child {
            border-bottom: none;
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
                    Admin Dashboard
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
                                <div>Last Update: <span id="lastUpdate">Never</span></div>
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
                        <img src="<?php echo htmlspecialchars($profile_picture ?? 'https://i.pravatar.cc/40?img=1'); ?>" alt="Admin Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow">Administrator</h3>
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

    <main class="ml-64 mt-16 p-6">
        <div class="max-w-7xl mx-auto">
            <!-- Enhanced Statistics Cards -->
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800">Dashboard Statistics</h2>
                <div class="flex items-center space-x-4">
                    <button onclick="refreshStats()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                        <i class="fas fa-sync-alt mr-2"></i>Refresh Stats
                    </button>
                    <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
                        <div class="text-sm text-gray-600 bg-gray-100 px-3 py-2 rounded">
                            <strong>Debug:</strong> Active Members Query: 
                            SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN payment_history ph ON u.id = ph.user_id 
                            INNER JOIN membership_plans mp ON u.selected_plan_id = mp.id WHERE u.role = 'member' 
                            AND ph.payment_status = 'Approved' AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) > CURDATE()
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <a href="manage_members.php" class="block">
                    <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-all duration-300 cursor-pointer h-32 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-blue-100 text-sm font-medium">Total Members</p>
                                <p class="text-3xl font-bold mt-1"><?php echo $stats['total_members']; ?> <small class="text-xs">(<?php echo date('H:i:s'); ?>)</small></p>
                            </div>
                            <div class="bg-blue-400 bg-opacity-30 p-3 rounded-full">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="manage_members.php?filter=active" class="block" title="Click to view all active members">
                    <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-all duration-300 cursor-pointer h-32 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-green-100 text-sm font-medium">Active Members</p>
                                <p class="text-3xl font-bold mt-1"><?php echo $stats['active_members']; ?> <small class="text-xs">(<?php echo date('H:i:s'); ?>)</small></p>
                                <p class="text-green-100 text-xs mt-1">Click to view all active members</p>
                            </div>
                            <div class="bg-green-400 bg-opacity-30 p-3 rounded-full">
                                <i class="fas fa-user-check text-xl"></i>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="attendance_history.php" class="block">
                    <div class="bg-gradient-to-br from-yellow-500 to-yellow-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-all duration-300 cursor-pointer h-32 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-yellow-100 text-sm font-medium">Today's Check-ins</p>
                                <p class="text-3xl font-bold mt-1"><?php echo $stats['today_attendance']; ?></p>
                            </div>
                            <div class="bg-yellow-400 bg-opacity-30 p-3 rounded-full">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                        </div>
                    </div>
                </a>

                <a href="manage_payments.php" class="block">
                    <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-xl shadow-lg p-6 text-white transform hover:scale-105 transition-all duration-300 cursor-pointer h-32 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-red-100 text-sm font-medium">Pending Payments</p>
                                <p class="text-3xl font-bold mt-1"><?php echo $stats['pending_payments']; ?></p>
                            </div>
                            <div class="bg-red-400 bg-opacity-30 p-3 rounded-full">
                                <i class="fas fa-credit-card text-xl"></i>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
            

            
            <!-- Enhanced Scanner and Activity Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Attendance Scanner -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-qrcode text-red-500 mr-3"></i>Attendance Scanner
                        </h2>
                        <div class="flex items-center space-x-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <i class="fas fa-circle text-xs mr-1"></i>Live
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($message): ?>
                        <div class="mb-4 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 border border-green-200 text-green-700' : 'bg-red-100 border border-red-200 text-red-700'; ?>">
                            <div class="flex items-center">
                                <i class="fas <?php echo $messageClass === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                                <?php echo $message; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Camera Selection</label>
                            <select class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500" id="camera-select">
                                <option value="">Loading cameras...</option>
                            </select>
                        </div>
                        
                        <div class="relative">
                            <video id="preview" class="w-full h-64 object-cover rounded-lg border border-gray-200"></video>
                            <div class="absolute inset-0 flex items-center justify-center bg-gray-100 rounded-lg" id="camera-placeholder" style="display: none;">
                                <div class="text-center">
                                    <i class="fas fa-video-slash text-4xl text-gray-400 mb-2"></i>
                                    <p class="text-gray-500">Camera loading...</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between text-sm text-gray-600">
                            <span><i class="fas fa-info-circle mr-1"></i>Point camera at QR code</span>
                            <span><i class="fas fa-clock mr-1"></i>Auto-scan enabled</span>
                        </div>
                        
                        <div class="text-center">
                            <a href="generate_test_qr.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700 transition-colors duration-200">
                                <i class="fas fa-qrcode mr-2"></i>Generate Test QR Code
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Compact Recent Activity -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-4 hover:shadow-xl transition-shadow duration-300">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-bold text-gray-800 flex items-center">
                            <i class="fas fa-chart-line text-blue-500 mr-2"></i>Recent Activity
                        </h2>
                        <a href="attendance_history.php" class="text-red-600 hover:text-red-700 text-xs font-medium transition-colors duration-200">
                            <i class="fas fa-external-link-alt mr-1"></i>View All
                        </a>
                    </div>
                    
                    <!-- Compact Recent Check-ins -->
                    <div class="mb-4">
                        <h3 class="text-sm font-medium text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-clock text-red-500 mr-2"></i>Recent Check-ins
                        </h3>
                        <div class="space-y-2">
                            <?php if ($recent_checkins->num_rows > 0): ?>
                                <?php while ($checkin = $recent_checkins->fetch_assoc()): ?>
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r from-red-50 to-red-100 rounded-lg border border-red-200 hover:from-red-100 hover:to-red-200 hover:shadow-md transition-all duration-200">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-gradient-to-br from-red-400 to-red-600 rounded-full flex items-center justify-center shadow-sm">
                                                <span class="text-white font-bold text-xs">
                                                    <?php echo strtoupper(substr($checkin['username'] ?? $checkin['email'] ?? 'U', 0, 1)); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?php echo htmlspecialchars($checkin['username'] ?? $checkin['email']); ?>
                                                </p>
                                                <span class="text-xs text-red-600 font-medium">
                                                    <?php echo htmlspecialchars($checkin['plan_name'] ?? 'No Plan'); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-600">
                                            <?php echo date('g:i A', strtotime($checkin['check_in_time'])); ?>
                                        </span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500 bg-gray-50 rounded-lg">
                                    <i class="fas fa-inbox text-2xl mb-2 text-gray-300"></i>
                                    <p class="text-xs">No check-ins today</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Compact New Members -->
                    <div>
                        <h3 class="text-sm font-medium text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-user-plus text-green-500 mr-2"></i>New Members
                        </h3>
                        <div class="space-y-2">
                            <?php if ($recent_members->num_rows > 0): ?>
                                <?php while ($member = $recent_members->fetch_assoc()): ?>
                                    <div class="flex items-center justify-between p-3 bg-gradient-to-r from-green-50 to-green-100 rounded-lg border border-green-200 hover:from-green-100 hover:to-green-200 hover:shadow-md transition-all duration-200">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-gradient-to-br from-green-400 to-green-600 rounded-full flex items-center justify-center shadow-sm">
                                                <span class="text-white font-bold text-xs">
                                                    <?php echo strtoupper(substr($member['username'] ?? $member['email'] ?? 'U', 0, 1)); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">
                                                    <?php echo htmlspecialchars($member['username'] ?? $member['email']); ?>
                                                </p>
                                                <span class="text-xs text-green-600 font-medium">
                                                    <?php echo htmlspecialchars($member['plan_name'] ?? 'No Plan'); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-600">
                                            <?php echo date('M d, Y', strtotime($member['created_at'] ?? 'now')); ?>
                                        </span>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-gray-500 bg-gray-50 rounded-lg">
                                    <i class="fas fa-users text-2xl mb-2 text-gray-300"></i>
                                    <p class="text-xs">No new members</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Beautiful Smart Insights Section -->
        <div class="mt-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center justify-center">
                <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>Smart Insights
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 max-w-6xl mx-auto">
                <!-- Top Membership Plan -->
                <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-lg border border-yellow-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-yellow-200 text-yellow-700 mr-2">
                                <i class="fas fa-crown text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Top Plan</h3>
                        </div>
                        <span class="text-xs text-yellow-600 bg-yellow-200 px-2 py-1 rounded-full">Popular</span>
                    </div>
                    <div class="text-lg font-bold text-gray-900 mb-1">
                        <?php echo !empty($topPlan['plan_name']) && $topPlan['plan_name'] !== 'No active plans' ? htmlspecialchars($topPlan['plan_name']) : 'Monthly Plan'; ?>
                    </div>
                    <p class="text-xs text-gray-600">
                        <?php echo $topPlan['active_subscribers'] > 0 ? number_format($topPlan['active_subscribers']) . ' subscribers' : '0 subscribers'; ?>
                    </p>
                </div>

                <!-- Peak Hours -->
                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg border border-blue-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-blue-200 text-blue-700 mr-2">
                                <i class="fas fa-clock text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Peak Time</h3>
                        </div>
                        <span class="text-xs text-blue-600 bg-blue-200 px-2 py-1 rounded-full">Busy</span>
                    </div>
                    <div class="text-lg font-bold text-gray-900 mb-1">
                        <?php 
                        if ($peakHours['hour'] > 0 && $peakHours['check_ins'] > 0) {
                            echo date('g:i A', strtotime($peakHours['hour'] . ':00'));
                        } else {
                            echo '11:00 PM';
                        }
                        ?>
                    </div>
                    <p class="text-xs text-gray-600">Last 30 days</p>
                </div>

                <!-- Top Rated Trainer -->
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg border border-purple-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-purple-200 text-purple-700 mr-2">
                                <i class="fas fa-star text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Top Trainer</h3>
                        </div>
                        <span class="text-xs text-purple-600 bg-purple-200 px-2 py-1 rounded-full">Best</span>
                    </div>
                    <div class="text-lg font-bold text-gray-900 mb-1">
                        <?php echo !empty($topTrainer['trainer_name']) && $topTrainer['trainer_name'] !== 'No trainers available' ? htmlspecialchars($topTrainer['trainer_name']) : 'No trainers available'; ?>
                    </div>
                    <p class="text-xs text-gray-600">
                        <?php echo $topTrainer['total_ratings'] > 0 ? $topTrainer['average_rating'] . '/5 (' . $topTrainer['total_ratings'] . ' reviews)' : '0/5 (0 reviews)'; ?>
                    </p>
                </div>

                <!-- Upcoming Expirations -->
                <div class="bg-gradient-to-br from-red-50 to-red-100 rounded-lg border border-red-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-red-200 text-red-700 mr-2">
                                <i class="fas fa-calendar-times text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Expiring Soon</h3>
                        </div>
                        <span class="text-xs text-red-600 bg-red-200 px-2 py-1 rounded-full">Alert</span>
                    </div>
                    <div class="space-y-1 h-16 overflow-y-auto">
                        <?php if (!empty($expiringMemberships)): ?>
                            <?php foreach (array_slice($expiringMemberships, 0, 3) as $member): ?>
                                <div class="flex items-center justify-between p-1 bg-red-50 rounded text-xs">
                                    <span class="text-gray-800 font-medium truncate"><?php echo htmlspecialchars($member['username']); ?></span>
                                    <span class="text-red-600"><?php echo date('M d', strtotime($member['membership_end_date'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($expiringMemberships) > 3): ?>
                                <div class="text-xs text-red-600 text-center">+<?php echo count($expiringMemberships) - 3; ?> more</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <i class="fas fa-check-circle text-green-400 text-lg mb-1"></i>
                                <p class="text-xs text-green-600">No expirations soon</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Equipment Usage -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg border border-gray-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-gray-200 text-gray-700 mr-2">
                                <i class="fas fa-dumbbell text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Equipment</h3>
                        </div>
                        <span class="text-xs text-gray-600 bg-gray-200 px-2 py-1 rounded-full">Trends</span>
                    </div>
                    <div class="space-y-1 h-16 overflow-y-auto">
                        <?php if (!empty($equipmentTrends)): ?>
                            <?php foreach (array_slice($equipmentTrends, 0, 3) as $equipment): ?>
                                <div class="flex items-center justify-between p-1 bg-gray-50 rounded text-xs">
                                    <span class="text-gray-800 font-medium truncate"><?php echo htmlspecialchars($equipment['name']); ?></span>
                                    <span class="text-gray-600"><?php echo $equipment['view_count']; ?> views</span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($equipmentTrends) > 3): ?>
                                <div class="text-xs text-gray-600 text-center">+<?php echo count($equipmentTrends) - 3; ?> more</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-2">
                                <i class="fas fa-chart-line text-gray-400 text-lg mb-1"></i>
                                <p class="text-xs text-gray-500">No data yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Member Growth -->
                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg border border-green-200 p-4 hover:shadow-md transition-all duration-300 h-32">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-green-200 text-green-700 mr-2">
                                <i class="fas fa-chart-line text-sm"></i>
                            </div>
                            <h3 class="text-sm font-semibold text-gray-800">Growth</h3>
                        </div>
                        <?php 
                        $growth = 0;
                        if ($memberGrowth['last_month'] > 0) {
                            $growth = round(($memberGrowth['this_month'] - $memberGrowth['last_month']) / $memberGrowth['last_month'] * 100, 1);
                        } elseif ($memberGrowth['this_month'] > 0) {
                            $growth = 100;
                        }
                        ?>
                        <span class="text-xs <?php echo $growth >= 0 ? 'text-green-600 bg-green-200' : 'text-red-600 bg-red-200'; ?> px-2 py-1 rounded-full">
                            <?php echo $growth >= 0 ? '+' : ''; echo $growth; ?>%
                        </span>
                    </div>
                    <div class="text-lg font-bold text-gray-900 mb-1">
                        <?php echo $memberGrowth['this_month']; ?>
                    </div>
                    <p class="text-xs text-gray-600">vs <?php echo $memberGrowth['last_month']; ?> last month</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Chatbot Component -->
    <div id="chatbot" class="fixed bottom-6 right-6 z-50">
        <!-- Chat Button -->
        <button id="chatbotToggle" class="bg-red-600 hover:bg-red-700 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition-all duration-300 hover:scale-110">
            <i id="chatbotIcon" class="fas fa-comments text-xl"></i>
        </button>
        
        <!-- Chat Window -->
        <div id="chatbotWindow" class="absolute bottom-16 right-0 w-80 h-96 bg-white rounded-xl shadow-2xl border border-gray-200 hidden transform transition-all duration-300">
            <!-- Chat Header -->
            <div class="bg-red-600 text-white px-4 py-3 rounded-t-xl flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-white rounded-full flex items-center justify-center">
                        <i class="fas fa-dumbbell text-red-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold">Admin Assistant</h3>
                        <p class="text-xs text-red-100">Online</p>
                    </div>
                </div>
                <button id="chatbotClose" class="text-white hover:text-red-100 transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Chat Messages -->
            <div id="chatMessages" class="h-72 overflow-y-auto p-4 space-y-3">
                <!-- Welcome Message -->
                <div class="flex items-start gap-2">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-dumbbell text-red-600 text-xs"></i>
                    </div>
                    <div class="bg-gray-100 rounded-lg px-3 py-2 max-w-xs">
                        <p class="text-sm text-gray-800">Hi! I'm your Admin Assistant. How can I help you today?</p>
                    </div>
                </div>
            </div>
            
            <!-- Chat Input -->
            <div class="border-t border-gray-200 p-4">
                <div class="flex gap-2">
                    <input type="text" id="chatInput" placeholder="Type your message..." 
                           class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 text-sm">
                    <button id="chatSend" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                        <i class="fas fa-paper-plane text-sm"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Refresh Statistics Function
        function refreshStats() {
            const refreshBtn = event.target;
            const originalText = refreshBtn.innerHTML;
            
            // Show loading state
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Refreshing...';
            refreshBtn.disabled = true;
            
            // Reload the page to get fresh stats
            setTimeout(() => {
                location.reload();
            }, 500);
        }
        
        // QR Scanner Functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize QR Scanner
            let scanner = null;
            let currentCamera = null;
            
            // Initialize Instascan Scanner
            function initializeScanner() {
                try {
                    scanner = new Instascan.Scanner({ 
                        video: document.getElementById('preview'),
                        mirror: false
                    });
                    
                    scanner.addListener('scan', function (content) {
                        console.log('QR Code scanned:', content);
                        
                        // Create form and submit QR data
                        let form = document.createElement('form');
                        form.method = 'POST';
                        form.action = window.location.href;
                        
                        let input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'qr_data';
                        input.value = content;
                        
                        form.appendChild(input);
                        document.body.appendChild(form);
                        form.submit();
                    });
                    
                    // Get available cameras
                    Instascan.Camera.getCameras().then(function (cameras) {
                        let select = document.getElementById('camera-select');
                        select.innerHTML = '';
                        
                        if (cameras.length > 0) {
                            cameras.forEach((camera, index) => {
                                let option = document.createElement('option');
                                option.value = index;
                                option.text = camera.name || `Camera ${index + 1}`;
                                select.appendChild(option);
                            });
                            
                            // Start with first camera
                            startCamera(cameras[0]);
                            
                            // Handle camera selection change
                            select.onchange = function() {
                                if (currentCamera) {
                                    scanner.stop();
                                }
                                startCamera(cameras[select.value]);
                            };
                        } else {
                            console.error('No cameras found.');
                            showCameraError('No cameras found. Please check your camera permissions.');
                        }
                    }).catch(function (e) {
                        console.error('Camera error:', e);
                        showCameraError('Camera access denied. Please allow camera permissions.');
                    });
                    
                } catch (error) {
                    console.error('Scanner initialization error:', error);
                    showCameraError('Failed to initialize QR scanner. Please refresh the page.');
                }
            }
            
            function startCamera(camera) {
                if (scanner) {
                    scanner.start(camera);
                    currentCamera = camera;
                    hideCameraError();
                }
            }
            
            function showCameraError(message) {
                const placeholder = document.getElementById('camera-placeholder');
                const video = document.getElementById('preview');
                
                if (placeholder) {
                    placeholder.style.display = 'flex';
                    placeholder.innerHTML = `
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-2"></i>
                            <p class="text-red-500">${message}</p>
                            <button onclick="location.reload()" class="mt-2 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
                                Retry
                            </button>
                        </div>
                    `;
                }
                
                if (video) {
                    video.style.display = 'none';
                }
            }
            
            function hideCameraError() {
                const placeholder = document.getElementById('camera-placeholder');
                const video = document.getElementById('preview');
                
                if (placeholder) {
                    placeholder.style.display = 'none';
                }
                
                if (video) {
                    video.style.display = 'block';
                }
            }
            
            // Initialize scanner when page loads
            if (typeof Instascan !== 'undefined') {
                initializeScanner();
            } else {
                console.error('Instascan library not loaded');
                showCameraError('QR Scanner library not loaded. Please refresh the page.');
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const chevronIcon = sidebarToggle.querySelector('i');
            const mainContent = document.querySelector('main');
            let isCollapsed = false;

            sidebarToggle.addEventListener('click', function() {
                isCollapsed = !isCollapsed;
                if (isCollapsed) {
                    sidebar.style.width = '5rem';
                    chevronIcon.style.transform = 'rotate(180deg)';
                    document.querySelectorAll('.sidebar-logo-text, .sidebar-bottom-text, nav span').forEach(el => {
                        el.style.opacity = '0';
                        el.style.visibility = 'hidden';
                    });
                    // Center the main content
                    if (mainContent) {
                        mainContent.style.marginLeft = '5rem';
                        mainContent.style.transition = 'margin-left 0.3s ease';
                    }
                } else {
                    sidebar.style.width = '16rem';
                    chevronIcon.style.transform = 'rotate(0)';
                    document.querySelectorAll('.sidebar-logo-text, .sidebar-bottom-text, nav span').forEach(el => {
                        el.style.opacity = '1';
                        el.style.visibility = 'visible';
                    });
                    // Restore main content position
                    if (mainContent) {
                        mainContent.style.marginLeft = '16rem';
                    }
                }
            });

            // Existing scanner code
        let scanner = new Instascan.Scanner({ video: document.getElementById('preview') });
        
        scanner.addListener('scan', function (content) {
            let form = document.createElement('form');
            form.method = 'POST';
                form.action = window.location.href;

            let input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'qr_data';
            input.value = content;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        });

        Instascan.Camera.getCameras().then(function (cameras) {
                let select = document.querySelector('.camera-select');
                
            if (cameras.length > 0) {
                    select.innerHTML = '';
                cameras.forEach((camera, index) => {
                    let option = document.createElement('option');
                    option.value = index;
                    option.text = camera.name || `Camera ${index + 1}`;
                    select.appendChild(option);
                });
                
                scanner.start(cameras[0]);

                    select.onchange = function() {
                        scanner.start(cameras[select.value]);
                    };
            } else {
                console.error('No cameras found.');
            }
        }).catch(function (e) {
            console.error(e);
            });
        });

        // Profile dropdown functionality
        document.addEventListener('DOMContentLoaded', function() {
            const profileDropdown = document.getElementById('profileDropdown');
            const profileMenu = document.getElementById('profileMenu');
            const dropdownArrow = document.getElementById('dropdownArrow');
            let isOpen = false;

            function toggleDropdown() {
                isOpen = !isOpen;
                
                if (isOpen) {
                    profileMenu.classList.remove('opacity-0', 'invisible', 'scale-95');
                    profileMenu.classList.add('opacity-100', 'visible', 'scale-100');
                    dropdownArrow.style.transform = 'rotate(180deg)';
                } else {
                    profileMenu.classList.add('opacity-0', 'invisible', 'scale-95');
                    profileMenu.classList.remove('opacity-100', 'visible', 'scale-100');
                    dropdownArrow.style.transform = 'rotate(0deg)';
                }
            }

            function closeDropdown() {
                if (isOpen) {
                    isOpen = false;
                    profileMenu.classList.add('opacity-0', 'invisible', 'scale-95');
                    profileMenu.classList.remove('opacity-100', 'visible', 'scale-100');
                    dropdownArrow.style.transform = 'rotate(0deg)';
                }
            }

            // Toggle dropdown on button click
            profileDropdown.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!profileDropdown.contains(e.target) && !profileMenu.contains(e.target)) {
                    closeDropdown();
                }
            });

            // Close dropdown on escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeDropdown();
                }
            });

            // Prevent dropdown from closing when clicking inside the menu
            profileMenu.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // Chatbot functionality
        document.addEventListener('DOMContentLoaded', function() {
            const chatbotToggle = document.getElementById('chatbotToggle');
            const chatbotWindow = document.getElementById('chatbotWindow');
            const chatbotClose = document.getElementById('chatbotClose');
            const chatbotIcon = document.getElementById('chatbotIcon');
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const chatSend = document.getElementById('chatSend');
            
            let isChatOpen = false;
            
            // Predefined responses for common questions
            const responses = {
                'hello': 'Hello! How can I help you with your admin tasks today?',
                'hi': 'Hi there! I\'m here to help you manage the gym system.',
                'help': 'I can help you with:\n• Member management\n• Trainer management\n• Payment processing\n• Announcements\n• Equipment tracking\n• Reports and analytics\n• General system questions',
                'members': 'You can manage members in the Members section. Add new members, view profiles, or update membership status.',
                'trainers': 'The Trainers section lets you manage gym trainers, their schedules, and specializations.',
                'payments': 'Process and track payments in the Payments section. You can view payment history and handle pending payments.',
                'announcements': 'Create and manage gym announcements in the Announcements section.',
                'equipment': 'Track gym equipment, maintenance schedules, and usage in the Equipment section.',
                'reports': 'Generate various reports about membership, attendance, and revenue in the Reports section.',
                'attendance': 'Use the QR scanner to track member attendance. View attendance history in the Reports section.',
                'settings': 'Configure system settings, notifications, and preferences in the Settings section.',
                'contact': 'For technical support:\n• Email: support@almofitness.com\n• Phone: +63 912 345 6789\n• Available 24/7'
            };
            
            function toggleChat() {
                isChatOpen = !isChatOpen;
                
                if (isChatOpen) {
                    chatbotWindow.classList.remove('hidden');
                    chatbotIcon.className = 'fas fa-times text-xl';
                    chatInput.focus();
                } else {
                    chatbotWindow.classList.add('hidden');
                    chatbotIcon.className = 'fas fa-comments text-xl';
                }
            }
            
            function addMessage(message, isUser = false) {
                const messageDiv = document.createElement('div');
                messageDiv.className = 'flex items-start gap-2';
                
                if (isUser) {
                    messageDiv.innerHTML = `
                        <div class="flex-1"></div>
                        <div class="bg-red-600 text-white rounded-lg px-3 py-2 max-w-xs">
                            <p class="text-sm">${message}</p>
                        </div>
                    `;
                } else {
                    messageDiv.innerHTML = `
                        <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-dumbbell text-red-600 text-xs"></i>
                        </div>
                        <div class="bg-gray-100 rounded-lg px-3 py-2 max-w-xs">
                            <p class="text-sm text-gray-800">${message}</p>
                        </div>
                    `;
                }
                
                chatMessages.appendChild(messageDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            function getResponse(userMessage) {
                const message = userMessage.toLowerCase();
                
                // Check for exact matches first
                for (const [key, response] of Object.entries(responses)) {
                    if (message.includes(key)) {
                        return response;
                    }
                }
                
                // Check for common patterns
                if (message.includes('how') && (message.includes('add') || message.includes('create'))) {
                    if (message.includes('member')) {
                        return 'To add a new member:\n1. Go to Members section\n2. Click "Add Member"\n3. Fill in their details\n4. Assign a membership plan\n5. Save the profile';
                    }
                    if (message.includes('trainer')) {
                        return 'To add a new trainer:\n1. Go to Trainers section\n2. Click "Add Trainer"\n3. Fill in their details\n4. Set their specializations\n5. Save the profile';
                    }
                    if (message.includes('announcement')) {
                        return 'To create an announcement:\n1. Go to Announcements section\n2. Click "New Announcement"\n3. Write your message\n4. Set visibility and duration\n5. Publish';
                    }
                }
                
                if (message.includes('report') || message.includes('analytics')) {
                    return 'You can generate various reports in the Reports section:\n• Membership reports\n• Attendance analytics\n• Revenue statistics\n• Equipment usage\n• Trainer performance';
                }
                
                // Default response
                return 'I\'m not sure about that specific question. You can try asking about members, trainers, payments, announcements, equipment, or reports. Or check the documentation for detailed guides.';
            }
            
            function sendMessage() {
                const message = chatInput.value.trim();
                if (message === '') return;
                
                // Add user message
                addMessage(message, true);
                chatInput.value = '';
                
                // Simulate typing delay
                setTimeout(() => {
                    const response = getResponse(message);
                    addMessage(response);
                }, 500);
            }
            
            // Event listeners
            chatbotToggle.addEventListener('click', toggleChat);
            chatbotClose.addEventListener('click', toggleChat);
            
            chatSend.addEventListener('click', sendMessage);
            
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    sendMessage();
                }
            });
            
            // Close chat when clicking outside
            document.addEventListener('click', function(e) {
                if (isChatOpen && !chatbotWindow.contains(e.target)) {
                    toggleChat();
                }
            });
            
            // Prevent chat from closing when clicking inside
            chatbotWindow.addEventListener('click', function(e) {
                e.stopPropagation();
            });
            
            // Real-Time Notification System using Server-Sent Events (SSE)
            console.log('Initializing real-time SSE notification system...');
            
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const notificationBadge = document.getElementById('notificationBadge');
            const notificationList = document.getElementById('notificationList');
            
            console.log('Notification elements found:', {
                btn: !!notificationBtn,
                dropdown: !!notificationDropdown,
                badge: !!notificationBadge,
                list: !!notificationList
            });
            
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
                    
                    eventSource.onmessage = function(event) {
                        console.log('Received message:', event.data);
                    };
                    
                    eventSource.addEventListener('connected', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('SSE Connected:', data);
                    });
                    
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
                        console.log('📊 Count update received:', data);
                        
                        const newCount = data.unread_count;
                        if (newCount !== unreadCount) {
                            console.log('Notification count changed from', unreadCount, 'to', newCount);
                            unreadCount = newCount;
                            updateBadge();
                            
                            // Show notification for new count
                            if (newCount > 0 && newCount > (window.previousCount || 0)) {
                                showNotificationAction(`You have ${newCount} new notification${newCount > 1 ? 's' : ''}! 🔔`, 'info');
                            }
                            window.previousCount = newCount;
                        }
                    });
                    
                    eventSource.addEventListener('new_members', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('👥 New members received:', data);
                        
                        showNotificationAction('New member registration! 👥', 'info');
                    });
                    
                    eventSource.addEventListener('pending_payments', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('💰 Pending payments received:', data);
                        
                        showNotificationAction('New pending payments! 💰', 'info');
                    });
                    
                    eventSource.addEventListener('equipment_issues', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('🔧 Equipment issues received:', data);
                        
                        showNotificationAction('Equipment maintenance alert! 🔧', 'warning');
                    });
                    
                    eventSource.addEventListener('expiring_memberships', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('⏰ Expiring memberships received:', data);
                        
                        showNotificationAction('Memberships expiring soon! ⏰', 'warning');
                    });
                    
                    eventSource.addEventListener('low_attendance', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('📉 Low attendance received:', data);
                        
                        showNotificationAction('Low attendance alert! 📉', 'warning');
                    });
                    
                    eventSource.addEventListener('new_feedback', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('💬 New feedback received:', data);
                        
                        showNotificationAction('New feedback received! 💬', 'info');
                    });
                    
                    eventSource.addEventListener('system_alerts', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('⚠️ System alerts received:', data);
                        
                        showNotificationAction('System alert! ⚠️', 'error');
                    });
                    
                    eventSource.addEventListener('heartbeat', function(event) {
                        const data = JSON.parse(event.data);
                        console.log('💖 Heartbeat received:', data);
                    });
                    
                    eventSource.addEventListener('error', function(event) {
                        console.error('❌ SSE Error:', event);
                        showNotificationAction('Connection lost. Reconnecting... 🔄', 'warning');
                        updateDebugInfo('Error - Reconnecting', unreadCount);
                        
                        // Reconnect after 5 seconds
                        setTimeout(connectToRealTimeServer, 5000);
                    });
                    
                    eventSource.onerror = function(event) {
                        console.error('❌ SSE Connection error:', event);
                        eventSource.close();
                        
                        // Reconnect after 5 seconds
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
                
                // Get type icon
                function getTypeIcon(type) {
                    switch (type) {
                        case 'success': return 'fas fa-check-circle';
                        case 'warning': return 'fas fa-exclamation-triangle';
                        case 'error': return 'fas fa-times-circle';
                        case 'alert': return 'fas fa-bell';
                        default: return 'fas fa-info-circle';
                    }
                }
                
                // Get type color
                function getTypeColor(type) {
                    switch (type) {
                        case 'success': return 'bg-green-500';
                        case 'warning': return 'bg-yellow-500';
                        case 'error': return 'bg-red-500';
                        case 'alert': return 'bg-blue-500';
                        default: return 'bg-gray-500';
                    }
                }
                
                // Get type badge color
                function getTypeBadgeColor(type) {
                    switch (type) {
                        case 'success': return 'bg-green-100 text-green-800';
                        case 'warning': return 'bg-yellow-100 text-yellow-800';
                        case 'error': return 'bg-red-100 text-red-800';
                        case 'alert': return 'bg-blue-100 text-blue-800';
                        default: return 'bg-gray-100 text-gray-800';
                    }
                }
                
                // Get time ago
                function getTimeAgo(timestamp) {
                    const now = Math.floor(Date.now() / 1000);
                    const diff = now - timestamp;
                    
                    if (diff < 60) return 'Just now';
                    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                    return Math.floor(diff / 86400) + 'd ago';
                }
                
                // Handle notification click
                function handleNotificationClick(id, type) {
                    // Mark as read
                    markAsRead(id);
                    
                    // Show action feedback
                    showNotificationAction('Notification action triggered! ✅', 'success');
                    
                    // Add visual feedback
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
                
                // Show notification action feedback
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
                    
                    // Animate in
                    setTimeout(() => {
                        actionDiv.style.transform = 'translateX(0)';
                    }, 100);
                    
                    // Remove after 3 seconds
                    setTimeout(() => {
                        actionDiv.style.transform = 'translateX(100%)';
                        setTimeout(() => {
                            document.body.removeChild(actionDiv);
                        }, 300);
                    }, 3000);
                }
                
                // Toggle dropdown
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
                
                console.log('Real-time SSE notification system initialized successfully!');
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
                        
                        // Update local count
                        if (unreadCount > 0) {
                            unreadCount--;
                            updateBadge();
                        }
                        
                        // Remove from notifications array
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
                        
                        // Update local state
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
                        
                        // Update local state
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

        });
        

        

        

    </script>
    

</body>
</html> 