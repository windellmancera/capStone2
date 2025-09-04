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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $title = trim($_POST['title']);
            $content = trim($_POST['content']);
            
            if (empty($title) || empty($content)) {
                $message = "Please fill in all required fields.";
                $messageClass = 'error';
            } else {
                if ($_POST['action'] === 'add') {
                    $sql = "INSERT INTO announcements (title, content, created_at) VALUES (?, ?, NOW())";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ss", $title, $content);
                } else {
                    $id = $_POST['announcement_id'];
                    $sql = "UPDATE announcements SET title = ?, content = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssi", $title, $content, $id);
                }
                
                if ($stmt->execute()) {
                    $msg = ($_POST['action'] === 'add' ? "added" : "updated");
                    header("Location: manage_announcements.php?success=Announcement+$msg+successfully!");
                    exit();
                } else {
                    header("Location: manage_announcements.php?error=".urlencode("Error: ".$conn->error));
                    exit();
                }
            }
        } elseif ($_POST['action'] === 'delete' && isset($_POST['announcement_id'])) {
            $id = $_POST['announcement_id'];
            $sql = "DELETE FROM announcements WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                header("Location: manage_announcements.php?success=Announcement+deleted+successfully!");
                exit();
            } else {
                header("Location: manage_announcements.php?error=".urlencode("Error: ".$conn->error));
                exit();
            }
        }
    }
}

// Show messages from GET
if (isset($_GET['success'])) {
    $message = $_GET['success'];
    $messageClass = 'success';
} elseif (isset($_GET['error'])) {
    $message = $_GET['error'];
    $messageClass = 'error';
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

// Fetch all announcements
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");

// Default profile picture and display name
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Manage Announcements';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Announcements - Admin Dashboard</title>
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
        }

        .navbar {
            background-color: #2D2D2D;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 0 20px rgba(0, 157, 255, 0.25);
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .navbar-brand {
            color: white;
            text-decoration: none;
            font-size: 1.5rem;
            font-weight: bold;
        }

        .navbar-menu {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .navbar-menu a {
            color: white;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .navbar-menu a:hover {
            color: #009DFF;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #2D2D2D;
            min-width: 160px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 5px;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            transition: background-color 0.3s;
        }

        .dropdown-content a:hover {
            background-color: #3D3D3D;
            color: #009DFF;
        }

        .logout-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            transition: background-color 0.3s;
        }

        .logout-btn:hover {
            background-color: #c82333;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        textarea.form-control {
            height: 150px;
            resize: vertical;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0056b3;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .announcement-list {
            margin-top: 2rem;
        }

        .announcement-item {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .announcement-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .announcement-title {
            font-size: 1.2rem;
            font-weight: bold;
        }

        .announcement-date {
            color: #666;
            font-size: 0.9rem;
        }

        .announcement-actions {
            display: flex;
            gap: 0.5rem;
        }

        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 768px) {
            .navbar-container {
                flex-direction: column;
                text-align: center;
            }

            .navbar-menu {
                margin-top: 1rem;
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
        
        /* Enhanced form styling */
        .form-control:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
        }
        
        /* Card hover effects */
        .announcement-card {
            transition: all 0.3s ease;
        }
        
        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Button animations */
        .btn-animate {
            transition: all 0.2s ease;
        }
        
        .btn-animate:hover {
            transform: translateY(-1px);
        }
        
        /* Content truncation */
        .content-preview {
            line-height: 1.6;
            max-height: 4.8em;
            overflow: hidden;
        }
        
        /* Responsive improvements */
        @media (max-width: 768px) {
            .announcement-actions {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .announcement-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
        
        /* Custom scrollbar for announcements */
        .announcements-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .announcements-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .announcements-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .announcements-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Ensure page stays fixed */
        body {
            overflow-x: hidden;
        }
        
        /* Prevent horizontal scrolling on the main page */
        .main-content {
            overflow-x: hidden;
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
                    <?php echo $page_title ?? 'Manage Announcements'; ?>
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
                <div class="mb-6 p-4 rounded-lg border <?php echo $messageClass === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $messageClass === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-3"></i>
                        <?php echo $message; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg p-6 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-blue-100 text-sm font-medium">Total Announcements</p>
                            <p class="text-3xl font-bold"><?php echo $announcements ? $announcements->num_rows : 0; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-400 rounded-full flex items-center justify-center">
                            <i class="fas fa-bullhorn text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg p-6 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-green-100 text-sm font-medium">This Month</p>
                            <p class="text-3xl font-bold">
                                <?php 
                                $this_month = $conn->query("SELECT COUNT(*) as count FROM announcements WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
                                echo $this_month->fetch_assoc()['count'];
                                ?>
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-green-400 rounded-full flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg p-6 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-purple-100 text-sm font-medium">Last Updated</p>
                            <p class="text-lg font-semibold">
                                <?php 
                                $latest = $conn->query("SELECT created_at FROM announcements ORDER BY created_at DESC LIMIT 1");
                                if ($latest && $latest->num_rows > 0) {
                                    echo date('M d, Y', strtotime($latest->fetch_assoc()['created_at']));
                                } else {
                                    echo 'Never';
                                }
                                ?>
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-purple-400 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two Column Layout -->
            <!-- Current Announcements - Main Content -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Current Announcements</h2>
                    <div class="flex items-center space-x-4">
                        <!-- Search Bar -->
                        <div class="relative">
                            <input type="text" id="searchInput" placeholder="Search announcements..." 
                                   class="pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 w-64">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        
                        <!-- Add New Announcement Button -->
                        <button onclick="showAddForm()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all duration-200 font-medium">
                            <i class="fas fa-plus mr-2"></i>Add New
                        </button>
                        
                        <span class="text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-1"></i>
                            <span id="announcementCount"><?php echo $announcements ? $announcements->num_rows : 0; ?></span> announcement(s)
                        </span>
                    </div>
                </div>


                    
                    <div class="announcements-scroll-container" style="max-height: 500px; overflow-y: auto; overflow-x: hidden;">
                        <?php if ($announcements && $announcements->num_rows > 0): ?>
                            <div class="space-y-3">
                                <?php while($announcement = $announcements->fetch_assoc()): ?>
                                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200 hover:shadow-md transition-all duration-200">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="flex-1">
                                                <h3 class="text-base font-semibold text-gray-800 mb-2">
                                                    <i class="fas fa-bullhorn mr-2 text-red-500"></i>
                                                    <?php echo htmlspecialchars($announcement['title']); ?>
                                                </h3>
                                                <div class="text-gray-600 text-sm leading-relaxed mb-2">
                                                    <?php 
                                                    $content = htmlspecialchars($announcement['content']);
                                                    if (strlen($content) > 150) {
                                                        echo substr($content, 0, 150) . '...';
                                                        echo '<button onclick="showFullContent(' . $announcement['id'] . ')" class="text-red-600 hover:text-red-800 font-medium ml-2">Read more</button>';
                                                    } else {
                                                        echo $content;
                                                    }
                                                    ?>
                                                </div>
                                                <div class="flex items-center text-xs text-gray-500">
                                                    <i class="fas fa-calendar mr-1"></i>
                                                    <?php echo date('M d, Y', strtotime($announcement['created_at'])); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="flex items-center space-x-1 ml-3">
                                                <button onclick="editAnnouncement(<?php echo $announcement['id']; ?>)" 
                                                        class="px-2 py-1 bg-blue-600 text-white text-xs rounded-md hover:bg-blue-700 transition-all duration-200 font-medium">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </button>
                                                <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this announcement? This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="announcement_id" value="<?php echo $announcement['id']; ?>">
                                                    <button type="submit" class="px-2 py-1 bg-red-600 text-white text-xs rounded-md hover:bg-red-700 transition-all duration-200 font-medium">
                                                        <i class="fas fa-trash mr-1"></i>Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        
                                        <!-- Hidden full content -->
                                        <div id="fullContent_<?php echo $announcement['id']; ?>" class="hidden mt-3 p-3 bg-white rounded-md border border-gray-200">
                                            <h4 class="font-semibold text-gray-800 mb-2 text-sm">Full Content:</h4>
                                            <p class="text-gray-700 leading-relaxed text-sm"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                            <button onclick="hideFullContent(<?php echo $announcement['id']; ?>)" class="mt-2 text-red-600 hover:text-red-800 font-medium text-xs">
                                                <i class="fas fa-chevron-up mr-1"></i>Show less
                                            </button>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-12">
                                <div class="text-gray-400 mb-4">
                                    <i class="fas fa-bullhorn text-6xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-600 mb-2">No announcements yet</h3>
                                <p class="text-gray-500">Create your first announcement to keep members informed!</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Add/Edit Announcement Modal -->
            <div id="announcementModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
                        <div class="flex items-center justify-between p-6 border-b border-gray-200">
                            <h2 id="modalTitle" class="text-xl font-semibold text-gray-800">Add New Announcement</h2>
                            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        
                        <form id="announcementForm" action="" method="POST" class="p-6 space-y-4">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="announcement_id" value="">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-heading mr-2 text-red-500"></i>Title *
                                </label>
                                <input type="text" name="title" required 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200"
                                       placeholder="Enter announcement title">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-align-left mr-2 text-red-500"></i>Content *
                                </label>
                                <textarea name="content" rows="6" required 
                                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200 resize-none"
                                          placeholder="Enter announcement content"></textarea>
                            </div>
                            
                            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                                <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors duration-200">
                                    Cancel
                                </button>
                                <button type="submit" id="submitButton" 
                                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all duration-200 font-medium">
                                    <i class="fas fa-plus mr-2"></i>Add Announcement
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

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

        // Edit Announcement Function
        function editAnnouncement(id) {
            const announcements = <?php echo json_encode($announcements->fetch_all(MYSQLI_ASSOC)); ?>;
            const announcement = announcements.find(a => a.id === id);
            
            if (announcement) {
                const form = document.getElementById('announcementForm');
                const formTitle = document.getElementById('formTitle');
                const submitButton = document.getElementById('submitButton');
                
                form.querySelector('[name="title"]').value = announcement.title;
                form.querySelector('[name="content"]').value = announcement.content;
                form.querySelector('[name="action"]').value = 'edit';
                form.querySelector('[name="announcement_id"]').value = id;
                
                formTitle.textContent = 'Edit Announcement';
                submitButton.innerHTML = '<i class="fas fa-save mr-2"></i>Update Announcement';
                
                form.scrollIntoView({ behavior: 'smooth' });
            }
        }
        
        // Reset Form Function
        function resetForm() {
            const form = document.getElementById('announcementForm');
            const formTitle = document.getElementById('formTitle');
            const submitButton = document.getElementById('submitButton');
            
            form.reset();
            form.querySelector('[name="action"]').value = 'add';
            form.querySelector('[name="announcement_id"]').value = '';
            
            formTitle.textContent = 'Add New Announcement';
            submitButton.innerHTML = '<i class="fas fa-plus mr-2"></i>Add Announcement';
        }
        
        // Show Full Content Function
        function showFullContent(id) {
            document.getElementById('fullContent_' + id).classList.remove('hidden');
        }
        
        // Hide Full Content Function
        function hideFullContent(id) {
            document.getElementById('fullContent_' + id).classList.add('hidden');
        }
        
        // Search Functionality
        document.getElementById('searchInput').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const announcementCards = document.querySelectorAll('.bg-gray-50.rounded-lg');
            let visibleCount = 0;
            
            announcementCards.forEach(card => {
                const title = card.querySelector('h3').textContent.toLowerCase();
                const content = card.querySelector('.text-gray-600').textContent.toLowerCase();
                
                if (title.includes(searchTerm) || content.includes(searchTerm)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Update count
            document.getElementById('announcementCount').textContent = visibleCount;
            
            // Show no results message if needed
            if (visibleCount === 0 && searchTerm !== '') {
                if (!document.getElementById('noResultsMessage')) {
                    const noResults = document.createElement('div');
                    noResults.id = 'noResultsMessage';
                    noResults.className = 'text-center py-8 text-gray-500';
                    noResults.innerHTML = `
                        <i class="fas fa-search text-4xl mb-3"></i>
                        <p>No announcements found matching "${searchTerm}"</p>
                    `;
                    document.querySelector('.space-y-4').appendChild(noResults);
                }
            } else {
                const noResults = document.getElementById('noResultsMessage');
                if (noResults) {
                    noResults.remove();
                }
            }
        });
        
        // Modal Functions
        function showAddForm() {
            document.getElementById('announcementModal').classList.remove('hidden');
            document.getElementById('modalTitle').textContent = 'Add New Announcement';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-plus mr-2"></i>Add Announcement';
            document.getElementById('announcementForm').reset();
            document.getElementById('announcementForm').querySelector('[name="action"]').value = 'add';
            document.getElementById('announcementForm').querySelector('[name="announcement_id"]').value = '';
        }
        
        function closeModal() {
            document.getElementById('announcementModal').classList.add('hidden');
        }
        
        // Enhanced Edit Function
        function editAnnouncement(id) {
            const announcements = <?php echo json_encode($announcements->fetch_all(MYSQLI_ASSOC)); ?>;
            const announcement = announcements.find(a => a.id === id);
            
            if (announcement) {
                document.getElementById('announcementModal').classList.remove('hidden');
                document.getElementById('modalTitle').textContent = 'Edit Announcement';
                document.getElementById('submitButton').innerHTML = '<i class="fas fa-save mr-2"></i>Update Announcement';
                
                const form = document.getElementById('announcementForm');
                form.querySelector('[name="title"]').value = announcement.title;
                form.querySelector('[name="content"]').value = announcement.content;
                form.querySelector('[name="action"]').value = 'edit';
                form.querySelector('[name="announcement_id"]').value = id;
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('announcementModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_announcements.php...');
        
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
            
            console.log('Real-time SSE notification system initialized successfully for manage_announcements.php!');
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