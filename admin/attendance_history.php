<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

// Handle success messages from scanner redirect
$success_message = '';
if (isset($_GET['success'])) {
    $member = isset($_GET['member']) ? $_GET['member'] : 'Member';
    if ($_GET['success'] === 'checkin') {
        $success_message = "✅ Check-in successful for $member!";
    } elseif ($_GET['success'] === 'checkout') {
        $success_message = "✅ Check-out successful for $member!";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build the query with filters
$where_conditions = [];
$params = [];
$param_types = '';

if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

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

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Check if plan_id column exists
$plan_id_exists = false;
try {
    $columns = $conn->query("SHOW COLUMNS FROM attendance LIKE 'plan_id'")->fetch_all(MYSQLI_ASSOC);
    $plan_id_exists = count($columns) > 0;
} catch (Exception $e) {
    // Column doesn't exist, we'll use a simpler query
}

// Get total count for pagination
if ($plan_id_exists) {
    $count_sql = "SELECT COUNT(*) as total FROM attendance a 
                  JOIN users u ON a.user_id = u.id 
                  LEFT JOIN membership_plans mp ON a.plan_id = mp.id 
                  $where_clause";
} else {
    $count_sql = "SELECT COUNT(*) as total FROM attendance a 
                  JOIN users u ON a.user_id = u.id 
                  $where_clause";
}

if (!empty($params)) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($param_types, ...$params);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_records = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

// Get attendance records
if ($plan_id_exists) {
    $sql = "SELECT a.*, u.full_name, u.username, u.email, u.profile_picture, mp.name as plan_name,
                   CASE 
                       WHEN TIME(a.check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 'Present'
                       WHEN TIME(a.check_in_time) > '22:00:00' THEN 'Late'
                       WHEN TIME(a.check_in_time) < '06:00:00' THEN 'Early'
                       ELSE 'Present'
                   END as status
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            LEFT JOIN membership_plans mp ON a.plan_id = mp.id 
            $where_clause
            ORDER BY a.check_in_time DESC 
            LIMIT ? OFFSET ?";
} else {
    $sql = "SELECT a.*, u.full_name, u.username, u.email, u.profile_picture, 'No Plan' as plan_name,
                   CASE 
                       WHEN TIME(a.check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 'Present'
                       WHEN TIME(a.check_in_time) > '22:00:00' THEN 'Late'
                       WHEN TIME(a.check_in_time) < '06:00:00' THEN 'Early'
                       ELSE 'Present'
                   END as status
            FROM attendance a 
            JOIN users u ON a.user_id = u.id 
            $where_clause
            ORDER BY a.check_in_time DESC 
            LIMIT ? OFFSET ?";
}

$params[] = $per_page;
$params[] = $offset;
$param_types .= 'ii';

$stmt = $conn->prepare($sql);
if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent statistics (last 7 days)
$today = date('Y-m-d');
$week_ago = date('Y-m-d', strtotime('-7 days'));
$today_stats_sql = "SELECT 
                        COUNT(*) as total_checkins,
                        COUNT(CASE WHEN TIME(check_in_time) BETWEEN '06:00:00' AND '22:00:00' THEN 1 END) as on_time,
                        COUNT(CASE WHEN TIME(check_in_time) > '22:00:00' THEN 1 END) as late,
                        COUNT(CASE WHEN TIME(check_in_time) < '06:00:00' THEN 1 END) as early
                    FROM attendance 
                    WHERE DATE(check_in_time) >= ?";
$today_stmt = $conn->prepare($today_stats_sql);
$today_stmt->bind_param('s', $week_ago);
$today_stmt->execute();
$today_stats = $today_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance History - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen">
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
                        Attendance History
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
                            <img src="https://i.pravatar.cc/40?img=1" alt="Admin Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
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

        <!-- Main Content -->
        <main class="ml-64 mt-16 p-6 transition-all duration-300">
            <div class="max-w-7xl mx-auto">
            <!-- Success Message -->
            <?php if ($success_message): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?php echo htmlspecialchars($success_message); ?></span>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <title>Close</title>
                            <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                        </svg>
                    </span>
                </div>
            <?php endif; ?>
            
            <!-- Database Fix Notice -->
            <?php if (!$plan_id_exists): ?>
                <div class="mb-6 bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative" role="alert">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="block sm:inline">
                            <strong>Database Update Required:</strong> The attendance table needs to be updated to show plan information. 
                            <a href="fix_attendance_table.php" class="underline font-semibold">Click here to fix</a>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Today's Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Check-ins (7 days)</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $today_stats['total_checkins']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">On Time (7 days)</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $today_stats['on_time']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-exclamation-triangle text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Late (7 days)</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $today_stats['late']; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-sun text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Early (7 days)</p>
                            <p class="text-2xl font-bold text-gray-900"><?php echo $today_stats['early']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compact Filters -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-filter text-red-500 mr-2"></i>Filters
                    </h2>
                    <div class="flex items-center space-x-2">
                        <button type="submit" form="filterForm" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors">
                            <i class="fas fa-search mr-1"></i>Filter
                        </button>
                        <a href="attendance_history.php" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 transition-colors">
                            <i class="fas fa-times mr-1"></i>Clear
                        </a>
                        <a href="export_attendance.php?<?php echo http_build_query($_GET); ?>" 
                           class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 transition-colors">
                            <i class="fas fa-download mr-1"></i>Export CSV
                        </a>
                    </div>
                </div>
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search Member</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Name, username, or email" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>" 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                            <option value="">All Status</option>
                            <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                            <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
                            <option value="early" <?php echo $status_filter === 'early' ? 'selected' : ''; ?>>Early</option>
                        </select>
                    </div>
                </form>
            </div>

            <!-- Compact Attendance Records Table -->
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="px-4 py-4 border-b border-gray-200 bg-gray-50">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                                <i class="fas fa-list-alt text-red-500 mr-2"></i>Attendance Records
                            </h2>
                            <p class="text-sm text-gray-600 mt-1">Showing <?php echo count($attendance_records); ?> of <?php echo $total_records; ?> records</p>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                <i class="fas fa-clock mr-1"></i>Real-time
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-user mr-1"></i>Name
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-id-badge mr-1"></i>User ID
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-calendar mr-1"></i>Date
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-clock mr-1"></i>Time
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-check-circle mr-1"></i>Status
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-id-card mr-1"></i>Plan
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <i class="fas fa-cogs mr-1"></i>Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($attendance_records)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center">
                                        <div class="flex flex-col items-center text-gray-500">
                                            <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                                            <p class="text-lg font-medium">No attendance records found</p>
                                            <p class="text-sm text-gray-400 mt-1">Records will appear here when members check in</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attendance_records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <?php if (!empty($record['profile_picture'])): ?>
                                                        <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($record['profile_picture']); ?>" 
                                                             alt="Profile Picture" 
                                                             class="h-10 w-10 rounded-full object-cover shadow-sm border border-gray-200">
                                                    <?php else: ?>
                                                        <div class="h-10 w-10 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center shadow-sm">
                                                            <span class="text-white font-bold text-xs">
                                                                <?php echo strtoupper(substr($record['full_name'] ?? $record['username'] ?? 'U', 0, 1)); ?>
                                                            </span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ml-3">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($record['full_name'] ?? $record['username']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($record['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                <i class="fas fa-hashtag mr-1"></i>
                                                <?php echo $record['user_id']; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo date('M d, Y', strtotime($record['check_in_time'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('l', strtotime($record['check_in_time'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo date('H:i:s', strtotime($record['check_in_time'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('A', strtotime($record['check_in_time'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <?php
                                            $status_class = '';
                                            $status_icon = '';
                                            switch ($record['status']) {
                                                case 'Present':
                                                    $status_class = 'bg-green-100 text-green-800';
                                                    $status_icon = 'fas fa-check-circle';
                                                    break;
                                                case 'Late':
                                                    $status_class = 'bg-yellow-100 text-yellow-800';
                                                    $status_icon = 'fas fa-exclamation-triangle';
                                                    break;
                                                case 'Early':
                                                    $status_class = 'bg-purple-100 text-purple-800';
                                                    $status_icon = 'fas fa-sun';
                                                    break;
                                                default:
                                                    $status_class = 'bg-gray-100 text-gray-800';
                                                    $status_icon = 'fas fa-clock';
                                            }
                                            ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold <?php echo $status_class; ?>">
                                                <i class="<?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-id-card mr-1"></i>
                                                <?php echo htmlspecialchars($record['plan_name'] ?? 'No Plan'); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center space-x-1">
                                                <button onclick="viewDetails(<?php echo $record['id']; ?>)" 
                                                        class="p-1 bg-red-100 text-red-600 rounded hover:bg-red-200 hover:text-red-700 transition-colors">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="exportRecord(<?php echo $record['id']; ?>)" 
                                                        class="p-1 bg-blue-100 text-blue-600 rounded hover:bg-blue-200 hover:text-blue-700 transition-colors">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            </div>
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
        </main>
    </div>

    <!-- Auto-refresh script -->
    <script>
        // Auto-refresh every 30 seconds to show new attendance records
        setInterval(function() {
            location.reload();
        }, 30000);

        // Auto-submit form when filters change
        document.addEventListener('DOMContentLoaded', function() {
            const statusFilter = document.querySelector('select[name="status"]');
            const dateFilter = document.querySelector('input[name="date"]');
            const searchInput = document.querySelector('input[name="search"]');
            const form = document.querySelector('form');

            // Auto-submit when status filter changes
            if (statusFilter) {
                statusFilter.addEventListener('change', function() {
                    form.submit();
                });
            }

            // Auto-submit when date filter changes
            if (dateFilter) {
                dateFilter.addEventListener('change', function() {
                    form.submit();
                });
            }

            // Auto-submit when search input changes (with delay)
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        form.submit();
                    }, 500); // Wait 500ms after user stops typing
                });
            }
        });

        function viewDetails(recordId) {
            // Implement detailed view modal
            alert('View details for record ID: ' + recordId);
        }

        function exportRecord(recordId) {
            // Implement export functionality
            window.open('export_attendance.php?id=' + recordId, '_blank');
        }

        // Real-time updates using AJAX (optional enhancement)
        function checkForNewRecords() {
            $.ajax({
                url: 'get_latest_attendance.php',
                method: 'GET',
                success: function(data) {
                    if (data.hasNewRecords) {
                        location.reload();
                    }
                }
            });
        }

        // Check for new records every 10 seconds
        setInterval(checkForNewRecords, 10000);

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
    </script>
</body>
</html> 
