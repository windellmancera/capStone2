<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Check if user has the correct role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'member') {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';

// Initialize variables
$display_name = 'User';
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$page_title = 'Workout Progress';
$user = [];
$progress_data = [];
$error_message = null;

try {
    // Get user information
    $user_id = $_SESSION['user_id'];
    
    $user_sql = "SELECT * FROM users WHERE id = ?";
    $stmt = $conn->prepare($user_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Check if user exists in database
    if (!$user) {
        // User doesn't exist in database, redirect to login
        session_destroy();
        header("Location: member_login.php");
        exit();
    }
    
    // Set display name and profile picture
    $display_name = $user['full_name'] ?? $user['username'] ?? $user['email'] ?? 'User';
    $profile_picture = $user['profile_picture'] 
        ? "../uploads/profile_pictures/" . $user['profile_picture']
        : 'https://i.pravatar.cc/40?img=1';

    // Get progress data from database
    // Ensure table exists
    $tblCheck = $conn->query("SHOW TABLES LIKE 'member_progress'");
    if (!$tblCheck || $tblCheck->num_rows === 0) {
        throw new Exception("Missing member_progress table. Please run sql/create_progress_tables.sql.");
    }

    $progress_sql = "SELECT * FROM member_progress WHERE user_id = ? ORDER BY date_recorded DESC";
    $stmt = $conn->prepare($progress_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare progress query: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute progress query: " . $stmt->error);
    }
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $progress_data[] = $row;
    }
    $stmt->close();

    // If no real data exists, start with empty array
    if (empty($progress_data)) {
        $progress_data = [];
    }

    // Get workout performance data
    // Ensure table exists
    $tblCheck2 = $conn->query("SHOW TABLES LIKE 'workout_performance'");
    if (!$tblCheck2 || $tblCheck2->num_rows === 0) {
        throw new Exception("Missing workout_performance table. Please run sql/create_progress_tables.sql.");
    }

    $workout_sql = "SELECT * FROM workout_performance WHERE user_id = ? ORDER BY date_performed DESC LIMIT 10";
    $stmt = $conn->prepare($workout_sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare workout query: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute workout query: " . $stmt->error);
    }
    $result = $stmt->get_result();
    
    $workout_performance = [];
    while ($row = $result->fetch_assoc()) {
        $workout_performance[] = $row;
    }
    $stmt->close();

    // If no workout data, start with empty array
    if (empty($workout_performance)) {
        $workout_performance = [];
    }

} catch (Exception $e) {
    error_log("Error in progress.php: " . $e->getMessage());
    $error_message = "An error occurred while loading progress data. Please try again later.";
}

// Helper function to calculate progress percentage
function calculateProgress($current, $previous) {
    if ($previous == 0) return 0;
    return round((($current - $previous) / $previous) * 100, 1);
}

// Helper function to get progress color
function getProgressColor($percentage) {
    if ($percentage > 0) return 'text-green-600 bg-green-100';
    if ($percentage < 0) return 'text-red-600 bg-red-100';
    return 'text-gray-600 bg-gray-100';
}

// Helper function to get progress icon
function getProgressIcon($percentage) {
    if ($percentage > 0) return 'fas fa-arrow-up';
    if ($percentage < 0) return 'fas fa-arrow-down';
    return 'fas fa-minus';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Almo Fitness Gym</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="css/dark-mode.css">
    <style>
        /* Custom scrollbar */
        .sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.5);
        }

        /* Smooth transitions */
        .transition-all {
            transition: all 0.3s ease;
        }

        /* Progress bar animation */
        .progress-bar {
            transition: width 0.8s ease-in-out;
        }

        /* Card hover effects */
        .progress-card {
            transition: all 0.3s ease;
        }
        .progress-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        /* Before/After image styling */
        .before-after-container {
            position: relative;
            overflow: hidden;
        }

        .before-after-slider {
            position: absolute;
            top: 0;
            left: 50%;
            width: 2px;
            height: 100%;
            background: #ef4444;
            cursor: ew-resize;
            z-index: 10;
        }

        .before-after-slider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            background: #ef4444;
            border-radius: 50%;
            border: 2px solid white;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .progress-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Ensure sidebar navigation links are always visible */
        #sidebar nav a {
            visibility: visible !important;
            opacity: 1 !important;
            display: flex !important;
            align-items: center !important;
            min-height: 2.5rem !important;
        }

        /* Prevent accordion-like behavior */
        #sidebar nav a:hover {
            visibility: visible !important;
            opacity: 1 !important;
        }

        #sidebar nav a:active {
            visibility: visible !important;
            opacity: 1 !important;
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

    <!-- Top Bar (Responsive) -->
    <div class="w-full flex justify-center items-center mt-6 mb-2 px-4 sm:px-6">
        <header class="shadow-2xl drop-shadow-2xl w-full max-w-7xl rounded-2xl bg-clip-padding" style="background: linear-gradient(to right, #18181b 0%, #7f1d1d 100%);">
            <div class="px-4 sm:px-8 py-4 sm:py-5 flex items-center justify-between">
                <div class="min-w-0 flex-1">
                    <span class="block text-base sm:text-xl font-semibold text-white truncate" style="font-family: 'Segoe UI', 'Inter', sans-serif; letter-spacing: 0.01em;">
                        <?php echo $page_title; ?>
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
                        <button id="profileDropdown" class="flex items-center space-x-2 sm:space-x-3 p-1.5 sm:p-2 rounded-lg hover:bg-gray-700/30 transition-colors">
                            <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full border-2 border-gray-200 object-cover">
                            <div class="hidden sm:block text-left">
                                <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($display_name); ?></h3>
                                <p class="text-sm text-gray-200 drop-shadow">Member</p>
                            </div>
                            <i class="fas fa-chevron-down text-gray-300 text-xs sm:text-sm transition-transform duration-200" id="dropdownArrow"></i>
                        </button>
                        <!-- Dropdown Menu -->
                        <div id="profileMenu" class="absolute right-0 mt-2 w-44 sm:w-48 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50">
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
            </div>
        </header>
    </div>

    <!-- Main Content -->
    <main class="ml-64 mt-16 p-8" id="mainContent">
        <div class="max-w-7xl mx-auto space-y-8">

            <!-- Page Header -->
            <div class="bg-gradient-to-r from-red-600 to-red-700 rounded-xl shadow-lg p-6 text-white">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold mb-2">Before & After Progress Tracker</h1>
                        <p class="text-red-100">Track your fitness transformation and workout improvements</p>
                    </div>
                    <div class="text-right">
                        <button onclick="openAddProgressModal()" class="bg-white text-red-600 px-6 py-3 rounded-lg font-semibold hover:bg-gray-100 transition-colors">
                            <i class="fas fa-plus mr-2"></i>Add Progress
                        </button>
                    </div>
                </div>
            </div>

            <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <!-- Progress Overview Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <?php
                if (count($progress_data) >= 2) {
                    $latest = $progress_data[0];
                    $previous = $progress_data[1];
                    
                    $weight_change = calculateProgress($latest['weight'], $previous['weight']);
                    $body_fat_change = calculateProgress($latest['body_fat'], $previous['body_fat']);
                    $muscle_change = calculateProgress($latest['muscle_mass'], $previous['muscle_mass']);
                    $chest_change = calculateProgress($latest['chest'], $previous['chest']);
                } else {
                    $weight_change = $body_fat_change = $muscle_change = $chest_change = 0;
                }
                ?>
                
                <!-- Weight Progress -->
                <div class="bg-white rounded-xl shadow-lg p-6 progress-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 text-white">
                                <i class="fas fa-weight text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Current Weight</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php if (isset($latest) && $latest['weight']): ?>
                                        <?php echo $latest['weight']; ?> kg
                                    <?php else: ?>
                                        <span class="text-gray-400">No data yet</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php if (isset($latest) && isset($previous)): ?>
                                <span class="text-sm font-medium <?php echo getProgressColor($weight_change); ?>">
                                    <i class="<?php echo getProgressIcon($weight_change); ?> mr-1"></i>
                                    <?php echo $weight_change; ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-sm font-medium text-gray-400">
                                    <i class="fas fa-minus mr-1"></i>
                                    --
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Body Fat Progress -->
                <div class="bg-white rounded-xl shadow-lg p-6 progress-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-gradient-to-br from-green-500 to-green-600 text-white">
                                <i class="fas fa-percentage text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Body Fat</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php if (isset($latest) && $latest['body_fat']): ?>
                                        <?php echo $latest['body_fat']; ?>%
                                    <?php else: ?>
                                        <span class="text-gray-400">No data yet</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php if (isset($latest) && isset($previous)): ?>
                                <span class="text-sm font-medium <?php echo getProgressColor($body_fat_change); ?>">
                                    <i class="<?php echo getProgressIcon($body_fat_change); ?> mr-1"></i>
                                    <?php echo $body_fat_change; ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-sm font-medium text-gray-400">
                                    <i class="fas fa-minus mr-1"></i>
                                    --
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Muscle Mass Progress -->
                <div class="bg-white rounded-xl shadow-lg p-6 progress-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 text-white">
                                <i class="fas fa-dumbbell text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Muscle Mass</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php if (isset($latest) && $latest['muscle_mass']): ?>
                                        <?php echo $latest['muscle_mass']; ?> kg
                                    <?php else: ?>
                                        <span class="text-gray-400">No data yet</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php if (isset($latest) && isset($previous)): ?>
                                <span class="text-sm font-medium <?php echo getProgressColor($muscle_change); ?>">
                                    <i class="<?php echo getProgressIcon($muscle_change); ?> mr-1"></i>
                                    <?php echo $muscle_change; ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-sm font-medium text-gray-400">
                                    <i class="fas fa-minus mr-1"></i>
                                    --
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Chest Progress -->
                <div class="bg-white rounded-xl shadow-lg p-6 progress-card">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-gradient-to-br from-yellow-500 to-yellow-600 text-white">
                                <i class="fas fa-ruler text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600">Chest</p>
                                <p class="text-2xl font-bold text-gray-900">
                                    <?php if (isset($latest) && $latest['chest']): ?>
                                        <?php echo $latest['chest']; ?> cm
                                    <?php else: ?>
                                        <span class="text-gray-400">No data yet</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="text-right">
                            <?php if (isset($latest) && isset($previous)): ?>
                                <span class="text-sm font-medium <?php echo getProgressColor($chest_change); ?>">
                                    <i class="<?php echo getProgressIcon($chest_change); ?> mr-1"></i>
                                    <?php echo $chest_change; ?>%
                                </span>
                            <?php else: ?>
                                <span class="text-sm font-medium text-gray-400">
                                    <i class="fas fa-minus mr-1"></i>
                                    --
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Before & After Progress Timeline -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-xl font-bold text-gray-900 mb-6">Progress Timeline</h3>
                
                <?php if (empty($progress_data)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-chart-line text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600">No progress data yet. Start tracking your fitness journey!</p>
                        <button onclick="openAddProgressModal()" class="mt-4 bg-red-600 text-white px-6 py-2 rounded-lg hover:bg-red-700 transition-colors">
                            Add First Progress Entry
                        </button>
                    </div>
                <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($progress_data as $index => $progress): ?>
                            <div class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between mb-4">
                                    <div>
                                        <h4 class="text-lg font-semibold text-gray-900">
                                            Progress Entry - <?php echo date('M d, Y', strtotime($progress['date_recorded'])); ?>
                                        </h4>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($progress['notes']); ?></p>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button onclick="editProgress(<?php echo $progress['id']; ?>)" class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button onclick="deleteProgress(<?php echo $progress['id']; ?>)" class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Weight</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $progress['weight']; ?> kg</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Body Fat</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $progress['body_fat']; ?>%</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Muscle Mass</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $progress['muscle_mass']; ?> kg</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Chest</p>
                                        <p class="text-lg font-bold text-gray-900"><?php echo $progress['chest']; ?> cm</p>
                                    </div>
                                </div>
                                
                                <!-- Measurements -->
                                <div class="mt-4 grid grid-cols-3 gap-4">
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Waist</p>
                                        <p class="font-semibold text-gray-900"><?php echo $progress['waist']; ?> cm</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Arms</p>
                                        <p class="font-semibold text-gray-900"><?php echo $progress['arms']; ?> cm</p>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm text-gray-600">Legs</p>
                                        <p class="font-semibold text-gray-900"><?php echo $progress['legs']; ?> cm</p>
                                    </div>
                                </div>
                                
                                <!-- Before/After Photos -->
                                <?php if ($progress['photo_before'] || $progress['photo_after']): ?>
                                    <div class="mt-4">
                                        <p class="text-sm font-medium text-gray-700 mb-2">Progress Photos</p>
                                        <div class="flex space-x-4">
                                            <?php if ($progress['photo_before']): ?>
                                                <div class="text-center">
                                                    <p class="text-xs text-gray-600 mb-1">Before</p>
                                                    <img src="../uploads/progress_photos/<?php echo $progress['photo_before']; ?>" 
                                                         alt="Before" class="w-24 h-24 object-cover rounded-lg border">
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($progress['photo_after']): ?>
                                                <div class="text-center">
                                                    <p class="text-xs text-gray-600 mb-1">After</p>
                                                    <img src="../uploads/progress_photos/<?php echo $progress['photo_after']; ?>" 
                                                         alt="After" class="w-24 h-24 object-cover rounded-lg border">
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Workout Performance Tracking -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-gray-900">Workout Performance</h3>
                    <button onclick="openAddWorkoutModal()" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition-colors text-sm">
                        <i class="fas fa-plus mr-2"></i>Add Workout
                    </button>
                </div>
                
                <?php if (empty($workout_performance)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-dumbbell text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600">No workout performance data yet.</p>
                        <button onclick="openAddWorkoutModal()" class="mt-4 bg-red-600 text-white px-6 py-2 rounded-lg hover:bg-red-700 transition-colors">
                            Add First Workout Entry
                        </button>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exercise</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reps</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sets</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($workout_performance as $performance): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($performance['exercise_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $performance['weight']; ?> kg
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $performance['reps']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $performance['sets']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo date('M d, Y', strtotime($performance['date_performed'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            <?php echo htmlspecialchars($performance['notes']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Progress Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Weight Progress Chart -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Weight Progress</h3>
                    <div class="h-64">
                        <canvas id="weightChart"></canvas>
                    </div>
                </div>

                <!-- Body Measurements Chart -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-xl font-bold text-gray-900 mb-4">Body Measurements</h3>
                    <div class="h-64">
                        <canvas id="measurementsChart"></canvas>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- Add Progress Modal -->
    <div id="addProgressModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Add Progress Entry</h3>
                <form id="progressForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date</label>
                        <input type="date" name="date_recorded" value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Body Fat (%)</label>
                            <input type="number" step="0.1" name="body_fat" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Muscle Mass (kg)</label>
                            <input type="number" step="0.1" name="muscle_mass" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Chest (cm)</label>
                            <input type="number" step="0.1" name="chest" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Waist (cm)</label>
                            <input type="number" step="0.1" name="waist" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Arms (cm)</label>
                            <input type="number" step="0.1" name="arms" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Legs (cm)</label>
                            <input type="number" step="0.1" name="legs" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddProgressModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                            Save Progress
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Workout Modal -->
    <div id="addWorkoutModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Add Workout Performance</h3>
                <form id="workoutForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Exercise Name</label>
                        <input type="text" name="exercise_name" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Reps</label>
                            <input type="number" name="reps" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Sets</label>
                            <input type="number" name="sets" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date Performed</label>
                        <input type="date" name="date_performed" value="<?php echo date('Y-m-d'); ?>" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddWorkoutModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700">
                            Save Workout
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Progress Modal -->
    <div id="editProgressModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Edit Progress Entry</h3>
                <form id="editProgressForm" class="space-y-4">
                    <input type="hidden" name="progress_id" id="edit_progress_id">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date Recorded</label>
                        <input type="date" name="date_recorded" id="edit_date_recorded" required class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Weight (kg)</label>
                            <input type="number" step="0.1" name="weight" id="edit_weight" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Body Fat (%)</label>
                            <input type="number" step="0.1" name="body_fat" id="edit_body_fat" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Muscle Mass (kg)</label>
                            <input type="number" step="0.1" name="muscle_mass" id="edit_muscle_mass" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Chest (cm)</label>
                            <input type="number" step="0.1" name="chest" id="edit_chest" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Waist (cm)</label>
                            <input type="number" step="0.1" name="waist" id="edit_waist" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Arms (cm)</label>
                            <input type="number" step="0.1" name="arms" id="edit_arms" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Legs (cm)</label>
                            <input type="number" step="0.1" name="legs" id="edit_legs" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Notes</label>
                        <textarea name="notes" id="edit_notes" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2"></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditProgressModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            Update Progress
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle functionality
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const icon = this.querySelector('i');
            const navLinks = sidebar.querySelectorAll('nav a span');
            const sidebarTexts = sidebar.querySelectorAll('.sidebar-bottom-text');
            const sidebarLogoText = sidebar.querySelector('.sidebar-logo-text');
            
            if (sidebar.classList.contains('w-64')) {
                sidebar.classList.remove('w-64');
                sidebar.classList.add('w-16');
                mainContent.classList.remove('ml-64');
                mainContent.classList.add('ml-16');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
                this.title = 'Expand sidebar';
                
                // Hide text elements when collapsed but keep links functional
                navLinks.forEach(span => span.classList.add('hidden'));
                sidebarTexts.forEach(text => text.classList.add('hidden'));
                sidebarLogoText.classList.add('hidden');
                
                // Ensure all navigation links remain visible and functional
                const allNavLinks = sidebar.querySelectorAll('nav a');
                allNavLinks.forEach(link => {
                    link.style.display = 'flex';
                    link.style.alignItems = 'center';
                    link.style.justifyContent = 'center';
                    link.style.minHeight = '2.5rem';
                    link.style.visibility = 'visible';
                    link.style.opacity = '1';
                });
            } else {
                sidebar.classList.remove('w-16');
                sidebar.classList.add('w-64');
                mainContent.classList.remove('ml-16');
                mainContent.classList.add('ml-64');
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
                this.title = 'Collapse sidebar';
                
                // Show text elements when expanded
                navLinks.forEach(span => span.classList.remove('hidden'));
                sidebarTexts.forEach(text => text.classList.remove('hidden'));
                sidebarLogoText.classList.remove('hidden');
                
                // Reset navigation link styles
                const allNavLinks = sidebar.querySelectorAll('nav a');
                allNavLinks.forEach(link => {
                    link.style.display = '';
                    link.style.alignItems = '';
                    link.style.justifyContent = '';
                    link.style.minHeight = '';
                    link.style.visibility = '';
                    link.style.opacity = '';
                });
            }
        });

        // Profile dropdown functionality
        document.getElementById('profileDropdown').addEventListener('click', function() {
            const menu = document.getElementById('profileMenu');
            const arrow = document.getElementById('dropdownArrow');
            
            menu.classList.toggle('opacity-0');
            menu.classList.toggle('invisible');
            menu.classList.toggle('scale-95');
            arrow.classList.toggle('rotate-180');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('profileDropdown');
            const menu = document.getElementById('profileMenu');
            
            if (!dropdown.contains(event.target)) {
                menu.classList.add('opacity-0', 'invisible', 'scale-95');
                document.getElementById('dropdownArrow').classList.remove('rotate-180');
            }
        });

        // Modal functions
        function openAddProgressModal() {
            document.getElementById('addProgressModal').classList.remove('hidden');
        }

        function closeAddProgressModal() {
            document.getElementById('addProgressModal').classList.add('hidden');
        }

        // Progress Charts
        const progressData = <?php echo json_encode($progress_data); ?>;
        
        if (progressData.length > 0) {
            // Weight Chart
            const weightCtx = document.getElementById('weightChart').getContext('2d');
            const weightLabels = progressData.map(item => new Date(item.date_recorded).toLocaleDateString()).reverse();
            const weightValues = progressData.map(item => item.weight).reverse();
            
            new Chart(weightCtx, {
                type: 'line',
                data: {
                    labels: weightLabels,
                    datasets: [{
                        label: 'Weight (kg)',
                        data: weightValues,
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false
                        }
                    }
                }
            });

            // Measurements Chart
            const measurementsCtx = document.getElementById('measurementsChart').getContext('2d');
            const measurementLabels = progressData.map(item => new Date(item.date_recorded).toLocaleDateString()).reverse();
            
            new Chart(measurementsCtx, {
                type: 'line',
                data: {
                    labels: measurementLabels,
                    datasets: [
                        {
                            label: 'Chest (cm)',
                            data: progressData.map(item => item.chest).reverse(),
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.1
                        },
                        {
                            label: 'Waist (cm)',
                            data: progressData.map(item => item.waist).reverse(),
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.1
                        },
                        {
                            label: 'Arms (cm)',
                            data: progressData.map(item => item.arms).reverse(),
                            borderColor: 'rgb(245, 158, 11)',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: false
                        }
                    }
                }
            });
        }

        // Form submission
        document.getElementById('progressForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            
            // Send data to backend
            fetch('save_progress.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeAddProgressModal();
                    // Reload page to show new data
                    window.location.reload();
                } else {
                    if (data.message === 'Not logged in') {
                        alert('Please log in to add progress entries. Redirecting to login page...');
                        window.location.href = 'member_login.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving progress. Please check your login status and try again.');
            });
        });

        // Edit and delete functions
        function editProgress(id) {
            // Fetch the progress entry data
            fetch('../get_progress_entry.php?progress_id=' + id)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Populate the edit form
                        document.getElementById('edit_progress_id').value = data.data.id;
                        document.getElementById('edit_date_recorded').value = data.data.date_recorded;
                        document.getElementById('edit_weight').value = data.data.weight || '';
                        document.getElementById('edit_body_fat').value = data.data.body_fat || '';
                        document.getElementById('edit_muscle_mass').value = data.data.muscle_mass || '';
                        document.getElementById('edit_chest').value = data.data.chest || '';
                        document.getElementById('edit_waist').value = data.data.waist || '';
                        document.getElementById('edit_arms').value = data.data.arms || '';
                        document.getElementById('edit_legs').value = data.data.legs || '';
                        document.getElementById('edit_notes').value = data.data.notes || '';
                        
                        // Show the edit modal
                        document.getElementById('editProgressModal').classList.remove('hidden');
                    } else {
                        if (data.message === 'Not logged in') {
                            alert('Please log in to edit progress entries. Redirecting to login page...');
                            window.location.href = 'member_login.php';
                        } else {
                            alert('Error: ' + data.message);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading progress entry. Please check your login status and try again.');
                });
        }

        function deleteProgress(id) {
            if (confirm('Are you sure you want to delete this progress entry? This action cannot be undone.')) {
                const formData = new FormData();
                formData.append('progress_id', id);
                
                fetch('../delete_progress.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.reload(); // Reload page to show updated data
                    } else {
                        if (data.message === 'Not logged in') {
                            alert('Please log in to delete progress entries. Redirecting to login page...');
                            window.location.href = 'member_login.php';
                        } else {
                            alert('Error: ' + data.message);
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error deleting progress entry. Please check your login status and try again.');
                });
            }
        }

        // Edit modal functions
        function closeEditProgressModal() {
            document.getElementById('editProgressModal').classList.add('hidden');
        }

        // Workout modal functions
        function openAddWorkoutModal() {
            document.getElementById('addWorkoutModal').classList.remove('hidden');
        }

        function closeAddWorkoutModal() {
            document.getElementById('addWorkoutModal').classList.add('hidden');
        }

        // Workout form submission
        document.getElementById('workoutForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            
            // Send data to backend
            fetch('save_workout_performance.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeAddWorkoutModal();
                    // Reload page to show new data
                    window.location.reload();
                } else {
                    if (data.message === 'Not logged in') {
                        alert('Please log in to add workout entries. Redirecting to login page...');
                        window.location.href = 'member_login.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving workout. Please check your login status and try again.');
            });
        });

        // Edit progress form submission
        document.getElementById('editProgressForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            
            // Send data to backend
            fetch('../update_progress.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    closeEditProgressModal();
                    // Reload page to show updated data
                    window.location.reload();
                } else {
                    if (data.message === 'Not logged in') {
                        alert('Please log in to update progress entries. Redirecting to login page...');
                        window.location.href = 'member_login.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating progress. Please check your login status and try again.');
            });
        });

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
                            { id: 1, title: 'Progress Recorded', message: 'Your progress has been recorded successfully.', read: false, type: 'info' },
                            { id: 2, title: 'Goal Achievement', message: 'Congratulations! You\'ve reached a new milestone.', read: false, type: 'goal' },
                            { id: 3, title: 'Workout Complete', message: 'Great job! Your workout has been logged.', read: false, type: 'info' }
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