<?php
session_start();
require_once('../db.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Initialize variables with default values
$display_name = 'User';
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$page_title = 'Settings';
$user = [];

// Create user_settings table if it doesn't exist
$check_table_sql = "SHOW TABLES LIKE 'user_settings'";
$table_exists = $conn->query($check_table_sql)->num_rows > 0;

if (!$table_exists) {
    // Create the table with the correct structure
    $create_table_sql = "
    CREATE TABLE IF NOT EXISTS user_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        membership_renewal_notify TINYINT(1) DEFAULT 1,
        announcement_notify TINYINT(1) DEFAULT 1,
        schedule_notify TINYINT(1) DEFAULT 1,
        promo_notify TINYINT(1) DEFAULT 1,
        dark_mode TINYINT(1) DEFAULT 0,
        language VARCHAR(10) DEFAULT 'en',
        timezone VARCHAR(50) DEFAULT 'UTC',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )";
    
    if (!$conn->query($create_table_sql)) {
        die("Error creating user_settings table: " . $conn->error);
    }
}

// Check if required columns exist, add them if they don't
$check_columns_sql = "SHOW COLUMNS FROM user_settings LIKE 'schedule_notify'";
$column_exists = $conn->query($check_columns_sql)->num_rows > 0;

if (!$column_exists) {
    // Add the missing column
    $add_column_sql = "ALTER TABLE user_settings ADD COLUMN schedule_notify TINYINT(1) DEFAULT 1 AFTER announcement_notify";
    if (!$conn->query($add_column_sql)) {
        die("Error adding schedule_notify column: " . $conn->error);
    }
}

// Get user information and settings
$user_id = $_SESSION['user_id'];
$sql = "SELECT u.*, 
               COALESCE(us.membership_renewal_notify, 1) as membership_renewal_notify,
               COALESCE(us.announcement_notify, 1) as announcement_notify,
               COALESCE(us.schedule_notify, 1) as fitness_goals_notify,
               COALESCE(us.promo_notify, 1) as promo_notify,
               COALESCE(us.dark_mode, 0) as dark_mode,
               COALESCE(us.language, 'en') as language,
               COALESCE(us.timezone, 'UTC') as timezone
        FROM users u 
        LEFT JOIN user_settings us ON u.id = us.user_id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Set display name and profile picture
if ($user) {
    $display_name = $user['full_name'] ?? $user['username'] ?? $user['email'] ?? 'User';
    $profile_picture = $user['profile_picture'] 
        ? "../uploads/profile_pictures/" . $user['profile_picture']
        : 'https://i.pravatar.cc/40?img=1';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update notification settings
    $membership_renewal_notify = isset($_POST['membership_renewal_notify']) ? 1 : 0;
    $announcement_notify = isset($_POST['announcement_notify']) ? 1 : 0;
    $fitness_goals_notify = isset($_POST['fitness_goals_notify']) ? 1 : 0;
    $promo_notify = isset($_POST['promo_notify']) ? 1 : 0;
    $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;

    $update_sql = "
    INSERT INTO user_settings 
        (user_id, membership_renewal_notify, announcement_notify, 
         schedule_notify, promo_notify, dark_mode) 
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        membership_renewal_notify = VALUES(membership_renewal_notify),
        announcement_notify = VALUES(announcement_notify),
        schedule_notify = VALUES(schedule_notify),
        promo_notify = VALUES(promo_notify),
        dark_mode = VALUES(dark_mode)";

    $stmt = $conn->prepare($update_sql);
    if (!$stmt) {
        die("Update prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iiiiii", 
        $user_id, $membership_renewal_notify, $announcement_notify,
        $fitness_goals_notify, $promo_notify, $dark_mode
    );

    if ($stmt->execute()) {
        $success_message = "Settings updated successfully!";
    } else {
        $error_message = "Error updating settings. Please try again.";
    }

    // Refresh user data
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Refresh prepare failed: " . $conn->error);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Almo Fitness</title>
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
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider {
            background-color: #7C3AED;
        }
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Enhanced settings card styling */
        .settings-card {
            transition: all 0.3s ease;
        }
        
        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Quick action cards hover effect */
        .settings-card a:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }
        
        /* Form input styling */
        input[readonly] {
            background-color: #f9fafb;
            cursor: not-allowed;
        }
        
        /* Enhanced button hover effects */
        button:hover {
            transform: translateY(-1px);
        }
        
        /* Info boxes styling */
        .bg-blue-50, .bg-green-50 {
            border-left: 4px solid;
        }
        
        .bg-blue-50 {
            border-left-color: #3b82f6;
        }
        
        .bg-green-50 {
            border-left-color: #10b981;
        }
        
        /* Dark mode is now handled by the shared CSS file */
    </style>
</head>
<body class="bg-gray-100" id="body">
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
            <a href="settings.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 bg-red-600 text-white shadow-md">
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
                    <?php echo $page_title ?? 'Settings'; ?>
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
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <!-- Page Header -->
                <div class="bg-gradient-to-r from-red-600 to-red-700 rounded-xl shadow-lg p-6 text-white mb-8">
                    <div class="flex items-center">
                        <i class="fas fa-cog text-3xl mr-4"></i>
                        <div>
                            <h1 class="text-3xl font-bold">Account Settings</h1>
                            <p class="text-red-100 mt-1">Customize your gym experience and preferences</p>
                        </div>
                    </div>
                </div>

                <!-- Account Information -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 settings-card">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-user-circle text-blue-500 mr-3"></i>
                        Account Information
                    </h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
                                <input type="text" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                <input type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50" readonly>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                                <input type="text" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50" readonly>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Member Since</label>
                                <input type="text" value="<?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?>" 
                                       class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50" readonly>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notification Preferences -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 settings-card">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-bell text-green-500 mr-3"></i>
                        Notification Preferences
                    </h2>
                    <p class="text-gray-600 mb-6">Choose what notifications you want to receive to stay updated with your gym activities.</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <h3 class="font-medium text-gray-800">Membership Renewals</h3>
                                    <p class="text-sm text-gray-600">Get notified about membership expiration and renewals</p>
                                </div>
                                <label class="switch ml-4">
                                    <input type="checkbox" name="membership_renewal_notify"
                                           <?php echo (!empty($user['membership_renewal_notify'])) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <h3 class="font-medium text-gray-800">Gym Announcements</h3>
                                    <p class="text-sm text-gray-600">Important updates and announcements</p>
                                </div>
                                <label class="switch ml-4">
                                    <input type="checkbox" name="announcement_notify"
                                           <?php echo (!empty($user['announcement_notify'])) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <h3 class="font-medium text-gray-800">Fitness Goals & Progress</h3>
                                    <p class="text-sm text-gray-600">Reminders about your fitness goals and progress tracking</p>
                                </div>
                                <label class="switch ml-4">
                                    <input type="checkbox" name="fitness_goals_notify"
                                           <?php echo (!empty($user['fitness_goals_notify'])) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                                <div class="flex-1">
                                    <h3 class="font-medium text-gray-800">Promotions & Events</h3>
                                    <p class="text-sm text-gray-600">Special offers and upcoming events</p>
                                </div>
                                <label class="switch ml-4">
                                    <input type="checkbox" name="promo_notify"
                                           <?php echo (!empty($user['promo_notify'])) ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Display Settings -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 settings-card">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-desktop text-purple-500 mr-3"></i>
                        Display Settings
                    </h2>
                    <p class="text-gray-600 mb-6">Customize how the gym interface appears to you.</p>
                    
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
                            <div class="flex-1">
                                <h3 class="font-medium text-gray-800">Dark Mode</h3>
                                <p class="text-sm text-gray-600">Toggle dark theme for the interface</p>
                                <div class="flex items-center mt-2 text-xs text-gray-500">
                                    <i class="fas fa-moon mr-1"></i>
                                    <span id="darkModeStatus">Light mode active</span>
                                </div>
                            </div>
                            <label class="switch ml-4">
                                <input type="checkbox" name="dark_mode"
                                       <?php echo (!empty($user['dark_mode'])) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Privacy & Security -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 settings-card">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-shield-alt text-orange-500 mr-3"></i>
                        Privacy & Security
                    </h2>
                    <p class="text-gray-600 mb-6">Manage your account security and privacy settings.</p>
                    
                    <div class="space-y-4">
                        <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                            <div class="flex items-start">
                                <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                                <div>
                                    <h3 class="font-medium text-blue-800">Account Security</h3>
                                    <p class="text-sm text-blue-600 mt-1">Your account is protected with secure authentication. For password changes, please contact gym administration.</p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-4 bg-green-50 rounded-lg border border-green-200">
                            <div class="flex items-start">
                                <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                                <div>
                                    <h3 class="font-medium text-green-800">Data Protection</h3>
                                    <p class="text-sm text-green-600 mt-1">Your personal information is encrypted and stored securely. We never share your data with third parties.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6 settings-card">
                    <h2 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
                        <i class="fas fa-bolt text-yellow-500 mr-3"></i>
                        Quick Actions
                    </h2>
                    <p class="text-gray-600 mb-6">Common actions you can take from here.</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <a href="profile.php" class="flex items-center p-4 bg-gray-50 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-user-edit text-blue-500 mr-3 text-xl"></i>
                            <div>
                                <h3 class="font-medium text-gray-800">Edit Profile</h3>
                                <p class="text-sm text-gray-600">Update your personal information</p>
                            </div>
                        </a>
                        
                        <a href="membership.php" class="flex items-center p-4 bg-gray-50 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-id-card text-green-500 mr-3 text-xl"></i>
                            <div>
                                <h3 class="font-medium text-gray-800">View Membership</h3>
                                <p class="text-sm text-gray-600">Check your membership status</p>
                            </div>
                        </a>
                        
                        <a href="payment.php" class="flex items-center p-4 bg-gray-50 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-credit-card text-purple-500 mr-3 text-xl"></i>
                            <div>
                                <h3 class="font-medium text-gray-800">Payment History</h3>
                                <p class="text-sm text-gray-600">View your payment records</p>
                            </div>
                        </a>
                        
                        <a href="progress.php" class="flex items-center p-4 bg-gray-50 rounded-lg border border-gray-200 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-chart-line text-orange-500 mr-3 text-xl"></i>
                            <div>
                                <h3 class="font-medium text-gray-800">Track Progress</h3>
                                <p class="text-sm text-gray-600">Monitor your fitness journey</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-6 border border-gray-200">
                    <div class="flex flex-col sm:flex-row items-center justify-between">
                        <div class="mb-4 sm:mb-0">
                            <h3 class="text-lg font-semibold text-gray-800">Ready to save your changes?</h3>
                            <p class="text-gray-600">Your settings will be applied immediately after saving.</p>
                        </div>
                        <div class="flex space-x-3">
                            <button type="button" onclick="resetForm()" class="px-6 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors duration-200">
                                <i class="fas fa-undo mr-2"></i>
                                Reset
                            </button>
                            <button type="submit" class="bg-red-600 text-white px-8 py-3 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors duration-200 font-semibold">
                                <i class="fas fa-save mr-2"></i>
                                Save Settings
                            </button>
                        </div>
                    </div>
                </div>
            </form>
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
        const logoText = document.querySelector('.sidebar-logo-text');
        const navTexts = document.querySelectorAll('nav span');
        const bottomTexts = document.querySelectorAll('.sidebar-bottom-text');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            toggleIcon.classList.toggle('rotate-180');
            
            // Toggle visibility of text elements
            [logoText, ...navTexts, ...bottomTexts].forEach(el => {
                el.classList.toggle('hidden');
            });
        });

        // Show success message temporarily
        const successAlert = document.querySelector('.bg-green-100');
        if (successAlert) {
            setTimeout(() => {
                successAlert.style.transition = 'opacity 0.5s ease';
                successAlert.style.opacity = '0';
                setTimeout(() => successAlert.remove(), 500);
            }, 3000);
        }
        
        // Reset form function
        function resetForm() {
            if (confirm('Are you sure you want to reset all settings to their default values?')) {
                // Reset all checkboxes to checked (default values)
                const checkboxes = document.querySelectorAll('input[type="checkbox"]');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                
                // Show reset confirmation
                const resetMessage = document.createElement('div');
                resetMessage.className = 'bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-6';
                resetMessage.innerHTML = '<p>Settings have been reset to default values!</p>';
                
                const form = document.querySelector('form');
                form.insertBefore(resetMessage, form.firstChild);
                
                // Remove message after 3 seconds
                setTimeout(() => {
                    resetMessage.style.transition = 'opacity 0.5s ease';
                    resetMessage.style.opacity = '0';
                    setTimeout(() => resetMessage.remove(), 500);
                }, 3000);
            }
        }
        
        // Dark Mode Functions
        function toggleDarkMode() {
            const body = document.getElementById('body');
            const isDarkMode = body.classList.contains('dark-mode');
            const statusText = document.getElementById('darkModeStatus');
            
            if (isDarkMode) {
                body.classList.remove('dark-mode');
                localStorage.setItem('darkMode', 'false');
                if (statusText) statusText.textContent = 'Light mode active';
                console.log('Dark mode disabled');
            } else {
                body.classList.add('dark-mode');
                localStorage.setItem('darkMode', 'true');
                if (statusText) statusText.textContent = 'Dark mode active';
                console.log('Dark mode enabled');
            }
        }
        
        function loadDarkModePreference() {
            const darkModeToggle = document.querySelector('input[name="dark_mode"]');
            const body = document.getElementById('body');
            const statusText = document.getElementById('darkModeStatus');
            
            // Check localStorage first
            const savedDarkMode = localStorage.getItem('darkMode');
            
            if (savedDarkMode === 'true') {
                body.classList.add('dark-mode');
                if (darkModeToggle) darkModeToggle.checked = true;
                if (statusText) statusText.textContent = 'Dark mode active';
                console.log('Dark mode loaded from localStorage');
            } else if (savedDarkMode === 'false') {
                body.classList.remove('dark-mode');
                if (darkModeToggle) darkModeToggle.checked = false;
                if (statusText) statusText.textContent = 'Light mode active';
                console.log('Light mode loaded from localStorage');
            } else {
                // Check database value if no localStorage preference
                const isDarkMode = <?php echo (!empty($user['dark_mode'])) ? 'true' : 'false'; ?>;
                if (isDarkMode) {
                    body.classList.add('dark-mode');
                    if (darkModeToggle) darkModeToggle.checked = true;
                    if (statusText) statusText.textContent = 'Dark mode active';
                    localStorage.setItem('darkMode', 'true');
                    console.log('Dark mode loaded from database');
                } else {
                    body.classList.remove('dark-mode');
                    if (darkModeToggle) darkModeToggle.checked = false;
                    if (statusText) statusText.textContent = 'Light mode active';
                    localStorage.setItem('darkMode', 'false');
                    console.log('Light mode loaded from database');
                }
            }
        }
        
        // Initialize dark mode when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadDarkModePreference();
            
            // Add event listener to dark mode toggle
            const darkModeToggle = document.querySelector('input[name="dark_mode"]');
            if (darkModeToggle) {
                darkModeToggle.addEventListener('change', function() {
                    toggleDarkMode();
                });
            }
        });
    </script>
</body>
</html>
<?php include 'footer.php'; ?>
</body>
</html> 