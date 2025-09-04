<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';
require_once 'trainer_recommendation_helper.php';

// Check if trainer_activity_status view exists
$view_exists = false;
$result = $conn->query("SHOW TABLES LIKE 'trainer_activity_status'");
if ($result && $result->num_rows > 0) {
    $view_exists = true;
}

// Get user information from database
$user_id = $_SESSION['user_id'];
$sql = "SELECT u.*, mp.name as membership_type 
        FROM users u 
        LEFT JOIN membership_plans mp ON u.membership_plan_id = mp.id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Update profile picture variable for display
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = $user['username'] ?? $user['email'] ?? 'User';
$page_title = 'Trainers';

// Check if feedback table exists and has trainer_id column
$result = $conn->query("SHOW TABLES LIKE 'feedback'");
$has_feedback_table = false;
if ($result && $result->num_rows > 0) {
    $colResult = $conn->query("SHOW COLUMNS FROM feedback LIKE 'trainer_id'");
    $has_feedback_table = ($colResult && $colResult->num_rows > 0);
}

// Get trainer recommendations
$recommendation = new TrainerRecommendation($conn, $user_id);
$recommended_trainers = $recommendation->getRecommendedTrainers();

// Check if coming from recommendations
$from_recommendations = isset($_GET['from_recommendations']) && $_GET['from_recommendations'] == '1';
$recommended_trainer_type = null;

if ($from_recommendations && isset($_SESSION['recommended_trainer_type'])) {
    $recommended_trainer_type = $_SESSION['recommended_trainer_type'];
    // Clear the session data after using it
    unset($_SESSION['recommended_trainer_type']);
}

// Check if trainer_specialties table exists
$result = $conn->query("SHOW TABLES LIKE 'trainer_specialties'");
$has_specialties_table = ($result && $result->num_rows > 0);

// Check if trainer_schedules table exists
$result = $conn->query("SHOW TABLES LIKE 'trainer_schedules'");
$has_schedules_table = ($result && $result->num_rows > 0);

// Check if status column exists in trainers table
$result = $conn->query("SHOW COLUMNS FROM trainers LIKE 'status'");
$has_status_column = ($result && $result->num_rows > 0);

// Fetch all trainers with their class information for the complete list
$trainers_sql = "SELECT t.*, " .
                ($view_exists ? "tas.activity_status," : 
                "CASE 
                    WHEN t.status = 'inactive' THEN 'inactive'
                    WHEN t.status = 'on_leave' THEN 'on_leave'
                    WHEN t.next_session_start IS NOT NULL 
                        AND t.next_session_start <= DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 'upcoming_session'
                    WHEN t.last_session_end IS NOT NULL 
                        AND t.last_session_end >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'just_finished'
                    WHEN t.last_login IS NOT NULL 
                        AND t.last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 'online'
                    ELSE 'offline'
                END as activity_status,") .
                ($has_specialties_table ? 
                "GROUP_CONCAT(DISTINCT ts.specialty) as specialties" : 
                "t.specialization as specialties") .
                ($has_schedules_table ? 
                ", GROUP_CONCAT(DISTINCT CONCAT(tsch.day_of_week, ': ', 
                    TIME_FORMAT(tsch.start_time, '%h:%i %p'), ' - ', 
                    TIME_FORMAT(tsch.end_time, '%h:%i %p'))
                ORDER BY FIELD(tsch.day_of_week, 
                    'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
                    'Friday', 'Saturday', 'Sunday')
                SEPARATOR '\n') as schedule_details" : 
                ", NULL as schedule_details") .
                ($has_feedback_table ? 
                ", AVG(CASE WHEN f.rating IS NOT NULL THEN f.rating ELSE NULL END) as avg_rating,
                 COUNT(DISTINCT f.id) as feedback_count" : 
                ", NULL as avg_rating,
                 0 as feedback_count") . "
         FROM trainers t" .
         ($view_exists ? " LEFT JOIN trainer_activity_status tas ON t.id = tas.id" : "") .
         ($has_specialties_table ? 
         " LEFT JOIN trainer_specialties ts ON t.id = ts.trainer_id" : "") .
         ($has_schedules_table ? 
         " LEFT JOIN trainer_schedules tsch ON t.id = tsch.trainer_id" : "") .
         ($has_feedback_table ? 
         " LEFT JOIN feedback f ON t.id = f.trainer_id" : "") .
         " GROUP BY t.id
         ORDER BY t.name";

$all_trainers = $conn->query($trainers_sql);

if (!$all_trainers) {
    die("Error fetching trainers: " . $conn->error);
}

// Get user's fitness data for displaying recommendation basis
$fitness_sql = "SELECT * FROM fitness_data WHERE user_id = ?";
$fitness_stmt = $conn->prepare($fitness_sql);
$fitness_stmt->bind_param("i", $user_id);
$fitness_stmt->execute();
$fitness_data = $fitness_stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainers - Almo Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dark-mode.css">
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
        .match-score-ring {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
            position: relative;
        }
        
        .match-score-ring::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            border: 4px solid #f3f4f6;
        }
        
        .match-score-ring::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            border: 4px solid;
            border-color: currentColor;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);
            transform: rotate(calc(var(--percentage) * 3.6deg));
            transform-origin: center;
            transition: transform 1s ease-out;
        }

        .status-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            color: white;
        }

        .status-badge.active {
            background: rgba(16, 185, 129, 0.9);
        }

        .status-badge.inactive {
            background: rgba(239, 68, 68, 0.9);
        }

        .status-badge.on_leave {
            background: rgba(245, 158, 11, 0.9);
        }

        .status-badge i {
            font-size: 0.75rem;
        }

        .status-badge i.pulse {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }

        .pulse {
            position: relative;
        }

        .pulse::before {
            content: '';
            position: absolute;
            left: -0.25rem;
            top: 50%;
            transform: translateY(-50%);
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background-color: currentColor;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: translateY(-50%) scale(0.95);
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.7);
            }
            70% {
                transform: translateY(-50%) scale(1);
                box-shadow: 0 0 0 6px rgba(255, 255, 255, 0);
            }
            100% {
                transform: translateY(-50%) scale(0.95);
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
            }
        }

        .trainer-card {
            background: white;
            border-radius: 0.75rem;
            overflow: hidden;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .trainer-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px -10px rgba(0, 0, 0, 0.1);
        }

        .trainer-image-container {
            position: relative;
            width: 100%;
            padding-top: 75%; /* 4:3 Aspect Ratio */
            background: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.7) 100%);
        }

        .trainer-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
        }

        .trainer-image-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 1.5rem 1rem;
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0) 100%);
            color: white;
        }

        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            z-index: 10;
        }

        .status-badge.online {
            background: rgba(16, 185, 129, 0.9);
        }

        .status-badge.offline {
            background: rgba(107, 114, 128, 0.9);
        }

        .status-badge.upcoming_session {
            background: rgba(59, 130, 246, 0.9);
        }

        .status-badge.just_finished {
            background: rgba(139, 92, 246, 0.9);
        }

        .status-badge.on_leave {
            background: rgba(245, 158, 11, 0.9);
        }

        .trainer-info {
            padding: 1.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .specialty-tag {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #FEE2E2;
            color: #991B1B;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            margin: 0.25rem;
        }

        .rating-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            background: #FEF3C7;
            color: #92400E;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: auto;
            padding-top: 1rem;
        }

        .action-button {
            flex: 1;
            padding: 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-align: center;
            transition: all 0.2s ease-in-out;
        }

        .action-button.primary {
            background: #DC2626;
            color: white;
        }

        .action-button.primary:hover {
            background: #B91C1C;
        }

        .action-button.secondary {
            background: #F3F4F6;
            color: #374151;
        }

        .action-button.secondary:hover {
            background: #E5E7EB;
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
                    <?php echo $page_title ?? 'Trainers'; ?>
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
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($display_name); ?></h3>
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
    <main class="ml-64 mt-16 p-6" id="mainContent">
        <div class="max-w-7xl mx-auto space-y-8">
            <!-- Recommended Trainers Section -->
            <?php if (!empty($recommended_trainers)): ?>
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="mb-6">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-2">Recommended Trainers</h2>
                    <?php if ($fitness_data): ?>
                    <p class="text-gray-600">Based on your fitness level (<?php echo ucfirst($fitness_data['fitness_level']); ?>), 
                        goals (<?php echo str_replace('_', ' ', ucfirst($fitness_data['goal'])); ?>), 
                        and activity level (<?php echo ucfirst($fitness_data['activity_level']); ?>)</p>
                    <?php else: ?>
                    <p class="text-gray-600">Based on trainer experience<?php echo $has_feedback_table ? ' and member ratings' : ''; ?></p>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <?php foreach(array_slice($recommended_trainers, 0, 3) as $trainer): ?>
                    <div class="bg-gray-50 rounded-lg p-6 trainer-card relative overflow-hidden" data-trainer-id="<?php echo $trainer['id']; ?>">
                        <div class="absolute top-4 right-4">
                            <div class="match-score-ring text-green-500" style="--percentage: <?php echo $trainer['match_score']; ?>">
                                <?php echo $trainer['match_score']; ?>%
                            </div>
                        </div>
                        
                        <div class="flex items-start mb-4">
                            <img src="<?php 
                                if (!empty($trainer['image_url'])) {
                                    echo htmlspecialchars('../uploads/trainer_images/' . basename($trainer['image_url']));
                                } else {
                                    echo '../image/almo.jpg';
                                }
                            ?>" 
                                 alt="<?php echo htmlspecialchars($trainer['name']); ?>" 
                                 class="w-20 h-20 rounded-full object-cover border-2 border-red-500"
                                 onerror="this.src='../image/almo.jpg';">
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($trainer['name']); ?></h3>
                                <p class="text-red-600 font-medium"><?php echo htmlspecialchars($trainer['specialization']); ?></p>
                                <div class="flex items-center mt-1">
                                    <?php if ($has_feedback_table && isset($trainer['avg_rating'])): ?>
                                    <div class="flex items-center">
                                        <span class="text-yellow-400 mr-1"><i class="fas fa-star"></i></span>
                                        <span class="text-gray-600"><?php echo number_format($trainer['avg_rating'], 1); ?></span>
                                    </div>
                                    <span class="mx-2 text-gray-300">|</span>
                                    <?php endif; ?>
                                    <span class="text-gray-600"><?php echo $trainer['experience_years']; ?> years exp.</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="space-y-2">
                            <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($trainer['bio'] ?? 'No bio available.'); ?></p>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <?php if (!empty($trainer['certification'])): ?>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($trainer['certification']); ?>
                                </span>
                                <?php endif; ?>
                                <?php if (($trainer['class_count'] ?? 0) > 0): ?>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-purple-100 text-purple-800">
                                    <?php echo $trainer['class_count']; ?> Classes
                                </span>
                                <?php endif; ?>
                                <?php if ($has_feedback_table && ($trainer['feedback_count'] ?? 0) > 0): ?>
                                <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">
                                    <?php echo $trainer['feedback_count']; ?> Reviews
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mt-4 flex justify-end">
                            <button onclick="viewProfile(<?php echo $trainer['id']; ?>)" class="text-red-600 hover:text-red-700 font-medium text-sm">View Profile →</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- All Trainers Section -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <!-- Header Section with Better Alignment -->
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 space-y-4 lg:space-y-0">
                    <h2 class="text-2xl font-semibold text-gray-800">Our Trainers</h2>
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4 w-full lg:w-auto">
                        <div class="relative">
                            <input type="text" 
                                   id="searchTrainer" 
                                   placeholder="Search by name, specialization, or experience..." 
                                   class="rounded-lg border-gray-300 text-sm focus:ring-red-500 focus:border-red-500 pl-10 pr-4 py-2 min-w-[400px]">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <button id="clearSearch" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400 hover:text-gray-600 hidden">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Trainers Grid with Improved Alignment -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if ($all_trainers && $all_trainers->num_rows > 0): ?>
                        <?php while($trainer = $all_trainers->fetch_assoc()): ?>
                            <div class="trainer-card">
                                <div class="trainer-image-container">
                                    <?php
                                    $trainer_image = !empty($trainer['image_url']) 
                                        ? "../uploads/trainer_images/" . basename($trainer['image_url'])
                                        : "../image/almo.jpg";
                                    
                                    // Get trainer status and icon
                                    $status = $trainer['status'] ?? 'inactive';
                                    $status_text = match($status) {
                                        'active' => '<i class="fas fa-circle pulse"></i> Active',
                                        'on_leave' => '<i class="fas fa-umbrella-beach"></i> On Leave',
                                        'inactive' => '<i class="fas fa-circle"></i> Offline',
                                        default => '<i class="fas fa-circle"></i> Offline'
                                    };
                                    ?>
                                    
                                    <img src="<?php echo htmlspecialchars($trainer_image); ?>" 
                                         alt="<?php echo htmlspecialchars($trainer['name']); ?>"
                                         class="trainer-image"
                                         onerror="this.src='../image/almo.jpg';">
                                    
                                    <!-- Status Badge -->
                                    <div class="status-badge <?php echo htmlspecialchars($status); ?>">
                                        <?php echo $status_text; ?>
                                    </div>

                                    <!-- Name Overlay -->
                                    <div class="trainer-image-overlay">
                                        <h3 class="text-xl font-semibold text-white mb-1">
                                            <?php echo htmlspecialchars($trainer['name']); ?>
                                        </h3>
                                        <p class="text-gray-200 text-sm">
                                            <?php echo htmlspecialchars($trainer['specialization']); ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="trainer-info">
                                    <!-- Specialties -->
                                    <?php if (!empty($trainer['specialties'])): ?>
                                    <div class="mb-4">
                                        <h4 class="text-sm font-semibold text-gray-600 mb-2">Specialties</h4>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach(explode(',', $trainer['specialties']) as $specialty): ?>
                                                <span class="specialty-tag">
                                                    <?php echo htmlspecialchars(trim($specialty)); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Experience and Rating -->
                                    <div class="flex items-center justify-between mb-4">
                                        <span class="text-sm text-gray-600">
                                            <i class="fas fa-briefcase text-gray-400 mr-1"></i>
                                            <?php echo $trainer['experience_years']; ?> years experience
                                        </span>
                                        <?php if ($has_feedback_table && isset($trainer['avg_rating']) && $trainer['avg_rating']): ?>
                                        <div class="rating-badge">
                                            <i class="fas fa-star text-yellow-500"></i>
                                            <span><?php echo number_format($trainer['avg_rating'], 1); ?></span>
                                            <span class="text-gray-500">(<?php echo $trainer['feedback_count']; ?>)</span>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Bio -->
                                    <p class="text-gray-600 text-sm mb-4 line-clamp-3">
                                        <?php echo htmlspecialchars($trainer['bio'] ?? ''); ?>
                                    </p>

                                    <!-- Schedule Preview -->
                                    <?php if (!empty($trainer['schedule_details'])): ?>
                                    <div class="mb-4">
                                        <h4 class="text-sm font-semibold text-gray-600 mb-2">Available Schedule</h4>
                                        <div class="text-sm text-gray-600 space-y-1">
                                            <?php 
                                            $schedules = explode("\n", $trainer['schedule_details']);
                                            array_splice($schedules, 2);  // Show only first 2 schedules
                                            foreach($schedules as $schedule): 
                                            ?>
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-calendar-alt text-gray-400"></i>
                                                    <?php echo htmlspecialchars($schedule); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (count(explode("\n", $trainer['schedule_details'])) > 2): ?>
                                                <div class="text-sm text-red-600 cursor-pointer hover:text-red-700">
                                                    View all schedules...
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Action Buttons -->
                                    <div class="action-buttons">
                                        <button class="action-button primary" onclick="viewTrainerProfile(<?php echo $trainer['id']; ?>)">
                                            View Profile
                                        </button>
                                        <button class="action-button secondary" onclick="scheduleSession(<?php echo $trainer['id']; ?>)">
                                            Schedule
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-span-full text-center py-12">
                            <div class="max-w-md mx-auto">
                                <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500 text-lg">No trainers available at the moment.</p>
                                <p class="text-gray-400 mt-2">Please check back later or contact the front desk.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Trainer Profile Modal -->
    <div id="trainerProfileModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden overflow-y-auto">
        <div class="min-h-screen px-4 text-center">
            <!-- Modal overlay -->
            <div class="fixed inset-0 transition-opacity" aria-hidden="true">
                <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
            </div>

            <!-- This element is to trick the browser into centering the modal contents. -->
            <span class="inline-block h-screen align-middle" aria-hidden="true">&#8203;</span>

            <!-- Modal panel -->
            <div class="inline-block w-full max-w-4xl p-6 my-8 text-left align-middle transition-all transform bg-white shadow-xl rounded-2xl">
                <div class="absolute top-0 right-0 pt-4 pr-4">
                    <button type="button" class="text-gray-400 hover:text-gray-500 focus:outline-none" onclick="closeProfileModal()">
                        <span class="sr-only">Close</span>
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="modal-content space-y-8">
                    <!-- Header Section -->
                    <div class="flex items-start space-x-6">
                        <img id="modalTrainerImage" src="" alt="" class="w-32 h-32 rounded-full object-cover border-4 border-red-500">
                        <div class="flex-1">
                            <h2 id="modalTrainerName" class="text-2xl font-bold text-gray-900"></h2>
                            <p id="modalTrainerSpecialization" class="text-lg text-red-600 font-medium mt-1"></p>
                            <div id="modalTrainerRating" class="mt-3">
                                <!-- Rating will be inserted here -->
                            </div>
                        </div>
                        <div id="modalTrainerRate" class="text-right">
                            <!-- Rate will be inserted here -->
                        </div>
                    </div>

                    <!-- Main Content Grid -->
                    <div class="space-y-6">
                        <!-- Tabs Navigation -->
                        <div class="border-b border-gray-200">
                            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                                <button id="tab-info" class="tab-btn border-red-500 text-red-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm" aria-current="page">
                                    Information
                                </button>
                                <button id="tab-reviews" class="tab-btn border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                                    Reviews
                                </button>
                            </nav>
                        </div>

                        <!-- Tab Content -->
                        <div id="tab-content-info" class="tab-content grid grid-cols-3 gap-6">
                            <!-- Left Column -->
                            <div class="col-span-2 space-y-6">
                                <!-- Bio Section -->
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-3">About</h3>
                                    <p id="modalTrainerBio" class="text-gray-600 leading-relaxed"></p>
                                </div>

                                <!-- Schedule Section -->
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Available Times</h3>
                                    <div id="modalTrainerSchedule" class="grid grid-cols-1 gap-2">
                                        <!-- Schedule will be inserted here -->
                                    </div>
                                </div>

                                <!-- Rating Section -->
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Rate this Trainer</h3>
                                    <div class="space-y-4">
                                        <div id="userRating" class="flex items-center space-x-2">
                                            <div class="stars flex space-x-1">
                                                <?php for($i = 1; $i <= 5; $i++): ?>
                                                <button class="star-btn text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="<?php echo $i; ?>">
                                                    <i class="fas fa-star"></i>
                                                </button>
                                                <?php endfor; ?>
                                            </div>
                                            <span id="ratingText" class="text-sm text-gray-500 ml-2">Click to rate</span>
                                        </div>
                                        <textarea id="feedbackComment" placeholder="Add a comment (optional)" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 text-sm" rows="3"></textarea>
                                        <button id="submitRating" class="w-full bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 transition-colors">
                                            Submit Rating
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div class="space-y-6">
                                <!-- Experience & Certifications -->
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Experience</h3>
                                    <div id="modalTrainerExperience" class="space-y-3">
                                        <!-- Experience details will be inserted here -->
                                    </div>
                                </div>

                                <!-- Contact Information -->
                                <div class="bg-gray-50 p-6 rounded-lg">
                                    <h3 class="text-lg font-semibold text-gray-900 mb-3">Contact Information</h3>
                                    <div id="modalTrainerContact" class="space-y-3">
                                        <!-- Contact info will be inserted here -->
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reviews Tab Content -->
                        <div id="tab-content-reviews" class="tab-content hidden">
                            <div class="bg-gray-50 p-6 rounded-lg">
                                <h3 class="text-lg font-semibold text-gray-900 mb-4">Member Reviews</h3>
                                <div id="modalTrainerReviews" class="space-y-4 max-h-[600px] overflow-y-auto pr-4 custom-scrollbar">
                                    <!-- Reviews will be inserted here -->
                                    <div class="text-center text-gray-500 py-4">Loading reviews...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex justify-end space-x-4 mt-8">
                        <button onclick="closeProfileModal()" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 font-medium transition-colors duration-200">
                            Close
                        </button>
                        <button id="modalScheduleButton" class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium transition-colors duration-200">
                            Schedule Session
                        </button>
                    </div>

                    <style>
                        .custom-scrollbar::-webkit-scrollbar {
                            width: 6px;
                        }
                        .custom-scrollbar::-webkit-scrollbar-track {
                            background: #f3f4f6;
                            border-radius: 3px;
                        }
                        .custom-scrollbar::-webkit-scrollbar-thumb {
                            background: #d1d5db;
                            border-radius: 3px;
                        }
                        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                            background: #9ca3af;
                        }
                    </style>

                    <script>
                        // Tab Functionality
                        document.querySelectorAll('.tab-btn').forEach(button => {
                            button.addEventListener('click', () => {
                                // Update button states
                                document.querySelectorAll('.tab-btn').forEach(btn => {
                                    btn.classList.remove('border-red-500', 'text-red-600');
                                    btn.classList.add('border-transparent', 'text-gray-500');
                                });
                                button.classList.remove('border-transparent', 'text-gray-500');
                                button.classList.add('border-red-500', 'text-red-600');

                                // Show corresponding content
                                document.querySelectorAll('.tab-content').forEach(content => {
                                    content.classList.add('hidden');
                                });
                                const contentId = button.id.replace('tab-', 'tab-content-');
                                document.getElementById(contentId).classList.remove('hidden');
                            });
                        });
                    </script>
                </div>
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
                profileMenu.classList.add('opacity-0');
                profileMenu.classList.add('invisible');
                profileMenu.classList.add('scale-95');
                dropdownArrow.classList.remove('rotate-180');
            }
        });

        // Sidebar Toggle with Content Centering
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const toggleIcon = sidebarToggle.querySelector('i');
        const mainContent = document.getElementById('mainContent');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            toggleIcon.classList.toggle('rotate-180');
            
            // Toggle visibility of text elements
            document.querySelectorAll('.sidebar-logo-text, nav span, .sidebar-bottom-text').forEach(el => {
                el.classList.toggle('hidden');
            });

            // Adjust main content margin for centering
            if (sidebar.classList.contains('w-20')) {
                mainContent.style.marginLeft = '5rem'; // 80px for w-20
                mainContent.style.transition = 'margin-left 0.3s ease';
            } else {
                mainContent.style.marginLeft = '16rem'; // 256px for w-64
                mainContent.style.transition = 'margin-left 0.3s ease';
            }
        });

        // Enhanced Trainer filtering
        const searchInput = document.getElementById('searchTrainer');
        const clearSearchBtn = document.getElementById('clearSearch');
        const trainerCards = document.querySelectorAll('.trainer-card');
        let searchTimeout;

        function getSearchableContent(card) {
            return [
                card.querySelector('h3')?.textContent || '', // name
                card.querySelector('.text-gray-600.leading-relaxed')?.textContent || '', // bio
                card.querySelector('.text-red-600.font-medium')?.textContent || '', // specialization
                Array.from(card.querySelectorAll('.bg-red-100')).map(el => el.textContent).join(' '), // specialties
                card.querySelector('.fa-star')?.parentElement?.textContent || '', // experience
                card.querySelector('.text-gray-600')?.textContent || '' // additional info
            ].join(' ').toLowerCase();
        }

        function filterTrainers() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            clearSearchBtn.style.display = searchTerm ? 'flex' : 'none';

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Add slight delay to prevent excessive filtering on fast typing
            searchTimeout = setTimeout(() => {
                let visibleCount = 0;

                trainerCards.forEach(card => {
                    const searchableContent = card._searchableContent || getSearchableContent(card);
                    
                    // Split search term into words and check if all words are found
                    const searchWords = searchTerm.split(/\s+/).filter(word => word.length > 0);
                    const matchesAllWords = searchWords.length === 0 || 
                        searchWords.every(word => searchableContent.includes(word));

                    if (matchesAllWords) {
                        card.style.display = 'block';
                        card.style.opacity = '1';
                        visibleCount++;
                    } else {
                        card.style.opacity = '0';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 200);
                    }
                });

                // Update or show/hide no results message
                updateNoResultsMessage(visibleCount === 0 && searchTerm);
            }, 200);
        }

        function updateNoResultsMessage(show) {
            let noResultsMessage = document.querySelector('.no-results-message');
            
            if (show) {
                if (!noResultsMessage) {
                    const message = document.createElement('div');
                    message.className = 'no-results-message col-span-full text-center py-12 fade-in';
                    message.innerHTML = `
                        <div class="max-w-md mx-auto">
                            <i class="fas fa-search text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-lg">No trainers found matching "${searchInput.value}"</p>
                            <p class="text-gray-400 mt-2">Try different search terms or clear the search</p>
                        </div>
                    `;
                    document.querySelector('.grid').appendChild(message);
                }
            } else if (noResultsMessage) {
                noResultsMessage.remove();
            }
        }

        function clearSearch() {
            searchInput.value = '';
            filterTrainers();
            searchInput.focus();
        }

        // Event listeners
        searchInput.addEventListener('input', filterTrainers);
        clearSearchBtn.addEventListener('click', clearSearch);

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
            // Escape to clear search when focused
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                clearSearch();
            }
        });

        // Add styles for animations
        const style = document.createElement('style');
        style.textContent = `
            .trainer-card {
                transition: opacity 0.2s ease-in-out;
            }
            .fade-in {
                animation: fadeIn 0.3s ease-in-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);

        // Initialize search functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initial setup
            clearSearchBtn.style.display = 'none';
            
            // Pre-calculate searchable content for better performance
            trainerCards.forEach(card => {
                card._searchableContent = getSearchableContent(card);
            });
            
            // Load dark mode preference
            const savedDarkMode = localStorage.getItem('darkMode');
            if (savedDarkMode === 'true') {
                document.body.classList.add('dark-mode');
                console.log('Dark mode loaded from localStorage');
            }
        });

        // Schedule session function
        function scheduleSession(trainerId) {
            // Get trainer details
            fetch(`get_trainer_schedule.php?trainer_id=${trainerId}`)
                .then(response => response.json())
                .then(data => {
                    // Create modal with schedule selection
                    const modal = document.createElement('div');
                    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
                    modal.innerHTML = `
                        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                            <div class="mt-3 text-center">
                                <h3 class="text-lg leading-6 font-medium text-gray-900">Schedule a Session</h3>
                                <div class="mt-2 px-7 py-3">
                                    <form id="scheduleForm">
                                        <input type="hidden" name="trainer_id" value="${trainerId}">
                                        <div class="mb-4">
                                            <label class="block text-gray-700 text-sm font-bold mb-2">Select Day</label>
                                            <select name="day" class="shadow border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                                ${data.schedule.map(s => `
                                                    <option value="${s.day_of_week}">${s.day_of_week}: ${s.start_time} - ${s.end_time}</option>
                                                `).join('')}
                                            </select>
                                        </div>
                                        <div class="flex items-center justify-between mt-4">
                                            <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded hover:bg-red-700">Schedule</button>
                                            <button type="button" onclick="closeModal()" class="bg-gray-200 text-gray-700 py-2 px-4 rounded hover:bg-gray-300">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(modal);
                    
                    // Handle form submission
                    document.getElementById('scheduleForm').addEventListener('submit', function(e) {
                        e.preventDefault();
                        const formData = new FormData(this);
                        fetch('process_schedule.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(result => {
                            alert(result.message);
                            closeModal();
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('An error occurred while scheduling the session.');
                        });
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while fetching trainer schedule.');
                });
        }

        function closeModal() {
            const modal = document.querySelector('.fixed');
            if (modal) {
                modal.remove();
            }
        }

        async function loadTrainerReviews(trainerId) {
            try {
                const response = await fetch(`get_trainer.php?id=${trainerId}&include_reviews=true`);
                const data = await response.json();
                
                const reviewsContainer = document.getElementById('modalTrainerReviews');
                
                if (!data.reviews || data.reviews.length === 0) {
                    reviewsContainer.innerHTML = `
                        <div class="text-center text-gray-500 py-4">
                            <p>No reviews yet</p>
                        </div>
                    `;
                    return;
                }

                const reviewsHTML = data.reviews.map(review => {
                    const rating = parseInt(review.rating);
                    const starsHTML = Array(5).fill('').map((_, index) => 
                        `<i class="fas fa-star ${index < rating ? 'text-yellow-400' : 'text-gray-300'}"></i>`
                    ).join('');

                    const reviewDate = new Date(review.created_at).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });

                    return `
                        <div class="border-b border-gray-200 last:border-0 pb-4 last:pb-0">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="flex text-sm">
                                        ${starsHTML}
                                    </div>
                                    <span class="font-medium text-gray-900">${review.username || 'Anonymous Member'}</span>
                                </div>
                                <span class="text-sm text-gray-500">${reviewDate}</span>
                            </div>
                            ${review.comment ? `
                                <p class="text-gray-600 text-sm">${review.comment}</p>
                            ` : ''}
                        </div>
                    `;
                }).join('');

                reviewsContainer.innerHTML = reviewsHTML;
            } catch (error) {
                console.error('Error loading reviews:', error);
                document.getElementById('modalTrainerReviews').innerHTML = `
                    <div class="text-center text-red-500 py-4">
                        <p>Error loading reviews. Please try again later.</p>
                    </div>
                `;
            }
        }

        function viewTrainerProfile(trainerId) {
            const trainerCard = document.querySelector(`.trainer-card[data-trainer-id="${trainerId}"]`);
            if (!trainerCard) {
                console.error('Trainer card not found');
                return;
            }
            const modal = document.getElementById('trainerProfileModal');
            
            // Store trainer ID for rating submission
            modal.dataset.trainerId = trainerId;
            
            // Reset rating UI
            document.querySelectorAll('.star-btn').forEach(btn => {
                btn.classList.remove('text-yellow-400');
                btn.classList.add('text-gray-300');
            });
            document.getElementById('ratingText').textContent = 'Click to rate';
            document.getElementById('feedbackComment').value = '';

            // Get trainer data from the card
            const trainerData = {
                name: trainerCard.querySelector('h3')?.textContent || '',
                specialization: trainerCard.querySelector('.text-red-600.font-medium')?.textContent || '',
                bio: trainerCard.querySelector('.text-gray-600.leading-relaxed')?.textContent || 'No bio available',
                image: trainerCard.querySelector('img')?.src || '../image/almo.jpg',
                experience: trainerCard.querySelector('.fa-star')?.closest('.flex')?.textContent?.trim() || '',
                schedule: Array.from(trainerCard.querySelectorAll('.fa-calendar-check, .fa-clock')).map(el => 
                    el.closest('.space-y-2')?.querySelector('.text-gray-500, .text-sm')?.textContent?.trim()
                ).filter(Boolean).join('\n') || 'Schedule not available',
                email: trainerCard.querySelector('.fa-envelope')?.nextElementSibling?.textContent?.trim() || '',
                phone: trainerCard.querySelector('.fa-phone')?.nextElementSibling?.textContent?.trim() || '',
                rating: trainerCard.querySelector('.text-2xl.font-bold.text-yellow-500')?.textContent?.trim() || '',
                reviewCount: trainerCard.querySelector('.text-sm.text-gray-500')?.textContent?.trim() || '',
                hourlyRate: trainerCard.querySelector('.text-red-600.font-semibold')?.textContent?.trim() || ''
            };

            try {
                // Update modal content
                const modalImg = document.getElementById('modalTrainerImage');
                modalImg.src = trainerData.image;
                modalImg.alt = trainerData.name;
                modalImg.onerror = () => { modalImg.src = '../image/almo.jpg'; };

                document.getElementById('modalTrainerName').textContent = trainerData.name || 'Name not available';
                document.getElementById('modalTrainerSpecialization').textContent = trainerData.specialization || 'Specialization not available';
                document.getElementById('modalTrainerBio').textContent = trainerData.bio || 'No bio available';

                // Update rating section with enhanced display
                const ratingSection = document.getElementById('modalTrainerRating');
                if (trainerData.rating && trainerData.reviewCount) {
                    const rating = parseFloat(trainerData.rating);
                    const fullStars = Math.floor(rating);
                    const hasHalfStar = rating % 1 >= 0.5;
                    
                    let starsHTML = '';
                    // Add full stars
                    for (let i = 0; i < fullStars; i++) {
                        starsHTML += '<i class="fas fa-star text-yellow-400"></i>';
                    }
                    // Add half star if needed
                    if (hasHalfStar) {
                        starsHTML += '<i class="fas fa-star-half-alt text-yellow-400"></i>';
                    }
                    // Add empty stars
                    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
                    for (let i = 0; i < emptyStars; i++) {
                        starsHTML += '<i class="far fa-star text-yellow-400"></i>';
                    }

                    ratingSection.innerHTML = `
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <div class="flex items-center text-xl">${starsHTML}</div>
                                <span class="text-2xl font-bold text-gray-900">${rating.toFixed(1)}</span>
                                <span class="text-gray-600">out of 5</span>
                            </div>
                            <p class="text-gray-500 mt-1">${trainerData.reviewCount}</p>
                        </div>
                    `;
                } else {
                    ratingSection.innerHTML = `
                        <div class="flex items-center text-gray-500">
                            <div class="flex items-center text-xl">
                                ${Array(5).fill('<i class="far fa-star text-gray-300"></i>').join('')}
                            </div>
                            <span class="ml-2">No ratings yet</span>
                        </div>
                    `;
                }

                // Update rate section
                const rateSection = document.getElementById('modalTrainerRate');
                if (trainerData.hourlyRate) {
                    rateSection.innerHTML = `
                        <div class="text-xl font-bold text-red-600">${trainerData.hourlyRate}</div>
                        <div class="text-sm text-gray-500">per hour</div>
                    `;
                } else {
                    rateSection.innerHTML = '';
                }

                // Update experience section
                document.getElementById('modalTrainerExperience').innerHTML = trainerData.experience ? `
                    <div class="flex items-center text-gray-600">
                        <i class="fas fa-star text-yellow-400 mr-2"></i>
                        <span>${trainerData.experience}</span>
                    </div>
                ` : '<div class="text-gray-500">Experience information not available</div>';

                // Update schedule section
                const scheduleContainer = document.getElementById('modalTrainerSchedule');
                if (trainerData.schedule && trainerData.schedule !== 'Schedule not available') {
                    scheduleContainer.innerHTML = trainerData.schedule.split('\n')
                        .filter(schedule => schedule && schedule.trim())
                        .map(schedule => `
                            <div class="flex items-center text-gray-600">
                                <i class="fas fa-clock text-blue-400 mr-2"></i>
                                <span>${schedule.trim()}</span>
                            </div>
                        `).join('');
                } else {
                    scheduleContainer.innerHTML = `
                        <div class="flex items-center text-gray-500">
                            <i class="fas fa-clock text-gray-400 mr-2"></i>
                            <span>Schedule not available</span>
                        </div>
                    `;
                }

                // Update contact section
                const contactSection = document.getElementById('modalTrainerContact');
                let contactHTML = '';
                if (trainerData.email) {
                    contactHTML += `
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-envelope text-green-400 mr-2"></i>
                            <span>${trainerData.email}</span>
                        </div>
                    `;
                }
                if (trainerData.phone) {
                    contactHTML += `
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-phone text-purple-400 mr-2"></i>
                            <span>${trainerData.phone}</span>
                        </div>
                    `;
                }
                if (!contactHTML) {
                    contactHTML = '<div class="text-gray-500">No contact information available</div>';
                }
                contactSection.innerHTML = contactHTML;
            } catch (error) {
                console.error('Error updating modal content:', error);
                alert('There was an error displaying the trainer profile. Please try again.');
                return;
            }

            // Load trainer reviews
            loadTrainerReviews(trainerId);

            // Set up schedule button
            document.getElementById('modalScheduleButton').onclick = () => {
                closeProfileModal();
                scheduleSession(trainerId);
            };

            // Show modal
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            const modal = document.getElementById('trainerProfileModal');
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Close modal when clicking outside
        document.getElementById('trainerProfileModal').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) {
                closeProfileModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !document.getElementById('trainerProfileModal').classList.contains('hidden')) {
                closeProfileModal();
            }
        });

        // Rating System
        let currentRating = 0;

        document.querySelectorAll('.star-btn').forEach(btn => {
            // Hover effect
            btn.addEventListener('mouseenter', () => {
                const rating = parseInt(btn.dataset.rating);
                updateStarsDisplay(rating, true);
            });

            btn.addEventListener('mouseleave', () => {
                updateStarsDisplay(currentRating, false);
            });

            // Click event
            btn.addEventListener('click', () => {
                currentRating = parseInt(btn.dataset.rating);
                updateStarsDisplay(currentRating, false);
                document.getElementById('ratingText').textContent = `${currentRating} out of 5 stars`;
            });
        });

        function updateStarsDisplay(rating, isHover) {
            document.querySelectorAll('.star-btn').forEach((btn, index) => {
                if (index < rating) {
                    btn.classList.remove('text-gray-300');
                    btn.classList.add('text-yellow-400');
                } else {
                    btn.classList.remove('text-yellow-400');
                    btn.classList.add('text-gray-300');
                }
            });

            if (isHover && rating > 0) {
                document.getElementById('ratingText').textContent = `${rating} out of 5 stars`;
            }
        }

        // Submit Rating
        document.getElementById('submitRating').addEventListener('click', async () => {
            if (currentRating === 0) {
                alert('Please select a rating before submitting');
                return;
            }

            const modal = document.getElementById('trainerProfileModal');
            const trainerId = modal.dataset.trainerId;
            const comment = document.getElementById('feedbackComment').value;

            try {
                const response = await fetch('submit_feedback.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `trainer_id=${trainerId}&rating=${currentRating}&comment=${encodeURIComponent(comment)}`
                });

                const data = await response.json();

                if (data.success) {
                    // Update the rating display in the modal with enhanced stars
                    const ratingSection = document.getElementById('modalTrainerRating');
                    const rating = parseFloat(data.avg_rating);
                    const fullStars = Math.floor(rating);
                    const hasHalfStar = rating % 1 >= 0.5;
                    
                    let starsHTML = '';
                    // Add full stars
                    for (let i = 0; i < fullStars; i++) {
                        starsHTML += '<i class="fas fa-star text-yellow-400"></i>';
                    }
                    // Add half star if needed
                    if (hasHalfStar) {
                        starsHTML += '<i class="fas fa-star-half-alt text-yellow-400"></i>';
                    }
                    // Add empty stars
                    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);
                    for (let i = 0; i < emptyStars; i++) {
                        starsHTML += '<i class="far fa-star text-yellow-400"></i>';
                    }

                    ratingSection.innerHTML = `
                        <div class="flex flex-col">
                            <div class="flex items-center gap-2">
                                <div class="flex items-center text-xl">${starsHTML}</div>
                                <span class="text-2xl font-bold text-gray-900">${rating.toFixed(1)}</span>
                                <span class="text-gray-600">out of 5</span>
                            </div>
                            <p class="text-gray-500 mt-1">${data.feedback_count} reviews</p>
                        </div>
                    `;

                    // Reset the rating form
                    currentRating = 0;
                    updateStarsDisplay(0, false);
                    document.getElementById('ratingText').textContent = 'Thanks for rating!';
                    document.getElementById('feedbackComment').value = '';

                    // Refresh the reviews section
                    loadTrainerReviews(trainerId);

                    // Show success message
                    alert('Thank you for your feedback!');
                } else {
                    throw new Error(data.error || 'Error submitting rating');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error submitting rating. Please try again.');
            }
        });

        function refreshTrainerStatus() {
            fetch('get_trainer_status.php')
                .then(response => response.json())
                .then(trainers => {
                    trainers.forEach(trainer => {
                        const trainerCard = document.querySelector(`[data-trainer-id="${trainer.id}"]`);
                        if (trainerCard) {
                            const statusBadge = trainerCard.querySelector('.status-badge');
                            if (statusBadge) {
                                // Update status class
                                statusBadge.className = `status-badge ${trainer.status}`;
                                
                                // Update status text and icon
                                let statusText = '';
                                switch(trainer.status) {
                                    case 'active':
                                        statusText = '<i class="fas fa-circle pulse"></i> Active';
                                        break;
                                    case 'on_leave':
                                        statusText = '<i class="fas fa-umbrella-beach"></i> On Leave';
                                        break;
                                    default:
                                        statusText = '<i class="fas fa-circle"></i> Offline';
                                }
                                statusBadge.innerHTML = statusText;
                            }
                        }
                    });
                })
                .catch(error => console.error('Error refreshing trainer status:', error));
        }

        // Refresh status every 30 seconds
        setInterval(refreshTrainerStatus, 30000);

        // Initial refresh
        document.addEventListener('DOMContentLoaded', refreshTrainerStatus);

        function viewProfile(trainerId) {
            viewTrainerProfile(trainerId);
        }

        // Simple Working Notification System
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing real-time notification system...');
            
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
                            { id: 1, title: 'New Trainer Available', message: 'A new trainer has joined our team.', read: false, type: 'info' },
                            { id: 2, title: 'Session Reminder', message: 'Your training session is scheduled for tomorrow.', read: false, type: 'reminder' },
                            { id: 3, title: 'Trainer Update', message: 'Your trainer has updated their schedule.', read: false, type: 'info' }
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
<?php include 'footer.php'; ?> 