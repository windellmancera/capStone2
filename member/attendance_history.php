<?php
session_start();

// Check if user is logged in and is a member
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'member') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$user_id = $_SESSION['user_id'];

// Get user information
$user_sql = "SELECT u.*, mp.name as plan_name 
             FROM users u 
             LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
             WHERE u.id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: ../index.php");
    exit();
}

// Set profile picture
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Build the query with filters
$where_conditions = ["a.user_id = ?"];
$params = [$user_id];
$param_types = 'i';

if ($date_filter) {
    $where_conditions[] = "DATE(a.check_in_time) = ?";
    $params[] = $date_filter;
    $param_types .= 's';
}

if ($status_filter) {
    switch ($status_filter) {
        case 'present':
            $where_conditions[] = "TIME(a.check_in_time) BETWEEN '06:00:00' AND '22:00:00'";
            break;
        case 'late':
            $where_conditions[] = "TIME(a.check_in_time) > '22:00:00'";
            break;
        case 'early':
            $where_conditions[] = "TIME(a.check_in_time) < '06:00:00'";
            break;
    }
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM attendance a $where_clause";

$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($param_types, ...$params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];

$total_pages = ceil($total_records / $per_page);

// Get attendance records
$sql = "SELECT a.*, 
               CASE 
                   WHEN TIME(a.check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 'Present'
                   WHEN TIME(a.check_in_time) > '22:00:00' THEN 'Late'
                   WHEN TIME(a.check_in_time) < '06:00:00' THEN 'Early'
                   ELSE 'Present'
               END as status
        FROM attendance a 
        $where_clause
        ORDER BY a.check_in_time DESC 
        LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get user's attendance statistics
$stats_sql = "SELECT 
                  COUNT(*) as total_visits,
                  COUNT(CASE WHEN DATE(check_in_time) = CURDATE() THEN 1 END) as today_visits,
                  COUNT(CASE WHEN DATE(check_in_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 END) as yesterday_visits,
                  COUNT(CASE WHEN TIME(check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 1 END) as on_time_visits,
                  COUNT(CASE WHEN TIME(check_in_time) > '22:00:00' THEN 1 END) as late_visits,
                  COUNT(CASE WHEN TIME(check_in_time) < '06:00:00' THEN 1 END) as early_visits,
                  MAX(check_in_time) as last_visit,
                  MIN(check_in_time) as first_visit
              FROM attendance 
              WHERE user_id = ?";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Get current month statistics
$current_month_sql = "SELECT 
                         COUNT(*) as month_visits,
                         COUNT(CASE WHEN TIME(check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 1 END) as month_on_time
                     FROM attendance 
                     WHERE user_id = ? 
                     AND MONTH(check_in_time) = MONTH(CURDATE()) 
                     AND YEAR(check_in_time) = YEAR(CURDATE())";
$month_stmt = $conn->prepare($current_month_sql);
$month_stmt->bind_param("i", $user_id);
$month_stmt->execute();
$month_stats = $month_stmt->get_result()->fetch_assoc();

// Calculate attendance streak (simplified version without window functions)
$streak_sql = "SELECT 
                   COUNT(*) as streak
               FROM (
                   SELECT DISTINCT DATE(check_in_time) as visit_date
                   FROM attendance 
                   WHERE user_id = ? 
                   AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                   ORDER BY visit_date DESC
               ) dates";
$streak_stmt = $conn->prepare($streak_sql);
$streak_stmt->bind_param("i", $user_id);
$streak_stmt->execute();
$streak_result = $streak_stmt->get_result()->fetch_assoc();
$current_streak = $streak_result ? $streak_result['streak'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance History - Almo Fitness Gym</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dark-mode.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        /* Notification System Styles */
        #notificationBtn {
            transition: all 0.3s ease;
        }
        
        #notificationBtn:hover {
            transform: scale(1.1);
        }
        
        #notificationBadge {
            animation: bounce 2s infinite;
        }
        
        #notificationDropdown {
            transition: all 0.2s ease;
        }
        
        .notification-item {
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            transform: translateX(5px);
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-3px);
            }
            60% {
                transform: translateY(-2px);
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
            }
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
            <a href="homepage.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-home w-6 text-center"></i> <span>Dashboard</span>
            </a>
            <a href="profile.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-user w-6 text-center"></i> <span>Profile</span>
            </a>
            <a href="membership.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'membership.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-id-card w-6 text-center"></i> <span>Membership</span>
            </a>
            <a href="payment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'payment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-credit-card w-6 text-center"></i> <span>Payments</span>
            </a>
            <a href="equipment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'equipment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-dumbbell w-6 text-center"></i> <span>Equipment</span>
            </a>
            <a href="trainers.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'trainers.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-users w-6 text-center"></i> <span>Trainers</span>
            </a>
            <a href="attendance_history.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-clock w-6 text-center"></i> <span>Attendance History</span>
            </a>
            <a href="progress.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'progress.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-chart-line w-6 text-center"></i> <span>Progress</span>
            </a>
        </nav>
        <div class="px-4 py-5 border-t border-gray-700 mt-auto flex flex-col space-y-2 sidebar-bottom">
            <a href="settings.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 hover:bg-gray-700 hover:text-white sidebar-settings">
                <i class="fas fa-cog w-6 text-center"></i> <span class="sidebar-bottom-text">Settings</span>
            </a>
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
                    My Attendance History
                </span>
            </div>
            <div class="flex items-center space-x-10">
                <div class="relative">
                    <button id="notificationBtn" class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors cursor-pointer relative" title="Notifications">
                        <i class="fas fa-bell text-lg"></i>
                        <!-- Notification Badge -->
                        <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold hidden">
                            0
                        </span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50 max-h-96 overflow-y-auto">
                        <div class="p-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                <button onclick="markAllAsRead()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Mark all as read</button>
                            </div>
                        </div>
                        
                        <div id="notificationList" class="p-2">
                            <!-- Notifications will be populated here -->
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-bell text-3xl mb-2"></i>
                                <p>No notifications</p>
                            </div>
                        </div>
                        
                        <div class="p-3 border-t border-gray-100 bg-gray-50">
                            <div class="flex justify-between items-center">
                                <a href="settings.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                    <i class="fas fa-cog mr-1"></i>Notification Settings
                                </a>
                                <button onclick="clearAllNotifications()" class="text-sm text-red-600 hover:text-red-800 font-medium">Clear All</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <button id="profileDropdown" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-700/30 transition-colors">
                        <img src="<?php echo htmlspecialchars($profile_picture ?? 'https://i.pravatar.cc/40?img=1'); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'User'); ?></h3>
                            <p class="text-sm text-gray-200 drop-shadow">Member</p>
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
    <main class="ml-64 mt-16 p-6">
        <div class="max-w-7xl mx-auto space-y-8">
            <!-- User Info Card -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-4">
                    <?php if ($user['profile_picture']): ?>
                        <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                             alt="Profile Picture" class="h-16 w-16 rounded-full object-cover border-2 border-gray-200">
                    <?php else: ?>
                        <div class="h-16 w-16 bg-red-100 rounded-full flex items-center justify-center">
                            <span class="text-2xl font-bold text-red-600">
                                <?php echo strtoupper(substr($user['full_name'] ?? $user['username'] ?? 'U', 0, 1)); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900">
                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                            </h2>
                            <p class="text-gray-600"><?php echo htmlspecialchars($user['email']); ?></p>
                            <p class="text-sm text-gray-500">
                                Member since: <?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-600">Current Plan</p>
                        <p class="text-lg font-semibold text-red-600">
                            <?php echo htmlspecialchars($user['plan_name'] ?? 'No Plan'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-calendar-check text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Visits</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats['total_visits']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">On Time</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $stats['on_time_visits']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-fire text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Current Streak</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $current_streak; ?> days</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">This Month</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $month_stats['month_visits']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Today</span>
                            <span class="font-semibold"><?php echo $stats['today_visits']; ?> visits</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Yesterday</span>
                            <span class="font-semibold"><?php echo $stats['yesterday_visits']; ?> visits</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Last Visit</span>
                            <span class="font-semibold">
                                <?php echo $stats['last_visit'] ? date('M d, Y', strtotime($stats['last_visit'])) : 'Never'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Attendance Breakdown</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">On Time</span>
                            <span class="font-semibold text-green-600"><?php echo $stats['on_time_visits']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Late</span>
                            <span class="font-semibold text-yellow-600"><?php echo $stats['late_visits']; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Early</span>
                            <span class="font-semibold text-purple-600"><?php echo $stats['early_visits']; ?></span>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Monthly Progress</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">This Month</span>
                            <span class="font-semibold"><?php echo $month_stats['month_visits']; ?> visits</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">On Time Rate</span>
                            <span class="font-semibold text-green-600">
                                <?php 
                                $on_time_rate = $month_stats['month_visits'] > 0 
                                    ? round(($month_stats['month_on_time'] / $month_stats['month_visits']) * 100, 1)
                                    : 0;
                                echo $on_time_rate . '%';
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Goal (20 visits)</span>
                            <span class="font-semibold">
                                <?php 
                                $progress = min(100, ($month_stats['month_visits'] / 20) * 100);
                                echo round($progress, 1) . '%';
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
                <h2 class="text-lg font-semibold mb-4">Filter Records</h2>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>" 
                               class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-red-500 focus:border-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <select name="status" class="w-full p-2 border border-gray-300 rounded focus:ring-2 focus:ring-red-500 focus:border-red-500">
                            <option value="">All Status</option>
                            <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="early" <?php echo $status_filter === 'early' ? 'selected' : ''; ?>>Early</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="bg-red-600 text-white px-6 py-2 rounded hover:bg-red-700">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                        <a href="attendance_history.php" class="bg-gray-500 text-white px-6 py-2 rounded hover:bg-gray-600">
                            <i class="fas fa-times mr-2"></i>Clear
                        </a>
                    </div>
                </form>
            </div>

            <!-- Attendance Records Table -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold">My Attendance Records</h2>
                    <p class="text-sm text-gray-600">Showing <?php echo count($attendance_records); ?> of <?php echo $total_records; ?> records</p>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                        No attendance records found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendance_records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('M d, Y', strtotime($record['check_in_time'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('H:i:s', strtotime($record['check_in_time'])); ?>
                                            <?php if (isset($record['check_out_time']) && $record['check_out_time']): ?>
                                                <br><span class="text-xs text-gray-500">
                                                    Out: <?php echo date('H:i:s', strtotime($record['check_out_time'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $status_class = '';
                                            switch ($record['status']) {
                                                case 'Present':
                                                    $status_class = 'bg-green-100 text-green-800';
                                                    break;
                                                case 'Late':
                                                    $status_class = 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'Early':
                                                    $status_class = 'bg-purple-100 text-purple-800';
                                                    break;
                                                default:
                                                    $status_class = 'bg-gray-100 text-gray-800';
                                            }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php 
                                            if (isset($record['check_out_time']) && $record['check_out_time']) {
                                                $duration = strtotime($record['check_out_time']) - strtotime($record['check_in_time']);
                                                $hours = floor($duration / 3600);
                                                $minutes = floor(($duration % 3600) / 60);
                                                echo $hours . 'h ' . $minutes . 'm';
                                            } else {
                                                echo 'Active';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button onclick="viewDetails(<?php echo $record['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-eye mr-1"></i>Details
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Previous
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                    Next
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_records); ?></span> of 
                                    <span class="font-medium"><?php echo $total_records; ?></span> results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                           class="relative inline-flex items-center px-4 py-2 border text-sm font-medium 
                                                  <?php echo $i === $page ? 'z-10 bg-red-50 border-red-500 text-red-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Attendance Chart -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mt-8">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Monthly Attendance Trend</h3>
                <canvas id="attendanceChart" width="400" height="200"></canvas>
            </div>
        </div>
    </main>

    <script>
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
        });

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

        function viewDetails(recordId) {
            // Implement detailed view modal
            alert('View details for record ID: ' + recordId);
        }

        // Attendance Chart
        const ctx = document.getElementById('attendanceChart').getContext('2d');
        const attendanceChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                datasets: [{
                    label: 'Visits',
                    data: [<?php echo $month_stats['month_visits']; ?>, 0, 0, 0], // Placeholder data
                    borderColor: 'rgb(239, 68, 68)',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Auto-refresh every 5 minutes
        setInterval(function() {
            location.reload();
        }, 300000);

        // Simple Working Notification System
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing real-time notification system...');
            
            // Load dark mode preference
            const savedDarkMode = localStorage.getItem('darkMode');
            if (savedDarkMode === 'true') {
                document.body.classList.add('dark-mode');
                console.log('Dark mode loaded from localStorage');
            }
            
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const notificationBadge = document.getElementById('notificationBadge');
            const notificationList = document.getElementById('notificationList');
            
            console.log('Elements found:', { 
                button: notificationBtn, 
                dropdown: notificationDropdown, 
                badge: notificationBadge, 
                list: notificationList 
            });
            
            if (!notificationBtn || !notificationDropdown) {
                console.error('Notification elements not found!');
                return;
            }
            
            let notifications = [];
            let unreadCount = 0;
            
            // Fetch real-time notifications
            function fetchNotifications() {
                fetch('get_real_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            notifications = data.notifications;
                            unreadCount = data.unread_count;
                            updateBadge();
                            renderNotifications();
                            console.log('Fetched notifications:', notifications);
                        } else {
                            console.error('Failed to fetch notifications:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching notifications:', error);
                        // Fallback to sample notifications
                        notifications = [
                            { id: 1, title: 'Attendance Recorded', message: 'Your check-in has been recorded successfully.', read: false, type: 'info' },
                            { id: 2, title: 'Monthly Summary', message: 'Your monthly attendance summary is ready.', read: false, type: 'info' },
                            { id: 3, title: 'Attendance Goal', message: 'You\'re close to your monthly attendance goal!', read: false, type: 'goal' }
                        ];
                        unreadCount = notifications.length;
                        updateBadge();
                        renderNotifications();
                    });
            }
            
            // Update badge
            function updateBadge() {
                if (notificationBadge) {
                    if (unreadCount > 0) {
                        notificationBadge.textContent = unreadCount;
                        notificationBadge.classList.remove('hidden');
                    } else {
                        notificationBadge.classList.add('hidden');
                    }
                }
            }
            
            // Render notifications with enhanced styling
            function renderNotifications() {
                if (!notificationList) return;
                
                if (notifications.length === 0) {
                    notificationList.innerHTML = '<div class="text-center py-8 text-gray-500"><i class="fas fa-bell text-3xl mb-2"></i><p>No notifications</p></div>';
                    return;
                }
                
                notificationList.innerHTML = notifications.map(notification => {
                    const typeIcon = getTypeIcon(notification.type);
                    const typeColor = getTypeColor(notification.type);
                    const timeAgo = getTimeAgo(notification.timestamp);
                    
                    return `
                        <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-all duration-200">
                            <div class="flex items-start space-x-3">
                                <div class="w-2 h-2 rounded-full ${typeColor} mt-2 flex-shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate">${notification.title}</p>
                                        <span class="text-xs text-gray-400 ml-2">${timeAgo}</span>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1">${notification.message}</p>
                                    <div class="flex items-center mt-2 space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeBadgeColor(notification.type)}">
                                            ${typeIcon} ${notification.type}
                                        </span>
                                        ${!notification.read ? '<button onclick="markAsRead(\'' + notification.id + '\')" class="text-xs text-blue-600 hover:text-blue-800">Mark read</button>' : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            // Get type icon
            function getTypeIcon(type) {
                const icons = {
                    'warning': '<i class="fas fa-exclamation-triangle"></i>',
                    'info': '<i class="fas fa-info-circle"></i>',
                    'reminder': '<i class="fas fa-clock"></i>',
                    'goal': '<i class="fas fa-target"></i>',
                    'announcement': '<i class="fas fa-bullhorn"></i>',
                    'payment': '<i class="fas fa-credit-card"></i>',
                    'welcome': '<i class="fas fa-hand-wave"></i>'
                };
                return icons[type] || '<i class="fas fa-bell"></i>';
            }
            
            // Get type color
            function getTypeColor(type) {
                const colors = {
                    'warning': 'bg-yellow-500',
                    'info': 'bg-blue-500',
                    'reminder': 'bg-purple-500',
                    'goal': 'bg-green-500',
                    'announcement': 'bg-red-500',
                    'payment': 'bg-indigo-500',
                    'welcome': 'bg-pink-500'
                };
                return colors[type] || 'bg-gray-500';
            }
            
            // Get type badge color
            function getTypeBadgeColor(type) {
                const colors = {
                    'warning': 'bg-yellow-100 text-yellow-800',
                    'info': 'bg-blue-100 text-blue-800',
                    'reminder': 'bg-purple-100 text-purple-800',
                    'goal': 'bg-green-100 text-green-800',
                    'announcement': 'bg-red-100 text-red-800',
                    'payment': 'bg-indigo-100 text-indigo-800',
                    'welcome': 'bg-pink-100 text-pink-800'
                };
                return colors[type] || 'bg-gray-100 text-gray-800';
            }
            
            // Get time ago
            function getTimeAgo(timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = now - timestamp;
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
                return Math.floor(diff / 2592000) + 'mo ago';
            }
            
            // Global functions
            window.markAsRead = function(id) {
                const notification = notifications.find(n => n.id === id);
                if (notification && !notification.read) {
                    // Mark as read in backend
                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'notification_id=' + encodeURIComponent(id)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            notification.read = true;
                            unreadCount--;
                            updateBadge();
                            renderNotifications();
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notification as read:', error);
                        // Still update locally
                        notification.read = true;
                        unreadCount--;
                        updateBadge();
                        renderNotifications();
                    });
                }
            };
            
            window.markAllAsRead = function() {
                notifications.forEach(n => n.read = true);
                unreadCount = 0;
                updateBadge();
                renderNotifications();
            };
            
            window.clearAllNotifications = function() {
                notifications.length = 0;
                unreadCount = 0;
                updateBadge();
                renderNotifications();
            };
            
            // Toggle dropdown
            function toggleDropdown() {
                const isVisible = !notificationDropdown.classList.contains('invisible');
                console.log('Toggling dropdown, visibility:', isVisible);
                
                if (isVisible) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.remove('opacity-100', 'scale-100');
                } else {
                    notificationDropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.add('opacity-100', 'scale-100');
                    // Refresh notifications when opening
                    fetchNotifications();
                }
            }
            
            // Add click event
            notificationBtn.addEventListener('click', function(e) {
                console.log('Notification button clicked!');
                e.stopPropagation();
                toggleDropdown();
            });
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.remove('opacity-100', 'scale-100');
                }
            });
            
            // Initialize
            fetchNotifications();
            console.log('Real-time notification system initialized successfully!');
            
            // Refresh notifications every 5 minutes
            setInterval(fetchNotifications, 300000);
        });
    </script>
</body>
</html> 