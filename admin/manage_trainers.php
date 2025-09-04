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

// Get message from URL if it exists
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageClass = 'success';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $redirect_params = [];
        
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            $name = trim($_POST['name']);
            $specialization = trim($_POST['specialization']);
            $experience_years = trim($_POST['experience_years']);
            $bio = trim($_POST['bio']);
            $contact_number = trim($_POST['contact_number'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $hourly_rate = !empty($_POST['hourly_rate']) ? floatval($_POST['hourly_rate']) : 50.00;
            $status = trim($_POST['status'] ?? 'active');
            $availability_schedule = trim($_POST['availability_schedule'] ?? '');
            
            // Handle image upload
            $image_url = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $target_dir = "../uploads/trainer_images/";
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $new_filename = uniqid() . '.' . $file_extension;
                $target_file = $target_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
                    $image_url = 'uploads/trainer_images/' . $new_filename;
                }
            }
            
            if (empty($name) || empty($specialization) || empty($experience_years)) {
                $redirect_params['message'] = "Please fill in all required fields.";
                $redirect_params['status'] = 'error';
            } else {
                // Check if trainers table has all required columns
                $check_columns = $conn->query("SHOW COLUMNS FROM trainers");
                $existing_columns = [];
                while ($col = $check_columns->fetch_assoc()) {
                    $existing_columns[] = $col['Field'];
                }

                // Add missing columns if needed
                $needed_columns = [
                    'hourly_rate' => 'DECIMAL(10,2) DEFAULT 50.00',
                    'status' => "ENUM('active', 'inactive') DEFAULT 'active'",
                    'availability_schedule' => 'TEXT',
                    'bio' => 'TEXT',
                    'experience_years' => 'INT DEFAULT 0',
                    'contact_number' => 'VARCHAR(20)',
                    'email' => 'VARCHAR(100)',
                    'image_url' => 'VARCHAR(255)'
                ];

                foreach ($needed_columns as $column => $definition) {
                    if (!in_array($column, $existing_columns)) {
                        $conn->query("ALTER TABLE trainers ADD COLUMN IF NOT EXISTS $column $definition");
                    }
                }

                if ($_POST['action'] === 'add') {
                    $sql = "INSERT INTO trainers (name, specialization, experience_years, bio, contact_number, email, image_url, hourly_rate, status, availability_schedule) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("ssissssdss", $name, $specialization, $experience_years, $bio, $contact_number, $email, $image_url, $hourly_rate, $status, $availability_schedule);
                } else {
                    $id = $_POST['trainer_id'];
                    if ($image_url) {
                        $sql = "UPDATE trainers SET name = ?, specialization = ?, experience_years = ?, bio = ?, contact_number = ?, email = ?, image_url = ?, hourly_rate = ?, status = ?, availability_schedule = ? WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssissssdssi", $name, $specialization, $experience_years, $bio, $contact_number, $email, $image_url, $hourly_rate, $status, $availability_schedule, $id);
                    } else {
                        $sql = "UPDATE trainers SET name = ?, specialization = ?, experience_years = ?, bio = ?, contact_number = ?, email = ?, hourly_rate = ?, status = ?, availability_schedule = ? WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssisssdssi", $name, $specialization, $experience_years, $bio, $contact_number, $email, $hourly_rate, $status, $availability_schedule, $id);
                    }
                }
                
                if ($stmt->execute()) {
                    $trainer_id = $id ?? $conn->insert_id;
                    
                    // Handle specialties
                    if (isset($_POST['specialties']) && is_array($_POST['specialties'])) {
                        // Delete existing specialties
                        $delete_sql = "DELETE FROM trainer_specialties WHERE trainer_id = ?";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("i", $trainer_id);
                        $delete_stmt->execute();
                        
                        // Insert new specialties
                        $insert_sql = "INSERT INTO trainer_specialties (trainer_id, specialty) VALUES (?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        foreach ($_POST['specialties'] as $specialty) {
                            $insert_stmt->bind_param("is", $trainer_id, $specialty);
                            $insert_stmt->execute();
                        }
                    }
                    
                    // Handle schedule
                    if (isset($_POST['schedule']) && is_array($_POST['schedule'])) {
                        // Delete existing schedules
                        $delete_sql = "DELETE FROM trainer_schedules WHERE trainer_id = ?";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("i", $trainer_id);
                        $delete_stmt->execute();
                        
                        // Insert new schedules
                        $insert_sql = "INSERT INTO trainer_schedules (trainer_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_sql);
                        foreach ($_POST['schedule'] as $day => $times) {
                            if (!empty($times['start']) && !empty($times['end'])) {
                                $insert_stmt->bind_param("isss", $trainer_id, $day, $times['start'], $times['end']);
                                $insert_stmt->execute();
                            }
                        }
                    }
                    
                    $redirect_params['message'] = "Trainer " . ($_POST['action'] === 'add' ? "added" : "updated") . " successfully!";
                    $redirect_params['status'] = 'success';
                } else {
                    $redirect_params['message'] = "Error: " . $conn->error;
                    $redirect_params['status'] = 'error';
                }
            }
        } elseif ($_POST['action'] === 'delete' && isset($_POST['trainer_id'])) {
            $id = $_POST['trainer_id'];
            
            // Check if trainer has assigned classes
            $check_classes_sql = "SELECT COUNT(*) as class_count FROM classes WHERE trainer_id = ?";
            $check_stmt = $conn->prepare($check_classes_sql);
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $class_result = $check_stmt->get_result();
            $class_count = $class_result->fetch_assoc()['class_count'];
            
            if ($class_count > 0) {
                $redirect_params['message'] = "Cannot delete trainer: This trainer has " . $class_count . " assigned class(es). Please reassign or delete the classes first.";
                $redirect_params['status'] = 'error';
            } else {
                // Get the image URL before deleting
                $stmt = $conn->prepare("SELECT image_url FROM trainers WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $trainer = $result->fetch_assoc();
                
                $sql = "DELETE FROM trainers WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Delete the image file if it exists and trainer was found
                    if ($trainer && $trainer['image_url'] && file_exists("../" . $trainer['image_url'])) {
                        unlink("../" . $trainer['image_url']);
                    }
                    $redirect_params['message'] = "Trainer deleted successfully!";
                    $redirect_params['status'] = 'success';
                } else {
                    $redirect_params['message'] = "Error: " . $conn->error;
                    $redirect_params['status'] = 'error';
                }
            }
        }
        
        // Redirect with parameters
        $redirect_url = 'manage_trainers.php';
        if (!empty($redirect_params)) {
            $redirect_url .= '?' . http_build_query($redirect_params);
        }
        header("Location: " . $redirect_url);
        exit();
    }
}

// Get message from URL parameters
$message = $_GET['message'] ?? '';
$messageClass = $_GET['status'] ?? '';

// Check if tables exist
$result = $conn->query("SHOW TABLES LIKE 'trainer_specialties'");
$has_specialties_table = ($result && $result->num_rows > 0);

$result = $conn->query("SHOW TABLES LIKE 'trainer_schedules'");
$has_schedules_table = ($result && $result->num_rows > 0);

$result = $conn->query("SHOW TABLES LIKE 'classes'");
$has_classes_table = ($result && $result->num_rows > 0);

// Check if columns exist in trainers table
$result = $conn->query("SHOW COLUMNS FROM trainers");
$trainer_columns = [];
while ($row = $result->fetch_assoc()) {
    $trainer_columns[] = $row['Field'];
}

// Build the SQL query based on existing tables and columns
$trainers_sql = "SELECT t.*";

if ($has_classes_table) {
    $trainers_sql .= ", COUNT(DISTINCT c.id) as class_count";
}

if ($has_specialties_table) {
    $trainers_sql .= ", GROUP_CONCAT(DISTINCT ts.specialty) as specialties";
}

if ($has_schedules_table) {
    $trainers_sql .= ", GROUP_CONCAT(DISTINCT CONCAT(tsch.day_of_week, ': ', 
                        TIME_FORMAT(tsch.start_time, '%h:%i %p'), ' - ', 
                        TIME_FORMAT(tsch.end_time, '%h:%i %p'))
                    ORDER BY FIELD(tsch.day_of_week, 
                        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
                        'Friday', 'Saturday', 'Sunday')
                    SEPARATOR '\n') as schedule_details";
}

$trainers_sql .= " FROM trainers t";

if ($has_classes_table) {
    $trainers_sql .= " LEFT JOIN classes c ON t.id = c.trainer_id";
}

if ($has_specialties_table) {
    $trainers_sql .= " LEFT JOIN trainer_specialties ts ON t.id = ts.trainer_id";
}

if ($has_schedules_table) {
    $trainers_sql .= " LEFT JOIN trainer_schedules tsch ON t.id = tsch.trainer_id";
}

$trainers_sql .= " GROUP BY t.id ORDER BY t.name";

$trainers = $conn->query($trainers_sql);

if (!$trainers) {
    die("Error fetching trainers: " . $conn->error);
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

// Default profile picture and display name
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Manage Trainers';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Trainers - Admin Dashboard</title>
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

        /* Sticky header for table */
        .sticky {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Custom scrollbar for trainers */
        .trainers-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .trainers-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .trainers-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .trainers-scroll-container::-webkit-scrollbar-thumb:hover {
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
                    <?php echo $page_title ?? 'Manage Trainers'; ?>
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
                <div class="mb-4 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>



            <!-- Current Trainers - Main Content -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Current Trainers</h2>
                    <div class="flex items-center space-x-4">
                        <!-- Search Bar -->
                        <div class="relative">
                            <input type="text" id="searchTrainer" placeholder="Search trainers..." 
                                   class="pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 w-64">
                            <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                        
                        <!-- Add New Trainer Button -->
                        <button onclick="showAddForm()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all duration-200 font-medium">
                            <i class="fas fa-plus mr-2"></i>Add New
                        </button>
                        
                        <button onclick="refreshTrainers()" class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors duration-200">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                
                <div class="trainers-scroll-container" style="max-height: 600px; overflow-y: auto; overflow-x: hidden;">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Name</th>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Specialization</th>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Experience</th>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Rate/Hour</th>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Status</th>
                                <th class="px-4 py-3 text-left text-sm font-medium text-gray-600 border-b border-gray-200">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if ($trainers && $trainers->num_rows > 0): ?>
                                <?php while($trainer = $trainers->fetch_assoc()): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center space-x-3">
                                                <?php if (!empty($trainer['image_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars('../' . $trainer['image_url']); ?>" alt="<?php echo htmlspecialchars($trainer['name']); ?>" class="w-10 h-10 rounded-full object-cover border border-gray-200">
                                                <?php else: ?>
                                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center border border-gray-200">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <button onclick="showTrainerDetails(<?php echo $trainer['id']; ?>)" class="text-left hover:text-blue-600 transition-colors duration-200">
                                                        <div class="font-medium text-gray-800 hover:text-blue-600"><?php echo htmlspecialchars($trainer['name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($trainer['email'] ?? 'No email'); ?></div>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($trainer['specialization']); ?></div>
                                            <?php if (isset($trainer['specialties'])): ?>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($trainer['specialties']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900"><?php echo htmlspecialchars($trainer['experience_years']); ?> years</td>
                                        <td class="px-4 py-3 text-sm text-gray-900">₱<?php echo number_format($trainer['hourly_rate'] ?? 0, 2); ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $trainer['status'] === 'active' ? 'bg-green-100 text-green-800 border border-green-200' : 'bg-red-100 text-red-800 border border-red-200'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($trainer['status'] ?? 'active')); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center space-x-2">
                                                <button onclick="editTrainer(<?php echo $trainer['id']; ?>)" 
                                                        class="p-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors duration-200" 
                                                        title="Edit Trainer">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteTrainer(<?php echo $trainer['id']; ?>)" 
                                                        class="p-2 text-red-600 hover:text-red-800 hover:bg-red-50 rounded-lg transition-colors duration-200" 
                                                        title="Delete Trainer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <button onclick="showTrainerDetails(<?php echo $trainer['id']; ?>)" 
                                                        class="p-2 text-gray-600 hover:text-gray-800 hover:bg-gray-50 rounded-lg transition-colors duration-200" 
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-4 py-12 text-center text-gray-500">
                                        <div class="text-gray-500">
                                            <i class="fas fa-users text-6xl mb-4 text-gray-300"></i>
                                            <p class="text-xl font-medium">No trainers found</p>
                                            <p class="text-gray-500">Click the "Add New" button to create your first trainer!</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Add/Edit Trainer Modal -->
            <div id="trainerModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
                <div class="flex items-center justify-center min-h-screen p-4">
                    <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
                        <div class="flex items-center justify-between p-6 border-b border-gray-200">
                            <h2 id="modalTitle" class="text-xl font-semibold text-gray-800">Add New Trainer</h2>
                            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors duration-200">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        
                        <form id="trainerForm" action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="trainer_id" value="">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Name *</label>
                                    <input type="text" name="name" required placeholder="Enter trainer's full name" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Specialization *</label>
                                    <input type="text" name="specialization" required placeholder="e.g., Weight Training, Cardio" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Years of Experience *</label>
                                    <input type="number" name="experience_years" required min="0" max="50" placeholder="0" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact Number</label>
                                    <input type="tel" name="contact_number" placeholder="+63 912 345 6789" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                    <input type="email" name="email" placeholder="trainer@example.com" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Hourly Rate (₱) *</label>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500">₱</span>
                                        <input type="number" name="hourly_rate" step="0.01" min="0" value="50.00" required 
                                               class="w-full pl-8 pr-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                                    <select name="status" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                        <option value="active">🟢 Active</option>
                                        <option value="inactive">🔴 Inactive</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Profile Image</label>
                                    <input type="file" name="image" accept="image/*" 
                                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Bio</label>
                                <textarea name="bio" rows="4" placeholder="Tell us about the trainer's background..." 
                                          class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 resize-none"></textarea>
                            </div>
                            
                            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
                                <button type="button" onclick="closeModal()" class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors duration-200">
                                    Cancel
                                </button>
                                <button type="submit" id="submitButton" 
                                        class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all duration-200 font-medium">
                                    <i class="fas fa-plus mr-2"></i>Add Trainer
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Trainer Details Modal -->
    <div id="trainerDetailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-screen overflow-y-auto">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-xl font-semibold text-gray-800">Trainer Details</h3>
                    <button onclick="closeTrainerDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div id="trainerDetailsContent" class="p-6">
                    <div class="text-center text-gray-500">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        document.getElementById('searchTrainer').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tableRows = document.querySelectorAll('tbody tr');
            
            tableRows.forEach(row => {
                const trainerName = row.querySelector('td:first-child .font-medium').textContent.toLowerCase();
                const specialization = row.querySelector('td:nth-child(2) .text-gray-900').textContent.toLowerCase();
                const email = row.querySelector('td:first-child .text-gray-500').textContent.toLowerCase();
                
                if (trainerName.includes(searchTerm) || specialization.includes(searchTerm) || email.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Refresh trainers function
        function refreshTrainers() {
            location.reload();
        }

        // Edit Trainer Function
        async function editTrainer(trainerId) {
            try {
                const response = await fetch(`get_trainer.php?id=${trainerId}`);
                const trainer = await response.json();
                
                if (trainer) {
                    const form = document.getElementById('trainerForm');
                    const formTitle = document.getElementById('formTitle');
                    const submitButton = document.getElementById('submitButton');
                    
                    form.querySelector('[name="action"]').value = 'edit';
                    form.querySelector('[name="trainer_id"]').value = trainer.id;
                    form.querySelector('[name="name"]').value = trainer.name;
                    form.querySelector('[name="specialization"]').value = trainer.specialization;
                    form.querySelector('[name="experience_years"]').value = trainer.experience_years;
                    form.querySelector('[name="contact_number"]').value = trainer.contact_number || '';
                    form.querySelector('[name="email"]').value = trainer.email || '';
                    form.querySelector('[name="hourly_rate"]').value = trainer.hourly_rate || 50.00;
                    form.querySelector('[name="status"]').value = trainer.status || 'active';
                    form.querySelector('[name="bio"]').value = trainer.bio || '';
                    
                    formTitle.textContent = 'Edit Trainer';
                    submitButton.textContent = 'Update Trainer';
                    
                    form.scrollIntoView({ behavior: 'smooth' });
                }
            } catch (error) {
                console.error('Error fetching trainer details:', error);
                alert('Error fetching trainer details. Please try again.');
            }
        }

        // Delete Trainer Function
        function deleteTrainer(trainerId) {
            if (confirm('Are you sure you want to delete this trainer? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="trainer_id" value="${trainerId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Reset Form Function
        function resetForm() {
            const form = document.getElementById('trainerForm');
            const formTitle = document.getElementById('formTitle');
            const submitButton = document.getElementById('submitButton');
            
            form.reset();
            form.querySelector('[name="action"]').value = 'add';
            form.querySelector('[name="trainer_id"]').value = '';
            formTitle.textContent = 'Add New Trainer';
            submitButton.textContent = 'Add Trainer';
        }

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
        
        // Show Trainer Details Function
        async function showTrainerDetails(trainerId) {
            try {
                const response = await fetch(`get_trainer.php?id=${trainerId}`);
                const trainer = await response.json();
                
                if (trainer) {
                    // Populate the modal with trainer information
                    document.getElementById('trainerDetailsContent').innerHTML = `
                        <div class="space-y-6">
                            <!-- Header with Avatar -->
                            <div class="flex items-center space-x-4 pb-4 border-b border-gray-200">
                                <div class="w-20 h-20 rounded-full overflow-hidden flex items-center justify-center">
                                    ${trainer.image_url ? 
                                        `<img src="../${trainer.image_url}" alt="${trainer.name}" class="w-full h-full object-cover">` :
                                        `<div class="w-full h-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center text-white text-3xl font-bold">
                                            ${trainer.name.charAt(0).toUpperCase()}
                                        </div>`
                                    }
                                </div>
                                <div>
                                    <h3 class="text-2xl font-bold text-gray-900">${trainer.name}</h3>
                                    <p class="text-gray-600">${trainer.specialization}</p>
                                </div>
                            </div>
                            
                            <!-- Personal Information -->
                            <div class="space-y-4">
                                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-user text-blue-500 mr-2"></i>
                                    Personal Information
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                        <i class="fas fa-envelope text-gray-400 w-5"></i>
                                        <div>
                                            <p class="text-sm text-gray-500">Email</p>
                                            <p class="font-medium text-gray-900">${trainer.email || 'Not provided'}</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                        <i class="fas fa-phone text-gray-400 w-5"></i>
                                        <div>
                                            <p class="text-sm text-gray-500">Contact Number</p>
                                            <p class="font-medium text-gray-900">${trainer.contact_number || 'Not provided'}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Professional Details -->
                            <div class="space-y-4">
                                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-briefcase text-green-500 mr-2"></i>
                                    Professional Details
                                </h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                        <i class="fas fa-clock text-gray-400 w-5"></i>
                                        <div>
                                            <p class="text-sm text-gray-500">Experience</p>
                                            <p class="font-medium text-gray-900">${trainer.experience_years} years</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                        <i class="fas fa-dollar-sign text-gray-400 w-5"></i>
                                        <div>
                                            <p class="text-sm text-gray-500">Hourly Rate</p>
                                            <p class="font-medium text-gray-900">₱${parseFloat(trainer.hourly_rate || 0).toFixed(2)}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bio -->
                            ${trainer.bio ? `
                            <div class="space-y-4">
                                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-quote-left text-purple-500 mr-2"></i>
                                    Biography
                                </h4>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <p class="text-gray-700 leading-relaxed">${trainer.bio}</p>
                                </div>
                            </div>
                            ` : ''}
                            
                            <!-- Status -->
                            <div class="space-y-4">
                                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-info-circle text-orange-500 mr-2"></i>
                                    Status
                                </h4>
                                <div class="flex items-center space-x-3 p-3 bg-gray-50 rounded-lg">
                                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full ${trainer.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                        ${trainer.status === 'active' ? 'Active' : 'Inactive'}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Availability -->
                            ${trainer.availability_schedule ? `
                            <div class="space-y-4">
                                <h4 class="text-lg font-semibold text-gray-900 flex items-center">
                                    <i class="fas fa-calendar text-indigo-500 mr-2"></i>
                                    Availability Schedule
                                </h4>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <p class="text-gray-700 whitespace-pre-line">${trainer.availability_schedule}</p>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    `;
                    
                    // Show the modal
                    document.getElementById('trainerDetailsModal').classList.remove('hidden');
                }
            } catch (error) {
                console.error('Error fetching trainer details:', error);
                alert('Error fetching trainer details. Please try again.');
            }
        }
        
        // Close Trainer Details Modal
        function closeTrainerDetailsModal() {
            document.getElementById('trainerDetailsModal').classList.add('hidden');
        }
        
        // Close modal when clicking outside
        document.getElementById('trainerDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeTrainerDetailsModal();
            }
        });
        
        // Modal Functions
        function showAddForm() {
            document.getElementById('trainerModal').classList.remove('hidden');
            document.getElementById('modalTitle').textContent = 'Add New Trainer';
            document.getElementById('submitButton').innerHTML = '<i class="fas fa-plus mr-2"></i>Add Trainer';
            document.getElementById('trainerForm').reset();
            document.getElementById('trainerForm').querySelector('[name="action"]').value = 'add';
            document.getElementById('trainerForm').querySelector('[name="trainer_id"]').value = '';
        }
        
        function closeModal() {
            document.getElementById('trainerModal').classList.add('hidden');
        }
        
        // Enhanced Edit Function
        function editTrainer(id) {
            // Fetch trainer data and populate form
            const form = document.getElementById('trainerForm');
            const modalTitle = document.getElementById('modalTitle');
            const submitButton = document.getElementById('submitButton');
            
            // Show modal
            document.getElementById('trainerModal').classList.remove('hidden');
            modalTitle.textContent = 'Edit Trainer';
            submitButton.innerHTML = '<i class="fas fa-save mr-2"></i>Update Trainer';
            
            // Set form action to edit
            form.querySelector('[name="action"]').value = 'edit';
            form.querySelector('[name="trainer_id"]').value = id;
            
            // You can add AJAX call here to fetch trainer data and populate form fields
            // For now, we'll use the existing edit functionality
        }
        
        // Close modal when clicking outside
        document.getElementById('trainerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_trainers.php...');
        
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
            
            console.log('Real-time SSE notification system initialized successfully for manage_trainers.php!');
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
