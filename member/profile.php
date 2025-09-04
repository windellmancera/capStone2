profile.php
<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';
require_once 'payment_status_helper.php';
require_once 'predictive_analysis_helper.php';
require_once 'exercise_details_helper.php';

// Get user information from database
$user_id = $_SESSION['user_id'];
$user = getUserPaymentStatus($conn, $user_id);

// Get user's profile picture directly from users table
$profile_sql = "SELECT profile_picture FROM users WHERE id = ?";
$profile_stmt = $conn->prepare($profile_sql);
if ($profile_stmt) {
    $profile_stmt->bind_param("i", $user_id);
    $profile_stmt->execute();
    $profile_result = $profile_stmt->get_result();
    $profile_data = $profile_result->fetch_assoc();
    
    // Add profile picture to user array
    if ($profile_data) {
        $user['profile_picture'] = $profile_data['profile_picture'];
    }
}

// Initialize predictive analysis
$predictive_analysis = new MemberPredictiveAnalysis($conn, $user_id);
$member_analytics = $predictive_analysis->getMemberAnalytics();
$plan_recommendations = $predictive_analysis->getPlanRecommendations();

// Get recent payments
$recent_payments = getRecentPayments($conn, $user_id, 5);

// Get QR code
$qr_code = getQRCode($user);

// Calculate attendance frequency (visits per month)
$attendance_sql = "SELECT COUNT(*) as visit_count 
                  FROM attendance 
                  WHERE user_id = ? 
                  AND check_in_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$attendance_stmt = $conn->prepare($attendance_sql);
$attendance_stmt->bind_param("i", $user_id);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();
$attendance_data = $attendance_result->fetch_assoc();
$monthly_visits = $attendance_data['visit_count'];

// Handle profile picture upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $target_dir = "../uploads/profile_pictures/";
    
    // Check if upload directory exists and is writable
    if (!is_dir($target_dir)) {
        if (!mkdir($target_dir, 0755, true)) {
            $error_message = "Failed to create upload directory. Please contact administrator.";
        }
    }
    
    if (!is_dir($target_dir) || !is_writable($target_dir)) {
        $error_message = "Upload directory is not writable. Please contact administrator.";
    } else {
        $file = $_FILES['profile_picture'];
        $file_extension = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
        
        // Additional security: sanitize filename
        $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $file["name"]);
        $new_filename = "profile_" . $user_id . "_" . time() . "_" . substr(md5($safe_filename), 0, 8) . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        // Validate file size (max 5MB)
        if ($file["size"] > 5 * 1024 * 1024) {
            $error_message = "File size too large. Maximum size is 5MB.";
        }
        // Check if image file is a actual image or fake image
        elseif (getimagesize($file["tmp_name"]) === false) {
            $error_message = "Invalid image file. Please upload a valid image.";
        }
        // Allow certain file formats
        elseif (!in_array($file_extension, ["jpg", "jpeg", "png", "gif"])) {
            $error_message = "Only JPG, JPEG, PNG & GIF files are allowed.";
        }
        // Check for upload errors
        elseif ($file["error"] !== UPLOAD_ERR_OK) {
            $error_message = "Upload error occurred. Please try again.";
        }
        // Try to move uploaded file
        elseif (!move_uploaded_file($file["tmp_name"], $target_file)) {
            $error_message = "Failed to save uploaded file. Please try again.";
        } else {
            // Update database with new profile picture
            $update_sql = "UPDATE users SET profile_picture = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("si", $new_filename, $user_id);
                if ($update_stmt->execute()) {
                    // Delete old profile picture if exists
                    if (!empty($user['profile_picture']) && file_exists($target_dir . $user['profile_picture'])) {
                        @unlink($target_dir . $user['profile_picture']); // Suppress errors if file can't be deleted
                    }
                    
                    // Redirect to refresh the page
                    header("Location: profile.php?success=1");
                    exit();
                } else {
                    $error_message = "Failed to update database. Please try again.";
                    // Remove uploaded file if database update failed
                    if (file_exists($target_file)) {
                        unlink($target_file);
                    }
                }
            } else {
                $error_message = "Database error. Please try again.";
                // Remove uploaded file if database preparation failed
                if (file_exists($target_file)) {
                    unlink($target_file);
                }
            }
        }
    }
}

// Handle success and error messages
$success_message = "";
$error_message = "";

if (isset($_GET['fitness_updated']) && $_GET['fitness_updated'] == '1') {
    if (isset($_GET['refresh_recommendations']) && $_GET['refresh_recommendations'] == '1') {
        $success_message = "Fitness goals updated successfully! Your personalized recommendations have been refreshed. Check the Recommendations section for your updated workout plan.";
    } else {
        $success_message = "Fitness goals updated successfully! Your personalized recommendations are now available.";
    }
}

if (isset($_GET['error'])) {
    $error_message = htmlspecialchars($_GET['error']);
}

// Get current user data for the top bar
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $user_sql = "SELECT username, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    
    if ($user_stmt === false) {
        die("Error preparing user statement: " . $conn->error);
    }
    
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $result = $user_stmt->get_result();
    $current_user = $result->fetch_assoc();
}

// Update profile picture variable for display
$profile_picture = '';
if (!empty($user) && !empty($user['profile_picture']) && file_exists("../uploads/profile_pictures/" . $user['profile_picture'])) {
    $profile_picture = "../uploads/profile_pictures/" . $user['profile_picture'];
} else {
    // Use a data URI for a default avatar icon
    $profile_picture = "data:image/svg+xml;base64," . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23e5e7eb"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>');
}

$display_name = $current_user['username'] ?? $current_user['email'] ?? 'User';
$page_title = 'Profile';

// Check if user has an active membership plan (not daily)
$membership_sql = "SELECT ph.id as payment_id, mp.id as plan_id, mp.duration, mp.name as plan_name,
                        mp.price as plan_price, mp.features as plan_features,
                        ph.payment_date, ph.payment_status,
                        DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date,
                        DATEDIFF(DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY), CURDATE()) as days_remaining
                 FROM payment_history ph
                 INNER JOIN users u ON ph.user_id = u.id
                 INNER JOIN membership_plans mp ON u.selected_plan_id = mp.id
                 WHERE ph.user_id = ? 
                 AND ph.payment_status = 'Approved'
                 AND DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) >= CURDATE()
                 ORDER BY ph.payment_date DESC 
                 LIMIT 1";

$stmt = $conn->prepare($membership_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$membership = $stmt->get_result()->fetch_assoc();

// Only show QR code for memberships with duration >= 30 days (monthly/annual plans)
$show_qr = $membership && $membership['duration'] >= 30;

// Check if membership is expiring soon (within 7 days)
$expiring_soon = false;
$expiration_warning = '';
if ($membership && $membership['days_remaining'] <= 7 && $membership['days_remaining'] > 0) {
    $expiring_soon = true;
    if ($membership['days_remaining'] == 1) {
        $expiration_warning = 'Your membership expires tomorrow!';
    } elseif ($membership['days_remaining'] <= 3) {
        $expiration_warning = 'Your membership expires in ' . $membership['days_remaining'] . ' days!';
    } else {
        $expiration_warning = 'Your membership expires in ' . $membership['days_remaining'] . ' days!';
    }
} elseif ($membership && $membership['days_remaining'] <= 0) {
    $expiration_warning = 'Your membership has expired!';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Almo Fitness</title>
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
        
        /* BMI Modal Styles */
        .bmi-modal {
            backdrop-filter: blur(5px);
        }
        
        .bmi-modal .modal-content {
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bmi-result {
            transition: all 0.3s ease;
        }
        
        .bmi-result.show {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        
        /* Profile Picture Styles */
        .profile-picture-container {
            transition: all 0.3s ease;
        }
        
        .profile-picture-container:hover {
            transform: scale(1.02);
        }
        
        .profile-picture-upload-label {
            transition: all 0.3s ease;
        }
        
        .profile-picture-upload-label:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .profile-picture-preview {
            animation: fadeInUp 0.3s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Responsive adjustments */
        @media (max-width: 640px) {
            .bmi-modal .modal-content {
                width: 90%;
                margin: 0 auto;
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
                    <?php echo $page_title ?? 'Profile'; ?>
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
                                                                <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover" onerror="this.src='data:image/svg+xml;base64,<?php echo base64_encode('<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"%23e5e7eb\"><path d=\"M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\"/></svg>'); ?>'">
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
    <main class="ml-64 mt-16 p-8">
        <div class="max-w-7xl mx-auto space-y-8">
            <?php if(isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline"> Your profile picture has been updated.</span>
            </div>
            <?php endif; ?>

            <?php if(!empty($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <div>
                    <strong class="font-bold">Success!</strong>
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if(!empty($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline"><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>
            
            <!-- Expiration Warning Banner -->
            <?php if ($expiration_warning): ?>
                <div class="mb-4 p-4 rounded-lg border <?php echo $expiring_soon ? 'bg-yellow-50 text-yellow-700 border-yellow-200' : 'bg-red-50 text-red-700 border-red-200'; ?>">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <i class="fas <?php echo $expiring_soon ? 'fa-exclamation-triangle' : 'fa-times-circle'; ?> mr-3 text-lg"></i>
                            <div>
                                <h4 class="font-semibold"><?php echo $expiration_warning; ?></h4>
                                <p class="text-sm mt-1">
                                    <?php if ($expiring_soon): ?>
                                        Renew your membership to continue enjoying our facilities and services.
                                    <?php else: ?>
                                        Please renew your membership to regain access to our facilities.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center space-x-3">
                            <a href="membership.php" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200 font-medium">
                                <i class="fas fa-crown mr-2"></i>Renew Membership
                            </a>
                            <?php if ($expiring_soon): ?>
                                <button onclick="dismissWarning()" class="px-3 py-2 text-yellow-600 hover:text-yellow-800 font-medium">
                                    <i class="fas fa-times mr-1"></i>Dismiss
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Floating Membership Status Indicator -->
            <?php if ($membership && $membership['days_remaining'] <= 7): ?>
                <div class="fixed top-24 right-6 z-50">
                    <div class="bg-white rounded-lg shadow-lg border-2 border-<?php echo $membership['days_remaining'] <= 3 ? 'red' : 'yellow'; ?>-400 p-4 max-w-xs">
                        <div class="flex items-center space-x-3">
                            <div class="w-3 h-3 bg-<?php echo $membership['days_remaining'] <= 3 ? 'red' : 'yellow'; ?>-500 rounded-full animate-pulse"></div>
                            <div class="flex-1">
                                <h5 class="font-semibold text-gray-800 text-sm">Membership Alert</h5>
                                <p class="text-xs text-gray-600">
                                    <?php if ($membership['days_remaining'] == 1): ?>
                                        Expires tomorrow!
                                    <?php elseif ($membership['days_remaining'] <= 3): ?>
                                        Expires in <?php echo $membership['days_remaining']; ?> days!
                                    <?php else: ?>
                                        Expires in <?php echo $membership['days_remaining']; ?> days!
                                    <?php endif; ?>
                                </p>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                        <div class="mt-3">
                            <a href="membership.php" class="block w-full text-center px-3 py-2 bg-red-600 text-white text-xs rounded hover:bg-red-700 transition-colors duration-200">
                                Renew Now
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Debug Information (remove in production) -->
            <?php if (isset($_GET['debug']) && $_GET['debug'] == '1'): ?>
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong class="font-bold">Debug Info:</strong>
                <div class="mt-2 text-sm">
                    <p>User ID: <?php echo $user_id; ?></p>
                    <p>Profile Picture in DB: <?php echo $user['profile_picture'] ?? 'NULL'; ?></p>
                    <p>Profile Picture Path: <?php echo !empty($user['profile_picture']) ? "../uploads/profile_pictures/" . $user['profile_picture'] : 'N/A'; ?></p>
                    <p>File Exists: <?php echo !empty($user['profile_picture']) && file_exists("../uploads/profile_pictures/" . $user['profile_picture']) ? 'YES' : 'NO'; ?></p>
                    <p>Upload Directory: <?php echo is_dir("../uploads/profile_pictures/") ? 'EXISTS' : 'MISSING'; ?></p>
                    <p>Upload Directory Writable: <?php echo is_writable("../uploads/profile_pictures/") ? 'YES' : 'NO'; ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Main Content Container -->
            <div class="flex flex-col gap-6">
                <!-- Profile Information Row -->
                <div class="flex flex-col lg:flex-row gap-6">
                    <!-- Left Column - Profile Picture and Demographics -->
                    <div class="flex-1 flex flex-col gap-6">
                        <!-- Profile Picture Box -->
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300 profile-picture-container">
                            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                <i class="fas fa-user-circle text-red-500 mr-2"></i>Profile Picture
                            </h3>
                            <div class="flex flex-col items-center">
                                <div class="relative mb-2">
                                    <?php 
                                    $profile_img_src = '';
                                    $has_profile_picture = false;
                                    
                                    if (!empty($user['profile_picture'])) {
                                        $profile_path = "../uploads/profile_pictures/" . $user['profile_picture'];
                                        if (file_exists($profile_path)) {
                                            $profile_img_src = $profile_path;
                                            $has_profile_picture = true;
                                        } else {
                                            // File doesn't exist, clear from database
                                            $clear_sql = "UPDATE users SET profile_picture = NULL WHERE id = ?";
                                            $clear_stmt = $conn->prepare($clear_sql);
                                            if ($clear_stmt) {
                                                $clear_stmt->bind_param("i", $user_id);
                                                $clear_stmt->execute();
                                            }
                                            $user['profile_picture'] = null;
                                        }
                                    }
                                    
                                    if (!$has_profile_picture) {
                                        // Use a data URI for a default avatar icon
                                        $profile_img_src = "data:image/svg+xml;base64," . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23e5e7eb"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>');
                                    }
                                    ?>
                                    <img src="<?php echo $profile_img_src; ?>" 
                                         alt="Profile Picture" 
                                         class="w-36 h-36 rounded-full object-cover border-2 border-white shadow-md ring-2 ring-red-200"
                                         onerror="this.src='data:image/svg+xml;base64,<?php echo base64_encode('<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 24 24\" fill=\"%23e5e7eb\"><path d=\"M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z\"/></svg>'); ?>'">
                                    
                                    <?php if ($has_profile_picture): ?>
                                    <div class="absolute -bottom-1 -right-1 h-6 w-6 bg-green-400 rounded-full border-2 border-white flex items-center justify-center shadow-sm">
                                        <i class="fas fa-check text-xs text-white"></i>
                                    </div>
                                    <?php else: ?>
                                    <div class="absolute -bottom-1 -right-1 h-6 w-6 bg-gray-400 rounded-full border-2 border-white flex items-center justify-center shadow-sm">
                                        <i class="fas fa-user text-xs text-white"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <form action="profile.php" method="POST" enctype="multipart/form-data" class="w-full" id="profilePictureForm" onsubmit="return handleProfilePictureUpload(event)">
                                    <div class="flex flex-col items-center">
                                        <!-- Preview container -->
                                        <div id="imagePreview" class="hidden mb-3 profile-picture-preview">
                                            <img id="previewImg" src="" alt="Preview" class="w-24 h-24 rounded-full object-cover border-2 border-gray-200">
                                        </div>
                                        
                                        <label class="w-auto flex items-center px-3 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-md shadow-sm tracking-wide uppercase border border-red-600 cursor-pointer hover:from-red-600 hover:to-red-700 transition-all duration-300 transform hover:scale-105 profile-picture-upload-label">
                                            <i class="fas fa-cloud-upload-alt mr-2 text-xs"></i>
                                            <span class="text-xs font-bold leading-normal">Choose a file</span>
                                            <input type="file" name="profile_picture" id="profilePictureInput" class="hidden" 
                                                   accept=".jpg,.jpeg,.png,.gif"
                                                   onchange="previewImage(this)">
                                        </label>
                                        
                                        <!-- Upload button (initially hidden) -->
                                        <button type="submit" id="uploadBtn" class="hidden mt-2 px-4 py-2 bg-green-600 text-white text-xs font-bold rounded-md hover:bg-green-700 transition-all duration-300">
                                            <i class="fas fa-upload mr-1"></i>Upload Picture
                                        </button>
                                        
                                        <!-- Cancel button (initially hidden) -->
                                        <button type="button" id="cancelBtn" class="hidden mt-2 px-4 py-2 bg-gray-500 text-white text-xs font-bold rounded-md hover:bg-gray-600 transition-all duration-300" onclick="resetProfilePictureForm()">
                                            <i class="fas fa-times mr-1"></i>Cancel
                                        </button>
                                        
                                        <p class="text-xs text-gray-500 mt-1 font-medium">Supported formats: JPG, PNG, GIF (Max: 5MB)</p>
                                        

                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- QR Code Section -->
                        <?php if ($show_qr): ?>
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                            <div class="flex items-center justify-between mb-3">
                                <h2 class="text-lg font-bold text-gray-800 flex items-center">
                                    <i class="fas fa-qrcode text-blue-500 mr-2"></i>Attendance QR Code
                                </h2>
                                <button onclick="refreshQRCode()" class="text-sm text-blue-500 hover:text-blue-600 transition-colors duration-200 font-medium">
                                    <i class="fas fa-sync-alt mr-2"></i>Refresh QR Code
                                </button>
                            </div>
                            
                            <div class="flex flex-col items-center justify-center space-y-3">
                                <div id="qrCodeContainer" class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg border border-blue-200 shadow-sm">
                                    <?php if (!empty($user) && !empty($user['qr_code']) && file_exists("../uploads/qr_codes/" . $user['qr_code'])): ?>
                                        <img src="../uploads/qr_codes/<?php echo htmlspecialchars($user['qr_code']); ?>?t=<?php echo time(); ?>" 
                                             alt="Attendance QR Code" class="w-48 h-48">
                                    <?php else: ?>
                                        <div class="w-48 h-48 flex items-center justify-center bg-white rounded-md shadow-sm">
                                            <p class="text-gray-500 text-center text-xs font-medium">No QR code available.<br>Click refresh to generate one.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-gray-600 font-medium text-center">
                                    <i class="fas fa-shield-alt text-blue-500 mr-2"></i>
                                    This QR code refreshes automatically every 5 minutes for security.
                                    <br>Show this to the staff at the entrance to record your attendance.
                                </p>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                            <div class="flex items-center justify-between mb-6">
                                <h2 class="text-xl font-semibold text-gray-800">
                                    <i class="fas fa-qrcode text-gray-400 mr-3"></i>Attendance QR Code
                                </h2>
                                <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-sm font-medium rounded-full">
                                    Not Available
                                </span>
                            </div>
                            
                            <div class="flex flex-col items-center justify-center space-y-4">
                                <div class="bg-gray-50 p-8 rounded-lg text-center">
                                    <i class="fas fa-exclamation-circle text-yellow-500 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-800 mb-2">QR Code Not Available</h3>
                                    <p class="text-gray-600 mb-4">
                                        QR code attendance is only available for members with active monthly or annual membership plans.
                                        <?php if (!$membership): ?>
                                        <br>You currently don't have an active membership plan.
                                        <?php else: ?>
                                        <br>Your current plan is for daily access only.
                                        <?php endif; ?>
                                    </p>
                                    <a href="membership.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 transition-colors duration-200">
                                        <i class="fas fa-crown mr-2"></i>View Membership Plans
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Demographics Box -->
                        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 hover:shadow-2xl transition-all duration-300">
                            <div class="flex justify-between items-center mb-6">
                                <div class="flex items-center">
                                    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                                        <i class="fas fa-user text-red-500 mr-3"></i>Demographics
                                    </h3>
                                    <?php 
                                    $demographicsComplete = !empty($user['full_name']) && !empty($user['mobile_number']) && 
                                                          !empty($user['gender']) && !empty($user['date_of_birth']) && 
                                                          !empty($user['home_address']);
                                    ?>
                                    <span class="ml-3 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $demographicsComplete ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <i class="fas <?php echo $demographicsComplete ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-1"></i>
                                        <?php echo $demographicsComplete ? 'Complete' : 'Incomplete'; ?>
                                    </span>
                                </div>
                                <button onclick="toggleEdit('demographics')" class="text-red-600 hover:text-red-700 font-medium transition-colors duration-200">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </button>
                            </div>
                            
                            <!-- View Mode -->
                            <div id="demographics-view" class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600">Full Name</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['full_name'])): ?>
                                                <?php echo htmlspecialchars($user['full_name']); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Mobile Number</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['mobile_number'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($user['mobile_number']); ?>" 
                                                   class="text-red-600 hover:text-red-700 transition-colors duration-200">
                                                    <?php echo htmlspecialchars($user['mobile_number']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Gender</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['gender'])): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    <?php echo ucfirst(htmlspecialchars($user['gender'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Date of Birth</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['date_of_birth'])): ?>
                                                <?php echo date('F d, Y', strtotime($user['date_of_birth'])); ?>
                                                <span class="text-xs text-gray-500 ml-2">
                                                    (<?php echo date_diff(date_create($user['date_of_birth']), date_create('today'))->y; ?> years old)
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-span-2">
                                        <p class="text-sm text-gray-600">Home Address</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['home_address'])): ?>
                                                <?php echo nl2br(htmlspecialchars($user['home_address'])); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <div id="demographics-edit" class="hidden">
                                <form id="demographics-form" class="space-y-4">
                                    <input type="hidden" name="update_type" value="demographics">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Full Name <span class="text-red-500">*</span></label>
                                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                                                   required
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
                                            <p class="text-xs text-gray-500 mt-1">Enter your full legal name</p>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Mobile Number <span class="text-red-500">*</span></label>
                                            <input type="tel" name="mobile_number" value="<?php echo htmlspecialchars($user['mobile_number'] ?? ''); ?>" 
                                                   required pattern="[0-9+\-\s()]+"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
                                            <p class="text-xs text-gray-500 mt-1">Format: 09123456789 or +63 912 345 6789</p>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Gender <span class="text-red-500">*</span></label>
                                            <select name="gender" required
                                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
                                                <option value="">Select Gender</option>
                                                <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="other" <?php echo ($user['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Date of Birth <span class="text-red-500">*</span></label>
                                            <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>" 
                                                   required max="<?php echo date('Y-m-d'); ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
                                            <p class="text-xs text-gray-500 mt-1">Must be 13 years or older</p>
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-600 mb-1">Home Address <span class="text-red-500">*</span></label>
                                            <textarea name="home_address" required rows="3"
                                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200"
                                                      placeholder="Enter your complete home address"><?php echo htmlspecialchars($user['home_address'] ?? ''); ?></textarea>
                                            <p class="text-xs text-gray-500 mt-1">Include street, city, province, and postal code</p>
                                        </div>
                                    </div>
                                    <div class="flex justify-end space-x-2 mt-6">
                                        <button type="button" onclick="toggleEdit('demographics')" 
                                                class="px-6 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors duration-200">
                                            <i class="fas fa-times mr-2"></i>Cancel
                                        </button>
                                        <button type="submit" 
                                                class="px-6 py-2 text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors duration-200">
                                            <i class="fas fa-save mr-2"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Emergency Contact Box -->
                        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 hover:shadow-2xl transition-all duration-300">
                            <div class="flex justify-between items-center mb-6">
                                <div class="flex items-center">
                                    <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                                        <i class="fas fa-phone-alt text-red-500 mr-3"></i>Emergency Contact
                                    </h3>
                                    <?php 
                                    $emergencyComplete = !empty($user['emergency_contact_name']) && !empty($user['emergency_contact_number']) && 
                                                       !empty($user['emergency_contact_relationship']);
                                    ?>
                                    <span class="ml-3 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $emergencyComplete ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <i class="fas <?php echo $emergencyComplete ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-1"></i>
                                        <?php echo $emergencyComplete ? 'Complete' : 'Incomplete'; ?>
                                    </span>
                                </div>
                                <button onclick="toggleEdit('emergency')" class="text-red-600 hover:text-red-700 font-medium transition-colors duration-200">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </button>
                            </div>

                            <!-- View Mode -->
                            <div id="emergency-view" class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600">Contact Name</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['emergency_contact_name'])): ?>
                                                <?php echo htmlspecialchars($user['emergency_contact_name']); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Contact Number</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['emergency_contact_number'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($user['emergency_contact_number']); ?>" 
                                                   class="text-red-600 hover:text-red-700 transition-colors duration-200">
                                                    <?php echo htmlspecialchars($user['emergency_contact_number']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="col-span-2">
                                        <p class="text-sm text-gray-600">Relationship</p>
                                        <p class="font-medium text-gray-800">
                                            <?php if (!empty($user['emergency_contact_relationship'])): ?>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                    <?php echo ucfirst(htmlspecialchars($user['emergency_contact_relationship'])); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-gray-400 italic">Not set</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <div id="emergency-edit" class="hidden">
                                <form id="emergency-form" class="space-y-4">
                                    <input type="hidden" name="update_type" value="emergency_contact">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Contact Name <span class="text-red-500">*</span></label>
                                            <input type="text" name="emergency_contact_name" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>" 
                                                   required
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200"
                                                   placeholder="Enter emergency contact's full name">
                                            <p class="text-xs text-gray-500 mt-1">Full name of your emergency contact</p>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Contact Number <span class="text-red-500">*</span></label>
                                            <input type="tel" name="emergency_contact_number" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_number'] ?? ''); ?>" 
                                                   required pattern="[0-9+\-\s()]+"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200"
                                                   placeholder="09123456789">
                                            <p class="text-xs text-gray-500 mt-1">Mobile or landline number</p>
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-600 mb-1">Relationship <span class="text-red-500">*</span></label>
                                            <select name="emergency_contact_relationship" required
                                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-200">
                                                <option value="">Select Relationship</option>
                                                <option value="spouse" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'spouse' ? 'selected' : ''; ?>>Spouse</option>
                                                <option value="parent" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'parent' ? 'selected' : ''; ?>>Parent</option>
                                                <option value="sibling" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'sibling' ? 'selected' : ''; ?>>Sibling</option>
                                                <option value="child" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'child' ? 'selected' : ''; ?>>Child</option>
                                                <option value="friend" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'friend' ? 'selected' : ''; ?>>Friend</option>
                                                <option value="relative" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'relative' ? 'selected' : ''; ?>>Relative</option>
                                                <option value="other" <?php echo ($user['emergency_contact_relationship'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                            <p class="text-xs text-gray-500 mt-1">Your relationship to this person</p>
                                        </div>
                                    </div>
                                    <div class="flex justify-end space-x-2 mt-6">
                                        <button type="button" onclick="toggleEdit('emergency')" 
                                                class="px-6 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors duration-200">
                                            <i class="fas fa-times mr-2"></i>Cancel
                                        </button>
                                        <button type="submit" 
                                                class="px-6 py-2 text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors duration-200">
                                            <i class="fas fa-save mr-2"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Membership Stats Box -->
                        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 hover:shadow-2xl transition-all duration-300">
                            <h3 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                                <i class="fas fa-crown text-yellow-500 mr-3"></i>Membership Information
                            </h3>
                            <div class="space-y-4">
                                <!-- Active Membership -->
                                <?php if ($membership && $membership['payment_status'] === 'Approved'): ?>
                                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 border border-green-200 shadow-md hover:shadow-lg transition-all duration-300">
                                    <div class="flex items-center mb-4">
                                        <div class="p-3 rounded-full bg-green-500 text-white mr-3">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <h4 class="text-xl font-bold text-gray-800">Active Membership</h4>
                                    </div>
                                    <div class="space-y-3 text-sm">
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Plan:</span>
                                            <span class="font-bold text-green-600"><?php echo htmlspecialchars($membership['plan_name']); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Status:</span>
                                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold">
                                                Active
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Price:</span>
                                            <span class="font-bold text-green-600">₱<?php echo number_format($membership['plan_price'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Duration:</span>
                                            <span class="font-bold text-gray-800"><?php echo $membership['duration']; ?> days</span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Started:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($membership['payment_date'])); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Expires:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($membership['expiry_date'])); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Days Remaining:</span>
                                            <span class="font-bold text-<?php echo $membership['days_remaining'] <= 7 ? 'red' : 'green'; ?>-600">
                                                <?php echo $membership['days_remaining']; ?> days
                                                <?php if ($membership['days_remaining'] <= 7): ?>
                                                    <i class="fas fa-exclamation-triangle text-yellow-500 ml-2"></i>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        
                                        <!-- Membership Progress Bar -->
                                        <div class="mt-3">
                                            <div class="flex justify-between items-center text-xs text-gray-500 mb-1">
                                                <span>Membership Progress</span>
                                                <span><?php echo round((($membership['duration'] - $membership['days_remaining']) / $membership['duration']) * 100); ?>% used</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <?php 
                                                $progress_percentage = ($membership['duration'] - $membership['days_remaining']) / $membership['duration'] * 100;
                                                $progress_color = $membership['days_remaining'] <= 7 ? 'bg-red-500' : ($membership['days_remaining'] <= 14 ? 'bg-yellow-500' : 'bg-green-500');
                                                ?>
                                                <div class="h-2 rounded-full transition-all duration-300 <?php echo $progress_color; ?>" 
                                                     style="width: <?php echo min(100, max(0, $progress_percentage)); ?>%"></div>
                                            </div>
                                        </div>
                                        

                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Selected Plan (Pending) -->
                                <?php if (!empty($user['plan_name']) && $user['payment_status'] !== 'Approved'): ?>
                                <div class="border-l-4 border-blue-500 pl-4">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-clock text-blue-500 mr-2"></i>
                                        <h4 class="font-semibold text-gray-800">Selected Plan (Pending)</h4>
                                    </div>
                                    <div class="space-y-2 text-sm">
                                        <p class="text-gray-600">Plan: <span class="font-medium"><?php echo htmlspecialchars($user['plan_name']); ?></span></p>
                                        <p class="text-gray-600">Status: 
                                            <span class="px-2 py-1 rounded-full text-xs bg-yellow-100 text-yellow-800">
                                                Payment Pending
                                            </span>
                                        </p>
                                        <p class="text-gray-600">Price: <span class="font-medium">₱<?php echo number_format($user['plan_price'] ?? 0, 2); ?></span></p>
                                        <p class="text-gray-600">Duration: <span class="font-medium"><?php echo $user['plan_duration'] ?? 0; ?> days</span></p>
                                        <a href="payment.php?plan_id=<?php echo $user['selected_plan_id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm">Complete Payment</a>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- No Active Plan -->
                                <?php if (empty($user['plan_name']) && empty($user['selected_plan_id'])): ?>
                                <div class="border-l-4 border-gray-400 pl-4">
                                    <div class="flex items-center mb-2">
                                        <i class="fas fa-info-circle text-gray-400 mr-2"></i>
                                        <h4 class="font-semibold text-gray-800">No Active Membership</h4>
                                    </div>
                                    <p class="text-gray-600 text-sm">You don't have an active membership plan. <a href="membership.php" class="text-blue-600 hover:text-blue-800">Choose a plan</a> to get started.</p>
                                </div>
                                <?php endif; ?>

                                <!-- Additional Stats -->
                                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 border border-blue-200 shadow-md hover:shadow-lg transition-all duration-300 mt-6">
                                    <div class="flex items-center mb-4">
                                        <div class="p-3 rounded-full bg-blue-500 text-white mr-3">
                                            <i class="fas fa-chart-bar"></i>
                                        </div>
                                        <h4 class="text-xl font-bold text-gray-800">Additional Information</h4>
                                    </div>
                                    <div class="space-y-3 text-sm">
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Monthly Visits:</span>
                                            <span class="font-bold text-blue-600"><?php echo isset($monthly_visits) ? $monthly_visits . ' times' : '0 times'; ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Completed Payments:</span>
                                            <span class="font-bold text-blue-600"><?php echo $user['completed_payments'] ?? 0; ?></span>
                                        </div>
                                        <?php if (isset($user['last_payment_date']) && $user['last_payment_date']): ?>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Last Payment:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($user['last_payment_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Fitness Analytics -->
                    <div class="flex-1 flex flex-col gap-6">
                        <!-- Fitness Analytics Box -->
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                <i class="fas fa-chart-line text-blue-500 mr-2"></i>Fitness Analytics
                            </h3>
                            
                            <!-- Engagement Score -->
                            <div class="mb-4">
                                <h4 class="text-base font-bold text-gray-700 mb-2 flex items-center">
                                    <i class="fas fa-target text-blue-500 mr-2"></i>Engagement Level
                                </h4>
                                <div class="relative pt-1">
                                    <div class="flex mb-2 items-center justify-between">
                                        <div>
                                            <span class="text-xs font-bold inline-block py-1 px-3 uppercase rounded-full shadow-sm <?php echo $member_analytics['engagement_level']['score'] >= 80 ? 'text-green-600 bg-green-100 border border-green-200' : ($member_analytics['engagement_level']['score'] >= 60 ? 'text-blue-600 bg-blue-100 border border-blue-200' : 'text-yellow-600 bg-yellow-100 border border-yellow-200'); ?>">
                                                <?php echo $member_analytics['engagement_level']['level']; ?>
                                            </span>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-base font-bold inline-block text-gray-700">
                                                <?php echo $member_analytics['engagement_level']['score']; ?>%
                                            </span>
                                        </div>
                                    </div>
                                    <div class="overflow-hidden h-3 mb-4 text-xs flex rounded-full bg-gray-200 shadow-inner">
                                        <div style="width:<?php echo $member_analytics['engagement_level']['score']; ?>%" 
                                             class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center rounded-full <?php echo $member_analytics['engagement_level']['score'] >= 80 ? 'bg-gradient-to-r from-green-400 to-green-600' : ($member_analytics['engagement_level']['score'] >= 60 ? 'bg-gradient-to-r from-blue-400 to-blue-600' : 'bg-gradient-to-r from-yellow-400 to-yellow-600'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Activity Stats -->
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-md p-3 border border-blue-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex items-center mb-1">
                                        <div class="p-1 rounded-full bg-blue-500 text-white mr-2">
                                            <i class="fas fa-running text-xs"></i>
                                        </div>
                                        <h5 class="text-sm font-bold text-gray-700">Activity Level</h5>
                                    </div>
                                    <p class="text-lg font-bold text-blue-600"><?php echo $member_analytics['activity_level']; ?></p>
                                </div>
                                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-md p-3 border border-green-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex items-center mb-1">
                                        <div class="p-1 rounded-full bg-green-500 text-white mr-2">
                                            <i class="fas fa-chart-line text-xs"></i>
                                        </div>
                                        <h5 class="text-sm font-bold text-gray-700">Consistency Score</h5>
                                    </div>
                                    <p class="text-lg font-bold text-green-600"><?php echo round($member_analytics['consistency_score']); ?>%</p>
                                </div>
                            </div>
                        </div>

                        <!-- Fitness Goals Box -->
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                                    <i class="fas fa-dumbbell text-red-500 mr-2"></i>Fitness Goals
                                </h3>
                                <button onclick="openBMIModal()" class="bg-blue-600 text-white px-3 py-1 rounded-md hover:bg-blue-700 transition-colors text-sm font-medium">
                                    <i class="fas fa-plus mr-1"></i>Add Fitness Data
                                </button>
                            </div>
                            

                            
                            <?php 
                            try {
                                $fitness_goals = $predictive_analysis->getFitnessGoalsRecommendations();
                            } catch (Exception $e) {
                                error_log("Error getting fitness goals recommendations: " . $e->getMessage());
                                $fitness_goals = [];
                            }
                            if (!empty($fitness_goals)): 
                            ?>
                                <?php if (isset($fitness_goals['bmi'])): ?>
                                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-md p-3 mb-3 border border-purple-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-purple-500 text-white mr-2">
                                                <i class="fas fa-weight text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">BMI Status</h5>
                                        </div>
                                        <span class="text-sm font-bold text-purple-600"><?php echo $fitness_goals['bmi']['current']; ?></span>
                                    </div>
                                    <p class="text-sm font-bold text-gray-800 mb-2"><?php echo $fitness_goals['bmi']['category']; ?></p>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['bmi']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-purple-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (isset($fitness_goals['weight'])): ?>
                                <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-md p-3 mb-3 border border-orange-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-orange-500 text-white mr-2">
                                                <i class="fas fa-balance-scale text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">Weight Progress</h5>
                                        </div>
                                        <span class="text-sm font-bold text-orange-600">
                                            <?php echo $fitness_goals['weight']['current']; ?> kg / 
                                            <?php echo $fitness_goals['weight']['target']; ?> kg
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['weight']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-orange-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (isset($fitness_goals['body_composition']) && !empty($fitness_goals['body_composition'])): ?>
                                <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-md p-3 mb-3 border border-emerald-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-emerald-500 text-white mr-2">
                                                <i class="fas fa-chart-pie text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">Body Composition</h5>
                                        </div>
                                        <span class="text-xs font-bold text-emerald-600">
                                            Fat: <?php echo $fitness_goals['body_composition']['body_fat']; ?>% | 
                                            Muscle: <?php echo $fitness_goals['body_composition']['muscle_mass']; ?>%
                                        </span>
                                    </div>
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-gray-700"><?php echo $fitness_goals['body_composition']['analysis']['body_fat_status']; ?></p>
                                        <p class="text-xs font-medium text-gray-700"><?php echo $fitness_goals['body_composition']['analysis']['muscle_status']; ?></p>
                                    </div>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['body_composition']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-emerald-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (isset($fitness_goals['measurements']) && !empty($fitness_goals['measurements'])): ?>
                                <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 rounded-md p-3 mb-3 border border-cyan-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-cyan-500 text-white mr-2">
                                                <i class="fas fa-ruler-horizontal text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">Body Measurements</h5>
                                        </div>
                                        <span class="text-xs font-bold text-cyan-600">
                                            WHR: <?php echo $fitness_goals['measurements']['waist_hip_ratio']; ?>
                                        </span>
                                    </div>
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-gray-700">
                                            Waist: <?php echo $fitness_goals['measurements']['waist']; ?>cm | 
                                            Hip: <?php echo $fitness_goals['measurements']['hip']; ?>cm
                                        </p>
                                        <p class="text-xs font-medium text-gray-700"><?php echo $fitness_goals['measurements']['health_risk']; ?></p>
                                    </div>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['measurements']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-cyan-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if (isset($fitness_goals['training']) && !empty($fitness_goals['training'])): ?>
                                <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-md p-3 mb-3 border border-indigo-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-indigo-500 text-white mr-2">
                                                <i class="fas fa-trophy text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">Training Progress</h5>
                                        </div>
                                        <span class="text-xs font-bold text-indigo-600"><?php echo ucfirst($fitness_goals['training']['current_level']); ?></span>
                                    </div>
                                    <div class="mb-2">
                                        <p class="text-xs font-medium text-gray-700">
                                            Frequency: <?php echo $fitness_goals['training']['frequency']; ?> times/week
                                        </p>
                                        <p class="text-xs font-medium text-gray-700">
                                            Next Goal: <?php echo $fitness_goals['training']['progression']['next_step']; ?>
                                        </p>
                                    </div>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['training']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-indigo-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>


                            <?php else: ?>
                                <!-- BMI Setup Prompt -->
                                <?php if (!$current_height || !$current_weight): ?>
                                <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-4 mb-4 border border-blue-200 shadow-sm">
                                    <div class="text-center mb-3">
                                        <i class="fas fa-calculator text-3xl text-blue-400 mb-2"></i>
                                        <h4 class="text-lg font-medium text-gray-700">Set Your BMI Data</h4>
                                        <p class="text-sm text-gray-500">Start by entering your height and weight to calculate your BMI</p>
                                    </div>
                                    <div class="text-center">
                                        <button onclick="openBMIModal()" class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 transition-colors font-medium">
                                            <i class="fas fa-plus mr-2"></i>Add Your Fitness Data
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Fitness Goals Setup Form -->
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <div class="text-center mb-4">
                                        <i class="fas fa-dumbbell text-4xl text-gray-400 mb-2"></i>
                                        <h4 class="text-lg font-medium text-gray-700">Set Your Fitness Goals</h4>
                                        <p class="text-sm text-gray-500">Help us provide personalized recommendations</p>
                                    </div>
                                    
                                    <form action="update_fitness_data.php" method="POST" class="space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label>
                                                <input type="number" name="height" placeholder="170" value="<?php echo $user['height'] ?? ''; ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Current Weight (kg)</label>
                                                <input type="number" name="weight" placeholder="70" step="0.1" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Target Weight (kg)</label>
                                                <input type="number" name="target_weight" placeholder="65" step="0.1" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Fitness Goal</label>
                                                <select name="fitness_goal" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                                    <option value="">Select a goal</option>
                                                    <option value="weight_loss" <?php echo ($user['fitness_goal'] == 'weight_loss') ? 'selected' : ''; ?>>Weight Loss</option>
                                                    <option value="muscle_gain" <?php echo ($user['fitness_goal'] == 'muscle_gain') ? 'selected' : ''; ?>>Muscle Gain</option>
                                                    <option value="endurance" <?php echo ($user['fitness_goal'] == 'endurance') ? 'selected' : ''; ?>>Endurance</option>
                                                    <option value="flexibility" <?php echo ($user['fitness_goal'] == 'flexibility') ? 'selected' : ''; ?>>Flexibility</option>
                                                    <option value="general_fitness" <?php echo ($user['fitness_goal'] == 'general_fitness') ? 'selected' : ''; ?>>General Fitness</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Experience Level</label>
                                                <select name="experience_level" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                                    <option value="">Select level</option>
                                                    <option value="beginner">Beginner</option>
                                                    <option value="intermediate">Intermediate</option>
                                                    <option value="advanced">Advanced</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 mb-1">Preferred Workout Type</label>
                                                <select name="preferred_workout_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-red-500">
                                                    <option value="">Select type</option>
                                                    <option value="cardio">Cardio</option>
                                                    <option value="strength_training">Strength Training</option>
                                                    <option value="flexibility">Flexibility</option>
                                                    <option value="mixed">Mixed</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div class="text-center pt-4">
                                            <button type="submit" class="bg-red-600 text-white px-6 py-2 rounded-md hover:bg-red-700 transition-colors">
                                                Save Fitness Goals
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Workout Plan Box -->
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                <i class="fas fa-dumbbell text-purple-500 mr-2"></i>Recommendations
                            </h3>
                            
                            <?php 
                            // Get BMI-based exercise recommendations with validation
                            $current_height = isset($user['height']) && is_numeric($user['height']) && $user['height'] > 0 ? $user['height'] : null;
                            $current_weight = isset($user['weight']) && is_numeric($user['weight']) && $user['weight'] > 0 ? $user['weight'] : null;
                            $has_bmi_data = $current_height && $current_weight && $current_height >= 100 && $current_height <= 250 && $current_weight >= 30 && $current_weight <= 300;
                            
                            // DEBUG: Show BMI data status with validation
                            echo "<!-- DEBUG: Height=$current_height, Weight=$current_weight, Has BMI Data=".($has_bmi_data ? 'YES' : 'NO')." -->";
                            
                            if ($has_bmi_data) {
                                // Calculate BMI and provide realistic recommendations
                                $height_m = $current_height / 100;
                                $current_bmi = $current_weight / ($height_m * $height_m);
                                
                                // Calculate BMI category for display
                                if ($current_bmi < 18.5) {
                                    $bmi_category = 'Underweight';
                                } elseif ($current_bmi < 25) {
                                    $bmi_category = 'Normal Weight';
                                } elseif ($current_bmi < 30) {
                                    $bmi_category = 'Overweight';
                                } elseif ($current_bmi < 35) {
                                    $bmi_category = 'Obese Class I';
                                } elseif ($current_bmi < 40) {
                                    $bmi_category = 'Obese Class II';
                                } else {
                                    $bmi_category = 'Obese Class III';
                                }
                                
                                // BMI-based exercise programs
                                if ($current_bmi < 18.5) {
                                    echo "<!-- DEBUG: BMI=$current_bmi - UNDERWEIGHT CATEGORY -->";
                                    $workout_recommendations = [
                                        'workout_plan' => ['frequency' => '3-4 days per week', 'duration' => '45-60 minutes'],
                                        'exercise_types' => [
                                            'primary' => ['Underweight Strength Program'],
                                            'secondary' => ['Compound Strength Training', 'Progressive Overload'],
                                            'supplementary' => ['Muscle Building Focus', 'Nutrition Focus']
                                        ],
                                        'personalized_tips' => [
                                            'Focus on compound movements for maximum muscle engagement',
                                            'Eat 300-500 calories above maintenance daily',
                                            'Prioritize strength training over cardio',
                                            'Get adequate protein (1.6-2.2g per kg body weight)',
                                            'Allow 48-72 hours between muscle group training'
                                        ]
                                    ];
                                } elseif ($current_bmi < 25) {
                                    echo "<!-- DEBUG: BMI=$current_bmi - NORMAL WEIGHT CATEGORY -->";
                                    $workout_recommendations = [
                                        'workout_plan' => ['frequency' => '3-5 days per week', 'duration' => '30-60 minutes'],
                                        'exercise_types' => [
                                            'primary' => ['Normal Weight Balanced Program'],
                                            'secondary' => ['Balanced Strength Training', 'Moderate Cardio'],
                                            'supplementary' => ['Functional Training', 'Flexibility Work']
                                        ],
                                        'personalized_tips' => [
                                            'Maintain balanced approach to fitness',
                                            'Include variety in your routine',
                                            'Focus on functional movements',
                                            'Balance strength, cardio, and flexibility',
                                            'Set realistic, sustainable goals'
                                        ]
                                    ];
                                } elseif ($current_bmi < 30) {
                                    echo "<!-- DEBUG: BMI=$current_bmi - OVERWEIGHT CATEGORY -->";
                                    $workout_recommendations = [
                                        'workout_plan' => ['frequency' => '4-5 days per week', 'duration' => '30-45 minutes'],
                                        'exercise_types' => [
                                            'primary' => ['Overweight Weight Loss Program'],
                                            'secondary' => ['Low-Impact Cardio', 'Bodyweight Strength'],
                                            'supplementary' => ['Endurance Building', 'Mobility Work']
                                        ],
                                        'personalized_tips' => [
                                            'Create sustainable calorie deficit (500-750 calories)',
                                            'Focus on low-impact, joint-friendly exercises',
                                            'Build endurance gradually',
                                            'Include both cardio and strength training',
                                            'Celebrate non-scale victories'
                                        ]
                                    ];
                                } else {
                                    echo "<!-- DEBUG: BMI=$current_bmi - OBESE CATEGORY -->";
                                    $workout_recommendations = [
                                        'workout_plan' => ['frequency' => '3-4 days per week', 'duration' => '10-30 minutes'],
                                        'exercise_types' => [
                                            'primary' => ['Obese Safe Fitness Program'],
                                            'secondary' => ['Gentle Walking Program', 'Chair-Based Exercises'],
                                            'supplementary' => ['Low-Impact Movement', 'Breathing Exercises']
                                        ],
                                        'personalized_tips' => [
                                            'Start with what feels comfortable',
                                            'Focus on consistency over intensity',
                                            'Prioritize safety and joint health',
                                            'Work with healthcare professionals',
                                            'Build sustainable exercise habits'
                                        ]
                                    ];
                                }
                            } else {
                                // Fallback recommendations when no BMI data
                                echo "<!-- DEBUG: NO BMI DATA - USING FALLBACK -->";
                                $bmi_category = 'Not Available';
                                try {
                                    $workout_recommendations = $predictive_analysis->getWorkoutRecommendations();
                                } catch (Exception $e) {
                                    error_log("Error getting workout recommendations: " . $e->getMessage());
                                    $workout_recommendations = [
                                        'workout_plan' => ['frequency' => '3-4 days per week', 'duration' => '30-45 minutes'],
                                        'exercise_types' => [
                                            'primary' => ['Bodyweight Strength Training'],
                                            'secondary' => ['Low-Impact Cardio'],
                                            'supplementary' => ['Flexibility and Mobility Program']
                                        ],
                                        'personalized_tips' => [
                                            'Start with basic exercises and progress gradually',
                                            'Focus on proper form and technique',
                                            'Listen to your body and rest when needed',
                                            'Update your height and weight in Demographics for personalized BMI-based recommendations',
                                            'Consider consulting with our trainers for personalized guidance'
                                        ],
                                        'intensity_level' => [
                                            'intensity' => 'Low to Moderate',
                                            'heart_rate_zone' => '50-70% max HR',
                                            'rest_periods' => '60-90 seconds'
                                        ]
                                    ];
                                }
                            }
                            
                            // Get fitness goals for integration
                            $fitness_goal = $user['fitness_goal'] ?? null;
                            $experience_level = $user['experience_level'] ?? null;
                            ?>
                            
                            <!-- Quick Overview Card -->
                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 mb-4 border border-blue-200 shadow-sm">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center">
                                        <i class="fas fa-bullseye text-blue-600 mr-2"></i>
                                        <span class="text-sm font-medium text-blue-800">Quick Overview</span>
                                    </div>
                                    <?php if ($has_bmi_data): ?>
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs font-medium rounded-full border border-blue-200">
                                        BMI: <?php echo $bmi_category; ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 bg-amber-100 text-amber-800 text-xs font-medium rounded-full border border-amber-200 flex items-center">
                                        <i class="fas fa-info-circle mr-1"></i>Complete Profile for Better Recommendations
                                    </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                    <div class="bg-white rounded-lg p-3 shadow-sm border border-blue-200">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Frequency</p>
                                        <p class="text-sm font-bold text-blue-700"><?php echo $workout_recommendations['workout_plan']['frequency']; ?></p>
                                    </div>
                                    <div class="bg-white rounded-lg p-3 shadow-sm border border-blue-200">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Duration</p>
                                        <p class="text-sm font-bold text-blue-700"><?php echo $workout_recommendations['workout_plan']['duration']; ?></p>
                                    </div>
                                    <?php if ($fitness_goal): ?>
                                    <div class="bg-white rounded-lg p-3 shadow-sm border border-blue-200">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Goal</p>
                                        <p class="text-sm font-bold text-blue-700"><?php echo ucwords(str_replace('_', ' ', $fitness_goal)); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($experience_level): ?>
                                    <div class="bg-white rounded-lg p-3 shadow-sm border border-blue-200">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Level</p>
                                        <p class="text-sm font-bold text-blue-700"><?php echo ucfirst($experience_level); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Exercise Programs Summary -->
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4 mb-4 border border-purple-200 shadow-sm">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-dumbbell text-purple-600 mr-2"></i>
                                    <span class="text-sm font-medium text-purple-800">Recommended Exercise Programs</span>
                                </div>
                                
                                <div class="space-y-3">
                                    <!-- Primary Programs -->
                                    <div>
                                        <p class="text-xs text-gray-600 font-medium mb-2 flex items-center">
                                            <i class="fas fa-star text-blue-500 mr-1"></i>Primary Focus
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php if (isset($workout_recommendations['exercise_types']['primary']) && !empty($workout_recommendations['exercise_types']['primary'])): ?>
                                                <?php foreach ($workout_recommendations['exercise_types']['primary'] as $exercise): ?>
                                                <button onclick="showProfileExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')" 
                                                        class="inline-block bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs px-3 py-1.5 rounded-full font-medium shadow-sm transition-all duration-200 hover:shadow-md cursor-pointer border border-blue-200 hover:border-blue-300">
                                                    <i class="fas fa-info-circle mr-1 opacity-70"></i>
                                                    <?php echo htmlspecialchars($exercise); ?>
                                                </button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <!-- Fallback recommendations -->
                                                <button onclick="showProfileExerciseDetails('Bodyweight Strength Training')" 
                                                        class="inline-block bg-blue-100 hover:bg-blue-200 text-blue-800 text-xs px-3 py-1.5 rounded-full font-medium shadow-sm transition-all duration-200 hover:shadow-md cursor-pointer border border-blue-200 hover:border-blue-300">
                                                    <i class="fas fa-info-circle mr-1 opacity-70"></i>
                                                    General Fitness Program
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Secondary Programs -->
                                    <div>
                                        <p class="text-xs text-gray-600 font-medium mb-2 flex items-center">
                                            <i class="fas fa-plus text-green-500 mr-1"></i>Additional Focus
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php if (isset($workout_recommendations['exercise_types']['secondary']) && !empty($workout_recommendations['exercise_types']['secondary'])): ?>
                                                <?php foreach (array_slice($workout_recommendations['exercise_types']['secondary'], 0, 2) as $exercise): ?>
                                                <button onclick="showProfileExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')" 
                                                        class="inline-block bg-green-100 hover:bg-green-200 text-green-800 text-xs px-3 py-1.5 rounded-full font-medium shadow-sm transition-all duration-200 hover:shadow-md cursor-pointer border border-green-200 hover:border-green-300">
                                                    <i class="fas fa-info-circle mr-1 opacity-70"></i>
                                                    <?php echo htmlspecialchars($exercise); ?>
                                                </button>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <button onclick="showProfileExerciseDetails('Low-Impact Cardio')" 
                                                        class="inline-block bg-green-100 hover:bg-green-200 text-green-800 text-xs px-3 py-1.5 rounded-full font-medium shadow-sm transition-all duration-200 hover:shadow-md cursor-pointer border border-green-200 hover:border-green-300">
                                                    <i class="fas fa-info-circle mr-1 opacity-70"></i>
                                                    Cardio Training
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Key Tips Summary -->
                            <?php if (isset($workout_recommendations['personalized_tips']) && !empty($workout_recommendations['personalized_tips'])): ?>
                            <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-lg p-4 border border-amber-200 shadow-sm">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-lightbulb text-amber-600 mr-2"></i>
                                    <span class="text-sm font-medium text-amber-800">Key Tips for Success</span>
                                </div>
                                <div class="space-y-2">
                                    <?php foreach (array_slice($workout_recommendations['personalized_tips'], 0, 3) as $tip): ?>
                                    <div class="flex items-start text-sm text-amber-700">
                                        <i class="fas fa-check-circle text-amber-500 mr-2 mt-0.5 flex-shrink-0"></i>
                                        <?php echo htmlspecialchars($tip); ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- BMI Input Modal -->
    <div id="bmiModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden bmi-modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white modal-content">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center">
                        <i class="fas fa-calculator text-blue-500 mr-2"></i>Fitness Progress Tracker
                    </h3>
                    <button onclick="closeBMIModal()" class="text-gray-400 hover:text-gray-600 text-xl font-bold transition-colors duration-200">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form id="bmiForm" class="space-y-4">
                    <!-- Basic Measurements Section -->
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <h4 class="text-md font-semibold text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-ruler text-blue-500 mr-2"></i>Basic Measurements
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label>
                                <input type="number" id="bmiHeight" name="height" placeholder="170" 
                                       value="<?php echo $user['height'] ?? ''; ?>" 
                                       min="100" max="250"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200" required>
                                <p class="text-xs text-gray-500 mt-1">Range: 100-250 cm</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Weight (kg)</label>
                                <input type="number" id="bmiWeight" name="weight" placeholder="70" step="0.1" 
                                       min="30" max="300"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all duration-200" required>
                                <p class="text-xs text-gray-500 mt-1">Range: 30-300 kg</p>
                            </div>
                        </div>
                    </div>

                    <!-- Body Composition Section -->
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <h4 class="text-md font-semibold text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-percentage text-green-500 mr-2"></i>Body Composition
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Body Fat %</label>
                                <input type="number" id="bodyFat" name="body_fat" placeholder="20" step="0.1" 
                                       min="5" max="50"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-200">
                                <p class="text-xs text-gray-500 mt-1">Range: 5-50%</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Muscle Mass %</label>
                                <input type="number" id="muscleMass" name="muscle_mass" placeholder="40" step="0.1" 
                                       min="20" max="60"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 transition-all duration-200">
                                <p class="text-xs text-gray-500 mt-1">Range: 20-60%</p>
                            </div>
                        </div>
                    </div>

                    <!-- Circumference Measurements Section -->
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <h4 class="text-md font-semibold text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-circle text-purple-500 mr-2"></i>Circumference Measurements
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Waist (cm)</label>
                                <input type="number" id="waist" name="waist" placeholder="80" step="0.5" 
                                       min="50" max="200"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 transition-all duration-200">
                                <p class="text-xs text-gray-500 mt-1">Range: 50-200 cm</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hip (cm)</label>
                                <input type="number" id="hip" name="hip" placeholder="95" step="0.5" 
                                       min="60" max="250"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 transition-all duration-200">
                                <p class="text-xs text-gray-500 mt-1">Range: 60-250 cm</p>
                            </div>
                        </div>
                    </div>

                    <!-- Training Progress Section -->
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                        <h4 class="text-md font-semibold text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-dumbbell text-orange-500 mr-2"></i>Training Progress
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Current Training Level</label>
                                <select id="trainingLevel" name="training_level" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all duration-200">
                                    <option value="">Select level</option>
                                    <option value="beginner">Beginner (0-6 months)</option>
                                    <option value="intermediate">Intermediate (6 months - 2 years)</option>
                                    <option value="advanced">Advanced (2+ years)</option>
                                    <option value="elite">Elite (Competitive)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Training Frequency</label>
                                <select id="trainingFrequency" name="training_frequency" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all duration-200">
                                    <option value="">Select frequency</option>
                                    <option value="1-2">1-2 times per week</option>
                                    <option value="3-4">3-4 times per week</option>
                                    <option value="5-6">5-6 times per week</option>
                                    <option value="daily">Daily</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Training Notes</label>
                            <textarea id="trainingNotes" name="training_notes" placeholder="Enter any training progress notes, achievements, or goals..." 
                                      rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all duration-200"></textarea>
                        </div>
                    </div>
                    
                    <!-- BMI Calculation Result -->
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-4 border border-blue-200 shadow-sm">
                        <div class="text-center">
                            <p class="text-sm text-gray-600 mb-3">Your BMI will be calculated automatically</p>
                            <div id="bmiResult" class="hidden bmi-result">
                                <div class="bg-white rounded-lg p-3 shadow-sm mb-2">
                                    <p class="text-xl font-bold text-blue-600" id="bmiValue"></p>
                                </div>
                                <div class="bg-white rounded-lg p-2 shadow-sm">
                                    <p class="text-sm font-medium text-gray-700" id="bmiCategory"></p>
                                </div>
                            </div>
                            <div id="bmiError" class="hidden">
                                <p class="text-sm text-red-600 font-medium" id="bmiErrorMessage"></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-2 pt-4">
                        <button type="button" onclick="closeBMIModal()" 
                                class="px-4 py-2 text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors duration-200">
                            Cancel
                        </button>
                        <button type="submit" id="bmiSubmitBtn"
                                class="px-4 py-2 text-white bg-blue-600 rounded-md hover:bg-blue-700 transition-colors duration-200">
                            Save Fitness Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-4 right-4 bg-gray-800 text-white px-6 py-3 rounded-lg shadow-lg transform translate-y-full opacity-0 transition-all duration-300">
        <span id="toast-message"></span>
    </div>

    <script>
    function toggleEdit(section) {
        const viewElement = document.getElementById(`${section}-view`);
        const editElement = document.getElementById(`${section}-edit`);
        
        if (viewElement.classList.contains('hidden')) {
            viewElement.classList.remove('hidden');
            editElement.classList.add('hidden');
        } else {
            viewElement.classList.add('hidden');
            editElement.classList.remove('hidden');
        }
    }

    function showToast(message, isSuccess = true) {
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toast-message');
        
        toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg transform transition-all duration-300 ${
            isSuccess ? 'bg-green-600' : 'bg-red-600'
        } text-white`;
        
        toastMessage.textContent = message;
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
        
        setTimeout(() => {
            toast.style.transform = 'translateY(100%)';
            toast.style.opacity = '0';
        }, 3000);
    }

    // Handle form submissions
    document.getElementById('demographics-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (validateDemographicsForm()) {
            await submitForm(e.target);
        }
    });

    document.getElementById('emergency-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (validateEmergencyForm()) {
            await submitForm(e.target);
        }
    });

    // Form validation functions
    function validateDemographicsForm() {
        const form = document.getElementById('demographics-form');
        const fullName = form.querySelector('input[name="full_name"]').value.trim();
        const mobileNumber = form.querySelector('input[name="mobile_number"]').value.trim();
        const gender = form.querySelector('select[name="gender"]').value;
        const dateOfBirth = form.querySelector('input[name="date_of_birth"]').value;
        const homeAddress = form.querySelector('textarea[name="home_address"]').value.trim();

        // Clear previous error states
        clearFormErrors(form);

        let isValid = true;

        if (!fullName) {
            showFieldError(form.querySelector('input[name="full_name"]'), 'Full name is required');
            isValid = false;
        } else if (fullName.length < 2) {
            showFieldError(form.querySelector('input[name="full_name"]'), 'Full name must be at least 2 characters');
            isValid = false;
        }

        if (!mobileNumber) {
            showFieldError(form.querySelector('input[name="mobile_number"]'), 'Mobile number is required');
            isValid = false;
        } else if (!/^[0-9+\-\s()]{7,15}$/.test(mobileNumber)) {
            showFieldError(form.querySelector('input[name="mobile_number"]'), 'Please enter a valid mobile number');
            isValid = false;
        }

        if (!gender) {
            showFieldError(form.querySelector('select[name="gender"]'), 'Please select your gender');
            isValid = false;
        }

        if (!dateOfBirth) {
            showFieldError(form.querySelector('input[name="date_of_birth"]'), 'Date of birth is required');
            isValid = false;
        } else {
            const birthDate = new Date(dateOfBirth);
            const today = new Date();
            const age = today.getFullYear() - birthDate.getFullYear();
            if (age < 13) {
                showFieldError(form.querySelector('input[name="date_of_birth"]'), 'You must be at least 13 years old');
                isValid = false;
            }
        }

        if (!homeAddress) {
            showFieldError(form.querySelector('textarea[name="home_address"]'), 'Home address is required');
            isValid = false;
        } else if (homeAddress.length < 10) {
            showFieldError(form.querySelector('textarea[name="home_address"]'), 'Please provide a complete address');
            isValid = false;
        }

        return isValid;
    }

    function validateEmergencyForm() {
        const form = document.getElementById('emergency-form');
        const contactName = form.querySelector('input[name="emergency_contact_name"]').value.trim();
        const contactNumber = form.querySelector('input[name="emergency_contact_number"]').value.trim();
        const relationship = form.querySelector('select[name="emergency_contact_relationship"]').value;

        // Clear previous error states
        clearFormErrors(form);

        let isValid = true;

        if (!contactName) {
            showFieldError(form.querySelector('input[name="emergency_contact_name"]'), 'Contact name is required');
            isValid = false;
        } else if (contactName.length < 2) {
            showFieldError(form.querySelector('input[name="emergency_contact_name"]'), 'Contact name must be at least 2 characters');
            isValid = false;
        }

        if (!contactNumber) {
            showFieldError(form.querySelector('input[name="emergency_contact_number"]'), 'Contact number is required');
            isValid = false;
        } else if (!/^[0-9+\-\s()]{7,15}$/.test(contactNumber)) {
            showFieldError(form.querySelector('input[name="emergency_contact_number"]'), 'Please enter a valid contact number');
            isValid = false;
        }

        if (!relationship) {
            showFieldError(form.querySelector('select[name="emergency_contact_relationship"]'), 'Please select a relationship');
            isValid = false;
        }

        return isValid;
    }

    function showFieldError(field, message) {
        field.classList.add('border-red-500', 'focus:border-red-500', 'focus:ring-red-200');
        
        // Remove existing error message
        const existingError = field.parentNode.querySelector('.field-error');
        if (existingError) {
            existingError.remove();
        }
        
        // Add error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'field-error text-xs text-red-600 mt-1';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }

    function clearFormErrors(form) {
        const fields = form.querySelectorAll('input, select, textarea');
        fields.forEach(field => {
            field.classList.remove('border-red-500', 'focus:border-red-500', 'focus:ring-red-200');
        });
        
        const errors = form.querySelectorAll('.field-error');
        errors.forEach(error => error.remove());
    }

    async function submitForm(form) {
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        try {
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
            
            const formData = new FormData(form);
            const response = await fetch('update_profile.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast(result.message, true);
                // Close edit mode and refresh data
                const section = formData.get('update_type') === 'demographics' ? 'demographics' : 'emergency';
                toggleEdit(section);
                // Reload to show updated information
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(result.message, false);
            }
        } catch (error) {
            showToast('An error occurred while updating the profile', false);
        } finally {
            // Restore button state
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
        }
    }

    // Profile Picture Preview and Upload
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        const previewImg = document.getElementById('previewImg');
        const uploadBtn = document.getElementById('uploadBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const file = input.files[0];
        
        if (file) {
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                showToast('File size too large. Maximum size is 5MB.', false);
                input.value = '';
                return;
            }
            
            // Validate file type
            const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!validTypes.includes(file.type)) {
                showToast('Invalid file type. Please upload JPG, PNG, or GIF files only.', false);
                input.value = '';
                return;
            }
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                preview.classList.remove('hidden');
                uploadBtn.classList.remove('hidden');
                cancelBtn.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            preview.classList.add('hidden');
            uploadBtn.classList.add('hidden');
            cancelBtn.classList.add('hidden');
        }
    }
    
    function resetProfilePictureForm() {
        document.getElementById('imagePreview').classList.add('hidden');
        document.getElementById('uploadBtn').classList.add('hidden');
        document.getElementById('cancelBtn').classList.add('hidden');
        document.getElementById('profilePictureInput').value = '';
    }
    
    function handleProfilePictureUpload(event) {
        const uploadBtn = document.getElementById('uploadBtn');
        const originalText = uploadBtn.innerHTML;
        
        // Show loading state
        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Uploading...';
        uploadBtn.classList.remove('bg-green-600', 'hover:bg-green-700');
        uploadBtn.classList.add('bg-gray-500', 'cursor-not-allowed');
        
        // Show toast notification
        showToast('Uploading profile picture...', true);
        
        // Form will submit normally
        return true;
    }
    
    // QR Code Auto-refresh
    let qrRefreshInterval;
    
    function refreshQRCode() {
        const container = document.getElementById('qrCodeContainer');
        const button = document.querySelector('button[onclick="refreshQRCode()"]');
        
        // Show loading state
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Refreshing...';
        
        fetch('generate_qr_api.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update QR code image
                    container.innerHTML = `
                        <img src="../uploads/qr_codes/${data.filename}?t=${Date.now()}" 
                             alt="Attendance QR Code" class="w-64 h-64">
                    `;
                    
                    // Show success message
                    button.innerHTML = '<i class="fas fa-check mr-1"></i>Updated';
                    button.classList.add('text-green-500');
                    
                    // Reset button after 2 seconds
                    setTimeout(() => {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-sync-alt mr-1"></i>Refresh QR Code';
                        button.classList.remove('text-green-500');
                    }, 2000);
                    
                    // Reset auto-refresh interval
                    resetQRRefreshInterval();
                } else {
                    throw new Error(data.error || 'Failed to generate QR code');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                button.innerHTML = '<i class="fas fa-exclamation-circle mr-1"></i>Error';
                button.classList.add('text-red-500');
                
                setTimeout(() => {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-sync-alt mr-1"></i>Refresh QR Code';
                    button.classList.remove('text-red-500');
                }, 2000);
            });
    }
    
    function resetQRRefreshInterval() {
        // Clear existing interval
        if (qrRefreshInterval) {
            clearInterval(qrRefreshInterval);
        }
        
        // Set new interval (5 minutes)
        qrRefreshInterval = setInterval(refreshQRCode, 5 * 60 * 1000);
    }
    
    // Initialize auto-refresh when page loads
    document.addEventListener('DOMContentLoaded', () => {
        resetQRRefreshInterval();
    });
    
    // Clear interval when page is hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(qrRefreshInterval);
        } else {
            refreshQRCode(); // Refresh immediately when page becomes visible
            resetQRRefreshInterval();
        }
    });

    // Predictive Analysis Interactive Features
    document.addEventListener('DOMContentLoaded', function() {
        // Animate progress bars on scroll
        const observerOptions = {
            threshold: 0.5,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const progressBars = entry.target.querySelectorAll('.bg-blue-600, .bg-orange-600, .bg-green-500');
                    progressBars.forEach(bar => {
                        const width = bar.style.width;
                        bar.style.width = '0%';
                        setTimeout(() => {
                            bar.style.width = width;
                        }, 100);
                    });
                }
            });
        }, observerOptions);

        // Observe analytics section
        const analyticsSection = document.querySelector('.bg-white.rounded-xl.shadow-lg.border.border-gray-100.p-8');
        if (analyticsSection) {
            observer.observe(analyticsSection);
        }

        // Add hover effects to recommendation cards
        const recommendationCards = document.querySelectorAll('.bg-gradient-to-br');
        recommendationCards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
                this.style.boxShadow = '0 10px 25px rgba(0,0,0,0.1)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '';
            });
        });

        // Add tooltip functionality for decision factors
        const decisionFactors = document.querySelectorAll('.space-y-2.text-sm .flex.items-center.justify-between');
        decisionFactors.forEach(factor => {
            const value = factor.querySelector('.font-medium');
            if (value) {
                const tooltip = document.createElement('div');
                tooltip.className = 'absolute bg-gray-800 text-white text-xs rounded py-1 px-2 -mt-8 ml-2 opacity-0 pointer-events-none transition-opacity duration-200 z-10';
                tooltip.textContent = getTooltipText(value.textContent.trim());
                
                value.style.position = 'relative';
                value.appendChild(tooltip);
                
                value.addEventListener('mouseenter', () => {
                    tooltip.style.opacity = '1';
                });
                
                value.addEventListener('mouseleave', () => {
                    tooltip.style.opacity = '0';
                });
            }
        });

        // Add click-to-expand functionality for suggestions
        const suggestionLists = document.querySelectorAll('.space-y-2.text-sm');
        suggestionLists.forEach(list => {
            const items = list.querySelectorAll('li');
            if (items.length > 2) {
                // Hide items beyond the first 2
                items.forEach((item, index) => {
                    if (index >= 2) {
                        item.style.display = 'none';
                    }
                });
                
                // Add "Show More" button
                const showMoreBtn = document.createElement('button');
                showMoreBtn.className = 'text-teal-600 hover:text-teal-700 text-sm font-medium mt-2';
                showMoreBtn.textContent = 'Show More';
                showMoreBtn.addEventListener('click', function() {
                    items.forEach(item => {
                        item.style.display = 'flex';
                    });
                    this.style.display = 'none';
                });
                
                list.appendChild(showMoreBtn);
            }
        });
    });

    // Helper function for tooltip text
    function getTooltipText(value) {
        const tooltips = {
            'Very Active': '15+ visits in the last 30 days',
            'Active': '10-14 visits in the last 30 days',
            'Moderate': '5-9 visits in the last 30 days',
            'Light': '2-4 visits in the last 30 days',
            'Inactive': '0-1 visits in the last 30 days',
            'Elite': '90%+ engagement score',
            'Highly Engaged': '80-89% engagement score',
            'Engaged': '70-79% engagement score',
            'Moderate': '50-69% engagement score',
            'Light': '30-49% engagement score',
            'New': '0-29% engagement score'
        };
        
        return tooltips[value] || 'Click for more details';
    }

    // Analytics refresh function
    function refreshAnalytics() {
        const refreshBtn = document.querySelector('[onclick="refreshAnalytics()"]');
        if (refreshBtn) {
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Refreshing...';
            refreshBtn.disabled = true;
            
            // Simulate refresh (in real implementation, this would make an AJAX call)
            setTimeout(() => {
                location.reload();
            }, 1500);
        }
    }

    // Export analytics data
    function exportAnalytics() {
        const analyticsData = {
            engagement_level: '<?php echo $member_analytics['engagement_level']['level']; ?>',
            activity_level: '<?php echo $member_analytics['activity_level']; ?>',
            consistency_score: '<?php echo round($member_analytics['consistency_score']); ?>%',
            recommended_plan: '<?php echo isset($plan_recommendations[0]['plan']['name']) ? htmlspecialchars($plan_recommendations[0]['plan']['name']) : 'No Recommendation'; ?>',
            total_visits: '<?php echo $member_analytics['attendance_patterns']['total_visits']; ?>',
            payment_reliability: '<?php echo round($member_analytics['payment_behavior']['payment_reliability']); ?>%',
            export_date: new Date().toISOString()
        };
        
        const dataStr = JSON.stringify(analyticsData, null, 2);
        const dataBlob = new Blob([dataStr], {type: 'application/json'});
        const url = URL.createObjectURL(dataBlob);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = 'fitness_analytics_<?php echo $user_id; ?>_<?php echo date('Y-m-d'); ?>.json';
        link.click();
        
        URL.revokeObjectURL(url);
        
        showToast('Analytics data exported successfully!', true);
    }

    // BMI Modal Functions
    function openBMIModal() {
        const modal = document.getElementById('bmiModal');
        modal.classList.remove('hidden');
        
        // Pre-fill with current values if available
        const currentHeight = '<?php echo $user['height'] ?? ''; ?>';
        const currentWeight = '<?php echo $user['weight'] ?? ''; ?>';
        const currentBodyFat = '<?php echo $user['body_fat'] ?? ''; ?>';
        const currentMuscleMass = '<?php echo $user['muscle_mass'] ?? ''; ?>';
        const currentWaist = '<?php echo $user['waist'] ?? ''; ?>';
        const currentHip = '<?php echo $user['hip'] ?? ''; ?>';
        const currentTrainingLevel = '<?php echo $user['training_level'] ?? ''; ?>';
        const currentTrainingFrequency = '<?php echo $user['training_frequency'] ?? ''; ?>';
        const currentTrainingNotes = '<?php echo htmlspecialchars($user['training_notes'] ?? '', ENT_QUOTES); ?>';
        
        // Fill basic measurements
        if (currentHeight) document.getElementById('bmiHeight').value = currentHeight;
        if (currentWeight) document.getElementById('bmiWeight').value = currentWeight;
        
        // Fill body composition
        if (currentBodyFat) document.getElementById('bodyFat').value = currentBodyFat;
        if (currentMuscleMass) document.getElementById('muscleMass').value = currentMuscleMass;
        
        // Fill circumference measurements
        if (currentWaist) document.getElementById('waist').value = currentWaist;
        if (currentHip) document.getElementById('hip').value = currentHip;
        
        // Fill training progress
        if (currentTrainingLevel) document.getElementById('trainingLevel').value = currentTrainingLevel;
        if (currentTrainingFrequency) document.getElementById('trainingFrequency').value = currentTrainingFrequency;
        if (currentTrainingNotes) document.getElementById('trainingNotes').value = currentTrainingNotes;
        
        // Add event listeners for real-time BMI calculation
        document.getElementById('bmiHeight').addEventListener('input', calculateBMI);
        document.getElementById('bmiWeight').addEventListener('input', calculateBMI);
        
        // Add click outside to close functionality
        modal.addEventListener('click', handleModalClick);
        
        // Focus on first input
        setTimeout(() => {
            document.getElementById('bmiHeight').focus();
        }, 100);
    }

    function closeBMIModal() {
        const modal = document.getElementById('bmiModal');
        modal.classList.add('hidden');
        
        // Reset form and results
        document.getElementById('bmiForm').reset();
        const resultDiv = document.getElementById('bmiResult');
        resultDiv.classList.add('hidden');
        resultDiv.classList.remove('show');
        
        // Remove event listeners
        document.getElementById('bmiHeight').removeEventListener('input', calculateBMI);
        document.getElementById('bmiWeight').removeEventListener('input', calculateBMI);
        modal.removeEventListener('click', handleModalClick);
    }

    function handleModalClick(e) {
        if (e.target === e.currentTarget) {
            closeBMIModal();
        }
    }

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const modal = document.getElementById('bmiModal');
            if (!modal.classList.contains('hidden')) {
                closeBMIModal();
            }
        }
    });

    function calculateBMI() {
        const height = parseFloat(document.getElementById('bmiHeight').value);
        const weight = parseFloat(document.getElementById('bmiWeight').value);
        
        if (height && weight && height > 0 && weight > 0) {
            const heightInMeters = height / 100;
            const bmi = weight / (heightInMeters * heightInMeters);
            const category = getBMICategory(bmi);
            
            document.getElementById('bmiValue').textContent = `BMI: ${bmi.toFixed(1)}`;
            document.getElementById('bmiCategory').textContent = category;
            
            const resultDiv = document.getElementById('bmiResult');
            resultDiv.classList.remove('hidden');
            // Add show class for animation
            setTimeout(() => {
                resultDiv.classList.add('show');
            }, 10);
        } else {
            const resultDiv = document.getElementById('bmiResult');
            resultDiv.classList.remove('show');
            resultDiv.classList.add('hidden');
        }
    }

    function getBMICategory(bmi) {
        if (bmi < 18.5) return 'Underweight';
        if (bmi < 25) return 'Normal Weight';
        if (bmi < 30) return 'Overweight';
        if (bmi < 35) return 'Obese Class I';
        if (bmi < 40) return 'Obese Class II';
        return 'Obese Class III';
    }

    // Validate BMI form before submission
    function validateBMIForm() {
        const height = parseFloat(document.getElementById('bmiHeight').value);
        const weight = parseFloat(document.getElementById('bmiWeight').value);
        const errorDiv = document.getElementById('bmiError');
        const errorMsg = document.getElementById('bmiErrorMessage');
        
        // Clear previous errors
        errorDiv.classList.add('hidden');
        
        // Validate required fields
        if (!height || !weight) {
            errorMsg.textContent = 'Height and weight are required fields.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        // Validate ranges
        if (height < 100 || height > 250) {
            errorMsg.textContent = 'Height must be between 100-250 cm.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        if (weight < 30 || weight > 300) {
            errorMsg.textContent = 'Weight must be between 30-300 kg.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        // Validate optional fields
        const bodyFat = parseFloat(document.getElementById('bodyFat').value);
        const muscleMass = parseFloat(document.getElementById('muscleMass').value);
        const waist = parseFloat(document.getElementById('waist').value);
        const hip = parseFloat(document.getElementById('hip').value);
        
        if (bodyFat && (bodyFat < 5 || bodyFat > 50)) {
            errorMsg.textContent = 'Body fat percentage must be between 5-50%.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        if (muscleMass && (muscleMass < 20 || muscleMass > 60)) {
            errorMsg.textContent = 'Muscle mass percentage must be between 20-60%.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        if (waist && (waist < 50 || waist > 200)) {
            errorMsg.textContent = 'Waist measurement must be between 50-200 cm.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        if (hip && (hip < 60 || hip > 250)) {
            errorMsg.textContent = 'Hip measurement must be between 60-250 cm.';
            errorDiv.classList.remove('hidden');
            return false;
        }
        
        return true;
    }

    // Handle BMI form submission
    document.getElementById('bmiForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Validate form first
        if (!validateBMIForm()) {
            return;
        }
        
        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        
        const formData = new FormData(e.target);
        formData.append('update_type', 'bmi_data');
        
        // Debug: Log form data
        console.log('Sending fitness data:');
        for (let [key, value] of formData.entries()) {
            console.log(key + ': ' + value);
        }
        
        try {
            const response = await fetch('update_fitness_data.php', {
                method: 'POST',
                body: formData
            });
            
            // Check if response is ok
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            
            // Debug: Log server response
            console.log('Server response:', result);
            
            if (result.success) {
                showToast('Fitness data updated successfully!', true);
                closeBMIModal();
                // Reload page to show updated fitness goals
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast(result.message || 'Failed to update fitness data', false);
                // Reset button
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        } catch (error) {
            console.error('Error saving fitness data:', error);
            
            // More specific error messages
            let errorMessage = 'An error occurred while saving fitness data.';
            
            if (error.message.includes('HTTP error')) {
                errorMessage = 'Server error occurred. Please try again.';
            } else if (error.message.includes('Failed to fetch')) {
                errorMessage = 'Network error. Please check your connection.';
            } else if (error.name === 'SyntaxError') {
                errorMessage = 'Invalid response from server. Please check the console for details.';
            }
            
            showToast(errorMessage, false);
            
            // Reset button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    });

    function viewBMIHistory() {
        // For now, show a message that this feature is coming soon
        showToast('BMI history tracking feature coming soon! This will show your BMI changes over time.', true);
        
        // In the future, this could open a modal with BMI history charts
        // or redirect to a dedicated BMI tracking page
    }

    // Profile Page Exercise Details Modal Functions  
    function showProfileExerciseDetails(exerciseName) {
        console.log('🔍 Profile page - Opening exercise details for:', exerciseName);
        
        // Show modal
        const modal = document.getElementById('exerciseDetailsModal');
        const modalContent = document.getElementById('exerciseModalContent');
        
        if (!modal) {
            console.error('Exercise modal not found!');
            return;
        }
        
        // Prevent body scrolling and prepare modal
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open');
        
        // Show modal with loading state
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        modal.style.display = 'flex'; // Force show
        modal.style.visibility = 'visible'; // Make sure it's visible
        modal.style.zIndex = '50'; // Bring to front
        
        // Get exercise details from local database
        const exerciseDetails = getExerciseDetails(exerciseName);
        
        console.log('📋 Exercise details found:', exerciseDetails);
        
        if (exerciseDetails) {
            console.log('✅ Displaying exercise details for:', exerciseDetails.name);
            displayExerciseDetails(exerciseDetails);
        } else {
            console.log('❌ No exercise details found for:', exerciseName);
            modalContent.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-triangle text-3xl text-red-500 mb-4"></i>
                    <p class="text-gray-600">Exercise details not found for: ${exerciseName}</p>
                    <p class="text-sm text-red-500 mt-2">Looking for: "${exerciseName}"</p>
                    <button onclick="closeExerciseModal()" class="mt-4 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Close
                    </button>
                </div>
            `;
        }
    }

    // Fallback function for compatibility
    function showExerciseDetails(exerciseName) {
        showProfileExerciseDetails(exerciseName);
    }

    // Open Recommendations Modal Function
    function openRecommendationsModal() {
        console.log('🔍 Opening full recommendations modal...');
        const modal = document.getElementById('recommendationsModal');
        if (modal) {
            console.log('✅ Recommendations modal found, opening...');
            modal.classList.remove('hidden');
            // Prevent body scrolling
            document.body.style.overflow = 'hidden';
            document.body.classList.add('modal-open');
        } else {
            console.error('❌ Recommendations modal not found!');
            alert('Recommendations modal is not available. Please refresh the page and try again.');
        }
    }

    // Close Recommendations Modal Function (backup if not defined elsewhere)
    function closeRecommendationsModal() {
        console.log('🔴 Closing recommendations modal...');
        const modal = document.getElementById('recommendationsModal');
        if (modal) {
            modal.classList.add('hidden');
            // Restore body scrolling
            document.body.style.overflow = '';
            document.body.classList.remove('modal-open');
            console.log('✅ Recommendations modal closed successfully!');
        }
    }

    // Local exercise database
    function getExerciseDetails(exerciseName) {
        const exerciseDatabase = {
            // BMI-Specific Primary Programs
            'Underweight Strength Program': {
                name: 'Underweight Strength Program',
                category: 'Hypertrophy & Mass Building',
                difficulty: 'Beginner to Intermediate',
                description: 'An intensive muscle hypertrophy program specifically designed for underweight individuals to maximize muscle protein synthesis, increase total body mass, and develop strength through progressive resistance training.',
                muscle_groups: ['Pectoralis major and minor', 'Latissimus dorsi and rhomboids', 'Vastus muscles and gluteus maximus'],
                equipment: 'Olympic Barbell Set, Adjustable Dumbbells, Pull-up Bar, Weight Plates',
                instructions: [
                    'Perform compound exercises first when energy levels are highest',
                    'Execute 3-4 sets of 6-12 repetitions targeting muscle failure',
                    'Implement controlled eccentric phase lasting 3-4 seconds',
                    'Maintain 48-72 hours rest between training same muscle groups',
                    'Increase resistance by 2.5-5% when completing all prescribed repetitions',
                    'Document training loads and body weight changes weekly'
                ],
                benefits: [
                    'Stimulates maximum muscle fiber recruitment and growth',
                    'Increases total body weight through lean tissue development',
                    'Enhances appetite regulation and nutrient partitioning',
                    'Develops functional strength for daily activities',
                    'Improves insulin sensitivity and glucose uptake',
                    'Boosts resting metabolic rate permanently'
                ],
                examples: [
                    'Standard exercises: Barbell squats, deadlifts, bench press, bent-over rows',
                    'Modified variations: Goblet squats, dumbbell chest press, assisted chin-ups',
                    'Advanced progression: Front squats, Romanian deadlifts, weighted dips, pull-ups'
                ],
                tips: 'Consume 300-500 calories above maintenance daily with emphasis on post-workout nutrition. Prioritize sleep quality for growth hormone release and tissue repair.'
            },
            'Normal Weight Balanced Program': {
                name: 'Normal Weight Balanced Program',
                category: 'Comprehensive Fitness Optimization',
                difficulty: 'Intermediate',
                description: 'A well-rounded fitness maintenance program for individuals with optimal BMI, integrating strength training, aerobic conditioning, and flexibility work to sustain peak physical health and performance.',
                muscle_groups: ['Synergistic muscle chains', 'Aerobic and anaerobic energy systems', 'Fascial network and joint mobility'],
                equipment: 'Multi-Station Gym, Cardio Equipment, Yoga Mats, Suspension Trainers, Medicine Balls',
                instructions: [
                    'Alternate between strength-focused and cardio-focused training days',
                    'Implement periodized training with varying intensity cycles',
                    'Incorporate functional movement patterns mimicking daily activities',
                    'Execute dynamic warm-up sequences targeting mobility and activation',
                    'Progress training variables systematically every 4-6 weeks',
                    'Include flexibility and recovery sessions twice weekly'
                ],
                benefits: [
                    'Optimizes body composition ratio of muscle to fat',
                    'Maximizes both aerobic and anaerobic fitness capacity',
                    'Enhances neuromuscular coordination and movement efficiency',
                    'Prevents age-related decline in physical function',
                    'Supports psychological well-being through exercise variety',
                    'Maintains metabolic flexibility and hormonal balance'
                ],
                examples: [
                    'Standard exercises: Functional movement circuits, tempo runs, compound lifting',
                    'Modified variations: Aqua fitness, cycling intervals, bodyweight complexes',
                    'Advanced progression: Plyometric training, sport-specific movements, advanced yoga flows'
                ],
                tips: 'Implement periodization principles to prevent overtraining and maintain progression. Balance high-intensity sessions with active recovery and prioritize sleep for optimal adaptation.'
            },
            'Overweight Weight Loss Program': {
                name: 'Overweight Weight Loss Program',
                category: 'Metabolic Enhancement & Fat Loss',
                difficulty: 'Beginner to Intermediate',
                description: 'A scientifically-designed fat loss program that maximizes caloric expenditure while preserving lean muscle mass through strategic combination of aerobic exercise and resistance training.',
                muscle_groups: ['Type I muscle fibers for endurance', 'Core musculature for postural support', 'Large muscle groups for metabolic demand'],
                equipment: 'Elliptical Machine, Recumbent Bike, Resistance Bands, Light Dumbbells (5-15 lbs)',
                instructions: [
                    'Establish baseline fitness with 20-minute low-intensity cardio sessions',
                    'Target heart rate zone of 60-75% maximum for optimal fat oxidation',
                    'Add resistance training 2 days per week focusing on major muscle groups',
                    'Increase cardio duration by 5 minutes every 2 weeks progressively',
                    'Implement interval training once baseline fitness is established',
                    'Monitor body composition changes rather than scale weight alone'
                ],
                benefits: [
                    'Maximizes fat oxidation while preserving metabolically active tissue',
                    'Improves cardiovascular efficiency and VO2 capacity',
                    'Enhances insulin sensitivity and glucose metabolism',
                    'Reduces visceral adipose tissue and inflammation markers',
                    'Protects weight-bearing joints through controlled impact',
                    'Establishes sustainable lifestyle modification patterns'
                ],
                examples: [
                    'Standard exercises: Elliptical training, resistance band circuits, incline walking',
                    'Modified variations: Pool-based aerobics, recumbent cycling, wall-supported exercises',
                    'Advanced progression: High-intensity intervals, compound movement circuits, outdoor hiking'
                ],
                tips: 'Create a moderate caloric deficit of 500-750 calories daily through combined diet and exercise. Focus on non-scale victories such as improved energy and clothing fit.'
            },
            'Obese Safe Fitness Program': {
                name: 'Obese Safe Fitness Program',
                category: 'Safe Movement & Rehabilitation',
                difficulty: 'Beginner',
                description: 'A medically-guided, ultra-safe fitness program specifically designed for individuals with obesity, focusing on joint protection, gradual movement introduction, and sustainable habit formation.',
                muscle_groups: ['Postural support muscles', 'Large muscle groups (thighs and glutes)', 'Core stabilization muscles'],
                equipment: 'Supportive Chair, Pool Access (if available), Comfortable Walking Shoes, Medical Clearance',
                instructions: [
                    'Begin with 5-minute movement sessions, 3 times per week',
                    'Start all exercises in seated position for joint protection',
                    'Perform gentle range-of-motion movements for major joints',
                    'Use wall or chair support for any standing movements',
                    'Monitor heart rate and stop if exceeding 50-60% maximum',
                    'Progress duration by only 2-3 minutes per week maximum'
                ],
                benefits: [
                    'Initiates safe weight management process',
                    'Strengthens weight-bearing joints gradually',
                    'Improves circulation and reduces edema',
                    'Builds exercise tolerance without strain',
                    'Enhances mood and reduces depression',
                    'Prepares body for advanced movement patterns'
                ],
                examples: [
                    'Standard exercises: Seated knee lifts, wall-supported arm circles, gentle neck rolls',
                    'Modified variations: Pool walking, bed-based stretches, breathing exercises',
                    'Advanced progression: Standing marching in place, supported squats, short walks'
                ],
                tips: 'Always obtain medical clearance before starting. Focus on movement quality over quantity, and celebrate every small achievement. Listen to your body and never push through pain.'
            },
            'Nutrition Focus': {
                name: 'Nutrition Focus',
                category: 'Nutritional Support & Education',
                difficulty: 'Beginner',
                description: 'Comprehensive nutrition guidance specifically designed for underweight individuals looking to gain healthy weight through proper nutrition, meal timing, and nutrient optimization.',
                muscle_groups: ['Digestive System', 'Metabolic System', 'Overall Body Composition'],
                equipment: 'Nutrition Knowledge, Meal Planning Tools, Optional: Food Scale',
                instructions: [
                    'Calculate daily caloric needs and add 300-500 calories',
                    'Eat 5-6 smaller meals throughout the day',
                    'Include protein at every meal (25-30g per meal)',
                    'Focus on nutrient-dense, calorie-rich foods',
                    'Time meals around workouts for optimal recovery',
                    'Track food intake for first 2-4 weeks'
                ],
                benefits: [
                    'Supports healthy weight gain and muscle building',
                    'Improves energy levels and workout performance',
                    'Enhances immune system function',
                    'Promotes better sleep and recovery',
                    'Builds sustainable eating habits',
                    'Improves nutrient absorption and digestion',
                    'Supports hormone production and balance'
                ],
                tips: [
                    'Choose healthy fats: nuts, avocado, olive oil',
                    'Include complex carbs: oats, quinoa, sweet potatoes',
                    'Prioritize lean proteins: chicken, fish, legumes, eggs',
                    'Drink calories: smoothies, milk, protein shakes',
                    'Don\'t skip meals - consistency is key',
                    'Stay hydrated but avoid drinking too much before meals',
                    'Consider working with a registered dietitian'
                ]
            },
            'Flexibility Work': {
                name: 'Flexibility Work',
                category: 'Mobility & Recovery',
                difficulty: 'Beginner to Intermediate',
                description: 'Comprehensive flexibility and mobility program designed to improve range of motion, prevent injury, and enhance overall movement quality for balanced fitness.',
                muscle_groups: ['All Major Muscle Groups', 'Connective Tissues', 'Joint Structures'],
                equipment: 'Yoga Mat, Optional: Foam Roller, Resistance Bands',
                instructions: [
                    'Perform 15-20 minutes of stretching after workouts',
                    'Include both static and dynamic stretching',
                    'Hold static stretches for 30-60 seconds',
                    'Focus on major muscle groups: hamstrings, hip flexors, shoulders',
                    'Include yoga or Pilates sessions 1-2 times weekly',
                    'Practice deep breathing during stretching'
                ],
                benefits: [
                    'Improves joint range of motion and flexibility',
                    'Reduces muscle tension and soreness',
                    'Enhances athletic performance and movement quality',
                    'Decreases risk of injury during activities',
                    'Improves posture and body alignment',
                    'Promotes relaxation and stress relief',
                    'Supports faster recovery between workouts'
                ],
                tips: [
                    'Never stretch cold muscles - warm up first',
                    'Stretch to mild tension, never to pain',
                    'Breathe deeply and relax into each stretch',
                    'Be consistent - flexibility improves gradually',
                    'Focus on problem areas specific to your activities',
                    'Include both upper and lower body stretches',
                    'Consider attending yoga or stretching classes'
                ]
            },
            'Mobility Work': {
                name: 'Mobility Work',
                category: 'Movement Quality & Recovery',
                difficulty: 'Beginner to Intermediate',
                description: 'Targeted mobility exercises designed to improve joint function, movement patterns, and overall body mechanics for individuals with limited mobility or movement restrictions.',
                muscle_groups: ['Hip Complex', 'Shoulder Girdle', 'Spine', 'Ankle Joint'],
                equipment: 'None Required, Optional: Resistance Bands, Mobility Tools',
                instructions: [
                    'Focus on major joints: hips, shoulders, spine, ankles',
                    'Perform dynamic movements through full range of motion',
                    'Include 10-15 minutes before and after exercise',
                    'Progress gradually from basic to complex movements',
                    'Use controlled, deliberate movements',
                    'Listen to your body and avoid forcing movements'
                ],
                benefits: [
                    'Improves joint mobility and movement efficiency',
                    'Reduces stiffness and movement restrictions',
                    'Enhances daily functional activities',
                    'Helps prevent compensatory movement patterns',
                    'Supports better exercise form and technique',
                    'Reduces pain and discomfort from tight areas',
                    'Promotes better circulation and tissue health'
                ],
                tips: [
                    'Start with gentle movements and progress gradually',
                    'Focus on quality of movement over quantity',
                    'Address your specific movement limitations',
                    'Include mobility work in your daily routine',
                    'Be patient - mobility improvements take time',
                    'Consider working with a movement specialist',
                    'Combine with other recovery strategies like foam rolling'
                ]
            },
            
            // Underweight Exercises
            'Compound Strength Training': {
                name: 'Compound Strength Training',
                category: 'Strength Training',
                difficulty: 'Intermediate',
                description: 'Multi-joint exercises that engage multiple muscle groups simultaneously, perfect for building overall strength and muscle mass.',
                muscle_groups: ['Full Body', 'Core', 'Legs', 'Back', 'Chest'],
                equipment: 'Barbell, Dumbbells, or Bodyweight',
                instructions: [
                    'Start with compound movements like squats, deadlifts, and bench press',
                    'Focus on proper form and technique before adding weight',
                    'Perform 3-4 sets of 8-12 repetitions',
                    'Rest 2-3 minutes between sets for optimal recovery',
                    'Gradually increase weight as strength improves'
                ],
                benefits: [
                    'Builds overall muscle mass and strength',
                    'Improves functional movement patterns',
                    'Increases metabolic rate',
                    'Enhances bone density',
                    'Efficient full-body workout'
                ],
                tips: [
                    'Always warm up with lighter weights before heavy sets',
                    'Keep your core engaged throughout each movement',
                    'Focus on controlled, smooth movements',
                    'Don\'t sacrifice form for heavier weights',
                    'Track your progress to ensure progressive overload'
                ]
            },
            'Progressive Overload': {
                name: 'Progressive Overload',
                category: 'Training Principle',
                difficulty: 'Beginner',
                description: 'A fundamental training principle where you gradually increase the stress placed on your muscles to continue making gains.',
                muscle_groups: ['All Muscle Groups'],
                equipment: 'Any Resistance Equipment',
                instructions: [
                    'Start with a weight you can lift 8-12 times with good form',
                    'Once you can complete 12 reps easily, increase the weight',
                    'Aim for 8-10 reps with the new weight',
                    'Continue this cycle of progression',
                    'Track your progress in a workout journal'
                ],
                benefits: [
                    'Continuous muscle growth and strength gains',
                    'Prevents training plateaus',
                    'Builds confidence and motivation',
                    'Improves overall fitness level',
                    'Creates sustainable long-term progress'
                ],
                tips: [
                    'Increase weight by 5-10% when progressing',
                    'Don\'t rush progression - quality over quantity',
                    'Listen to your body and adjust accordingly',
                    'Be patient - progress takes time',
                    'Celebrate small improvements along the way'
                ]
            },
            'Muscle Building Focus': {
                name: 'Muscle Building Focus',
                category: 'Hypertrophy Training',
                difficulty: 'Intermediate',
                description: 'Specialized training approach designed to maximize muscle growth through specific rep ranges, rest periods, and exercise selection.',
                muscle_groups: ['All Muscle Groups'],
                equipment: 'Weights, Resistance Bands, or Bodyweight',
                instructions: [
                    'Perform 3-4 sets of 8-12 repetitions per exercise',
                    'Rest 60-90 seconds between sets',
                    'Focus on mind-muscle connection',
                    'Include both compound and isolation exercises',
                    'Train each muscle group 2-3 times per week'
                ],
                benefits: [
                    'Maximizes muscle hypertrophy (growth)',
                    'Improves muscle definition and tone',
                    'Increases overall strength',
                    'Boosts metabolism',
                    'Enhances body composition'
                ],
                tips: [
                    'Eat in a slight calorie surplus with adequate protein',
                    'Get 7-9 hours of quality sleep per night',
                    'Stay hydrated throughout the day',
                    'Be consistent with your training schedule',
                    'Allow adequate recovery between workouts'
                ]
            },
            
            // Normal Weight Exercises
            'Balanced Strength Training': {
                name: 'Balanced Strength Training',
                category: 'Strength & Conditioning',
                difficulty: 'Intermediate',
                description: 'A comprehensive approach that balances strength development with overall fitness, incorporating various training modalities.',
                muscle_groups: ['Full Body', 'Core', 'Upper Body', 'Lower Body'],
                equipment: 'Weights, Resistance Bands, Bodyweight',
                instructions: [
                    'Include 2-3 strength training sessions per week',
                    'Mix compound and isolation exercises',
                    'Vary intensity and rep ranges',
                    'Include functional movement patterns',
                    'Balance push and pull movements'
                ],
                benefits: [
                    'Improves overall strength and fitness',
                    'Enhances functional movement',
                    'Prevents muscle imbalances',
                    'Increases metabolic rate',
                    'Builds lean muscle mass'
                ],
                tips: [
                    'Maintain balance between different movement patterns',
                    'Don\'t neglect any major muscle groups',
                    'Include both unilateral and bilateral exercises',
                    'Focus on quality movement over quantity',
                    'Periodically change your routine to prevent plateaus'
                ]
            },
            'Moderate Cardio': {
                name: 'Moderate Cardio',
                category: 'Cardiovascular Training',
                difficulty: 'Beginner',
                description: 'Sustained aerobic exercise performed at 60-80% of maximum heart rate to improve cardiovascular fitness and endurance.',
                muscle_groups: ['Heart', 'Lungs', 'Legs'],
                equipment: 'Treadmill, Bike, Elliptical, or Outdoor Space',
                instructions: [
                    'Start with 20-30 minutes of continuous activity',
                    'Maintain a pace where you can talk but not sing',
                    'Gradually increase duration to 45-60 minutes',
                    'Perform 3-5 sessions per week',
                    'Include warm-up and cool-down periods'
                ],
                benefits: [
                    'Improves cardiovascular health',
                    'Increases endurance and stamina',
                    'Burns calories and aids weight management',
                    'Reduces stress and improves mood',
                    'Strengthens heart and lungs'
                ],
                tips: [
                    'Monitor your heart rate during exercise',
                    'Stay hydrated throughout your workout',
                    'Choose activities you enjoy to maintain consistency',
                    'Gradually increase intensity over time',
                    'Listen to your body and adjust pace as needed'
                ]
            },
            'Functional Training': {
                name: 'Functional Training',
                category: 'Movement Training',
                difficulty: 'Intermediate',
                description: 'Training that improves your ability to perform everyday activities by enhancing movement patterns, balance, and coordination.',
                muscle_groups: ['Core', 'Full Body', 'Stabilizer Muscles'],
                equipment: 'Bodyweight, Resistance Bands, Light Weights',
                instructions: [
                    'Focus on multi-planar movements',
                    'Include balance and stability exercises',
                    'Practice movement patterns used in daily life',
                    'Work on core strength and stability',
                    'Incorporate single-leg and single-arm movements'
                ],
                benefits: [
                    'Improves daily life functionality',
                    'Enhances balance and coordination',
                    'Reduces risk of injury',
                    'Increases core strength',
                    'Improves posture and movement quality'
                ],
                tips: [
                    'Start with basic movements and progress gradually',
                    'Focus on quality of movement over speed',
                    'Include exercises that challenge your balance',
                    'Practice movements slowly and deliberately',
                    'Don\'t rush through exercises - focus on form'
                ]
            },
            
            // Overweight Exercises
            'Low-Impact Cardio': {
                name: 'Low-Impact Cardio',
                category: 'Cardiovascular Training',
                difficulty: 'Beginner',
                description: 'Gentle cardiovascular exercises that minimize stress on joints while improving heart health and burning calories.',
                muscle_groups: ['Heart', 'Lungs', 'Legs'],
                equipment: 'Walking Shoes, Pool, Stationary Bike',
                instructions: [
                    'Start with 10-15 minutes of walking',
                    'Gradually increase to 30-45 minutes',
                    'Maintain a comfortable pace',
                    'Include gentle hills or inclines',
                    'Perform 5-7 sessions per week'
                ],
                benefits: [
                    'Improves cardiovascular health',
                    'Burns calories without joint stress',
                    'Builds endurance gradually',
                    'Improves mood and energy levels',
                    'Easy to incorporate into daily routine'
                ],
                tips: [
                    'Wear comfortable, supportive shoes',
                    'Start on flat surfaces before adding hills',
                    'Listen to your body and don\'t overdo it',
                    'Stay hydrated during longer sessions',
                    'Consider walking with a friend for motivation'
                ]
            },
            'Bodyweight Strength': {
                name: 'Bodyweight Strength',
                category: 'Strength Training',
                difficulty: 'Beginner',
                description: 'Strength training exercises that use your own body weight as resistance, perfect for building strength without equipment.',
                muscle_groups: ['Full Body', 'Core', 'Upper Body', 'Lower Body'],
                equipment: 'Bodyweight Only',
                instructions: [
                    'Start with basic movements like squats and push-ups',
                    'Focus on proper form and technique',
                    'Perform 2-3 sets of 8-15 repetitions',
                    'Rest 60-90 seconds between sets',
                    'Gradually increase difficulty as strength improves'
                ],
                benefits: [
                    'Builds functional strength',
                    'Improves body awareness',
                    'No equipment needed',
                    'Can be done anywhere',
                    'Reduces risk of injury'
                ],
                tips: [
                    'Master basic movements before progressing',
                    'Focus on controlled, smooth movements',
                    'Don\'t rush through exercises',
                    'Include both upper and lower body work',
                    'Listen to your body and rest when needed'
                ]
            },
            'Endurance Building': {
                name: 'Endurance Building',
                category: 'Conditioning',
                difficulty: 'Beginner',
                description: 'Progressive training to improve your ability to sustain physical activity for longer periods.',
                muscle_groups: ['Heart', 'Lungs', 'Legs', 'Core'],
                equipment: 'Walking Shoes, Timer, or Heart Rate Monitor',
                instructions: [
                    'Start with short, easy sessions',
                    'Gradually increase duration by 5-10 minutes',
                    'Maintain a comfortable, sustainable pace',
                    'Include rest days between sessions',
                    'Track your progress over time'
                ],
                benefits: [
                    'Improves cardiovascular fitness',
                    'Increases stamina and endurance',
                    'Burns calories and aids weight loss',
                    'Reduces fatigue in daily activities',
                    'Builds confidence in physical abilities'
                ],
                tips: [
                    'Be patient with your progress',
                    'Don\'t increase intensity and duration simultaneously',
                    'Stay consistent with your routine',
                    'Celebrate small improvements',
                    'Remember that building endurance takes time'
                ]
            },
            
            // Obese Exercises
            'Gentle Walking Program': {
                name: 'Gentle Walking Program',
                category: 'Progressive Cardiovascular Training',
                difficulty: 'Beginner',
                description: 'A structured outdoor walking program specifically designed to build cardiovascular endurance and promote calorie burning for individuals ready to engage in ambulatory exercise.',
                muscle_groups: ['Calf muscles and shin muscles', 'Quadriceps and hamstrings', 'Gluteal muscles for propulsion'],
                equipment: 'Professional Walking Shoes with Arch Support, Moisture-Wicking Clothing, Water Bottle',
                instructions: [
                    'Warm up with 2-3 minutes of slow walking before main session',
                    'Walk at a pace where you can speak in short sentences',
                    'Maintain upright posture with arms swinging naturally',
                    'Take walking breaks every 5-7 minutes initially',
                    'Cool down with 2-3 minutes of slower walking',
                    'Track distance and time to monitor progressive improvement'
                ],
                benefits: [
                    'Builds aerobic capacity and endurance systematically',
                    'Develops leg muscle strength and coordination',
                    'Promotes natural calorie expenditure outdoors',
                    'Enhances balance and walking confidence',
                    'Provides vitamin D exposure from sunlight',
                    'Creates sustainable daily activity habits'
                ],
                examples: [
                    'Standard exercises: Neighborhood walking routes, park pathway walking, retail store walking',
                    'Modified variations: Treadmill walking with incline, walking with trekking poles for stability',
                    'Advanced progression: Nature trail walking, walking with weighted vest, speed interval walking'
                ],
                tips: 'Choose varied walking routes to maintain interest and challenge different muscle groups. Walk during cooler parts of the day and always carry identification and emergency contact information.'
            },
            'Chair-Based Exercises': {
                name: 'Chair-Based Exercises',
                category: 'Adaptive Strength & Mobility Training',
                difficulty: 'Beginner',
                description: 'A specialized seated fitness program designed for individuals requiring chair support, focusing on upper body strength development, core activation, and circulation enhancement without weight-bearing stress.',
                muscle_groups: ['Deltoids and rotator cuff muscles', 'Abdominal and oblique muscles', 'Ankle dorsiflexors and plantar flexors'],
                equipment: 'Armless Sturdy Chair, Elastic Therapy Bands, Light Hand Weights (1-3 lbs), Towel for Grip',
                instructions: [
                    'Position chair away from walls with feet firmly planted shoulder-width apart',
                    'Engage core muscles and maintain neutral spine throughout session',
                    'Perform seated rows using resistance band anchored to door or stable object',
                    'Execute seated punches alternating left and right arms with control',
                    'Practice ankle circles and heel-toe raises for lower extremity circulation',
                    'Complete each exercise for 30-45 seconds with 15-second rest periods'
                ],
                benefits: [
                    'Develops upper extremity muscular strength safely',
                    'Activates deep abdominal muscles for postural support',
                    'Stimulates lymphatic drainage and venous return',
                    'Maintains range of motion in major joints',
                    'Accommodates physical limitations and mobility restrictions',
                    'Provides independence in exercise execution'
                ],
                examples: [
                    'Standard exercises: Seated chest press with bands, seated twists with weight, calf raises',
                    'Modified variations: Isometric holds, finger exercises, shoulder blade squeezes',
                    'Advanced progression: Multi-plane movements, coordination challenges, resistance variations'
                ],
                tips: 'Ensure chair stability before beginning and maintain proper breathing pattern throughout. Focus on quality of movement rather than speed, and adjust resistance based on individual comfort level.'
            },
            'Breathing Exercises': {
                name: 'Breathing Exercises',
                category: 'Respiratory & Relaxation',
                difficulty: 'Beginner',
                description: 'Therapeutic breathing techniques designed to improve lung capacity, reduce stress, and support overall wellness for individuals beginning their fitness journey.',
                muscle_groups: ['Diaphragm and respiratory muscles', 'Core stabilizers', 'Relaxation response system'],
                equipment: 'None (Optional: Chair, Mat)',
                instructions: [
                    'Sit comfortably or lie flat with spine aligned',
                    'Place one hand on chest, one on belly',
                    'Practice diaphragmatic breathing: breathe into belly, not chest',
                    'Inhale slowly for 4 counts, hold for 4 counts, exhale for 6 counts',
                    'Start with 5-10 minutes daily, gradually increase duration',
                    'Practice before, during, or after exercise sessions'
                ],
                benefits: [
                    'Improves oxygen delivery throughout the body',
                    'Reduces stress, anxiety, and blood pressure',
                    'Enhances lung capacity and efficiency',
                    'Improves focus and mental clarity',
                    'Supports better sleep quality',
                    'Prepares the body for physical activity'
                ],
                examples: [
                    'Standard exercises: Deep belly breathing, 4-7-8 breathing technique',
                    'Modified variations: Pursed lip breathing, box breathing',
                    'Advanced progression: Extended breath holds, meditation breathing'
                ],
                tips: 'Practice in a quiet, comfortable environment and don\'t force the breath. Start with short sessions and gradually increase duration. Stop if you feel dizzy and return to normal breathing.'
            },
            'Low-Impact Movement': {
                name: 'Low-Impact Movement',
                category: 'Gentle Mobility & Wellness',
                difficulty: 'Beginner',
                description: 'Gentle, therapeutic movements designed to improve joint mobility, circulation, and body awareness without placing stress on vulnerable joints. Perfect for individuals with limited mobility or those starting their fitness journey.',
                muscle_groups: ['Joint Mobilizers', 'Postural Muscles', 'Core Stabilizers', 'Circulation System'],
                equipment: 'Comfortable Space, Optional: Yoga Mat',
                instructions: [
                    'Begin with gentle neck and shoulder rolls',
                    'Progress to seated leg lifts and arm circles',
                    'Include gentle spine twists and side bends',
                    'Add standing movements like weight shifts and marching',
                    'Perform each movement 5-10 times with 30-second holds',
                    'Start with 10-15 minutes, progress to 20-25 minutes'
                ],
                benefits: [
                    'Improves joint range of motion and flexibility',
                    'Enhances blood circulation and lymphatic drainage',
                    'Reduces muscle stiffness and joint pain',
                    'Builds body awareness and confidence in movement',
                    'Lowers blood pressure and stress levels',
                    'Prepares body for more active exercises',
                    'Can help improve balance and coordination'
                ],
                tips: [
                    'Move slowly with intention and focus',
                    'Never force movements beyond comfortable range',
                    'Coordinate movement with natural breathing patterns',
                    'Use a supportive chair or wall when needed',
                    'Wear loose, comfortable clothing',
                    'Practice daily for best results',
                    'Listen to your body and rest when needed'
                ]
            }
        };
        
        const availableExercises = Object.keys(exerciseDatabase);
        console.log('🎯 Available exercises in database:', availableExercises);
        console.log('🔍 Looking for exercise:', exerciseName);
        
        return exerciseDatabase[exerciseName] || null;
    }

    function displayExerciseDetails(exercise) {
        const modalHeader = document.getElementById('exerciseModalHeader');
        const modalContent = document.getElementById('exerciseModalContent');
        const modalFooter = document.getElementById('exerciseModalFooter');
        
        // Fixed Header - Compact
        modalHeader.innerHTML = `
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-bold text-gray-800">${exercise.name}</h2>
                    <div class="flex gap-2 mt-1">
                        <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs">${exercise.category}</span>
                        <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded text-xs">${exercise.difficulty}</span>
                </div>
                </div>
                <button onclick="console.log('❌ Close button clicked!'); closeExerciseModal();" class="text-gray-400 hover:text-gray-600 text-xl hover:bg-gray-100 p-1 rounded">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        // Scrollable Content - Very Compact
        modalContent.innerHTML = `
            <div class="space-y-3">
                <!-- Description -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Description</h3>
                    <p class="text-gray-600 text-sm leading-relaxed">${exercise.description}</p>
                </div>

                <!-- Target Muscles -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Muscle Groups</h3>
                    <div class="flex flex-wrap gap-1">
                        ${exercise.muscle_groups.map(muscle => 
                            `<span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded text-xs">${muscle}</span>`
                        ).join('')}
                    </div>
                </div>

                <!-- Equipment -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Equipment</h3>
                    <p class="text-gray-600 bg-gray-50 rounded px-2 py-1 text-sm">${exercise.equipment}</p>
                </div>

                <!-- Benefits -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Benefits</h3>
                    <ul class="space-y-1">
                        ${exercise.benefits.slice(0, 4).map(benefit => 
                            `<li class="flex items-start text-gray-600 text-sm">
                                <i class="fas fa-check text-green-500 mr-2 mt-0.5 text-xs flex-shrink-0"></i>
                                <span>${benefit}</span>
                            </li>`
                        ).join('')}
                    </ul>
                </div>

                ${exercise.examples ? `
                <!-- Examples -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Examples</h3>
                    <div class="space-y-1">
                        ${exercise.examples.slice(0, 2).map(example => 
                            `<div class="bg-blue-50 rounded px-2 py-1 text-sm text-gray-700">${example}</div>`
                        ).join('')}
                    </div>
                </div>
                ` : ''}

                <!-- Instructions (Top 4) -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">How to Perform</h3>
                    <ol class="space-y-1">
                        ${exercise.instructions.slice(0, 4).map((instruction, index) => 
                            `<li class="flex items-start text-gray-600 text-sm">
                                <span class="bg-blue-500 text-white rounded-full w-4 h-4 flex items-center justify-center text-xs mr-2 mt-0.5 flex-shrink-0">${index + 1}</span>
                                <span>${instruction}</span>
                            </li>`
                        ).join('')}
                    </ol>
                    </div>

                <!-- Tips -->
                <div>
                    <h3 class="font-semibold text-gray-800 mb-1 text-sm">Tips</h3>
                    <div class="bg-yellow-50 rounded px-2 py-1 text-sm text-gray-700">
                        <i class="fas fa-lightbulb text-yellow-500 mr-1"></i>
                        ${typeof exercise.tips === 'string' ? exercise.tips : exercise.tips.join('. ')}
                </div>
            </div>
            </div>
        `;

        // Fixed Footer
        modalFooter.innerHTML = `
            <button onclick="console.log('🔴 Footer close button clicked!'); closeExerciseModal();" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm">
                Close
                </button>
        `;
    }

    function closeExerciseModal() {
        console.log('🔴 Closing exercise modal...');
        
        // Try both possible modal IDs
        const modal1 = document.getElementById('exerciseDetailsModal');
        const modal2 = document.getElementById('exerciseModal');
        
        if (modal1) {
            console.log('✅ Profile modal found, closing...');
            modal1.classList.add('hidden');
            modal1.classList.remove('flex');
            modal1.style.display = 'none'; // Force hide
            modal1.style.visibility = 'hidden'; // Extra hide
            modal1.style.zIndex = '-1'; // Send to back
        }
        
        if (modal2) {
            console.log('✅ Recommendations modal found, closing...');
            modal2.classList.add('hidden');
            modal2.classList.remove('flex');
            modal2.style.display = 'none'; // Force hide
            modal2.style.visibility = 'hidden'; // Extra hide
            modal2.style.zIndex = '-1'; // Send to back
        }
        
        // Restore body and html scrolling completely
        document.body.style.overflow = '';
        document.body.style.position = '';
        document.body.style.top = '';
        document.documentElement.style.overflow = '';
        
        // Remove any pointer-events blocking
        document.body.style.pointerEvents = '';
        
        // Force page to be interactive again
        setTimeout(() => {
            document.body.classList.remove('modal-open');
            console.log('✅ Page fully restored and interactive!');
        }, 100);
        
        console.log('✅ Modal closed successfully!');
    }

    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('exerciseDetailsModal');
        if (event.target === modal) {
            closeExerciseModal();
        }
    });

    // Close modal with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modal1 = document.getElementById('exerciseDetailsModal');
            const modal2 = document.getElementById('exerciseModal');
            if ((modal1 && !modal1.classList.contains('hidden')) || (modal2 && !modal2.classList.contains('hidden'))) {
                console.log('🔴 ESC key pressed, closing modal...');
                closeExerciseModal();
            }
        }
    });

    // Notification System
    let notifications = [];
    let unreadCount = 0;

    // Initialize notifications
    document.addEventListener('DOMContentLoaded', function() {
        loadNotifications();
        setupNotificationEvents();
    });

    function setupNotificationEvents() {
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const profileDropdown = document.getElementById('profileMenu');

        // Toggle notification dropdown
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleNotificationDropdown();
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                closeNotificationDropdown();
            }
            if (!profileDropdown.contains(e.target)) {
                closeProfileDropdown();
            }
        });
    }

    function toggleNotificationDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        const isVisible = !dropdown.classList.contains('invisible');
        
        if (isVisible) {
            closeNotificationDropdown();
        } else {
            openNotificationDropdown();
        }
    }

    function openNotificationDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
        dropdown.classList.add('opacity-100', 'scale-100');
    }

    function closeNotificationDropdown() {
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.classList.add('invisible', 'opacity-0', 'scale-95');
        dropdown.classList.remove('opacity-100', 'scale-100');
    }

    function loadNotifications() {
        // Sample notifications - in a real app, these would come from the server
        notifications = [
            {
                id: 1,
                type: 'info',
                title: 'Welcome to Almo Fitness!',
                message: 'Start your fitness journey by setting your goals and tracking your progress.',
                timestamp: new Date(Date.now() - 86400000), // 1 day ago
                read: false
            },
            {
                id: 2,
                type: 'success',
                title: 'Profile Updated',
                message: 'Your profile information has been successfully updated.',
                timestamp: new Date(Date.now() - 3600000), // 1 hour ago
                read: false
            },
            {
                id: 3,
                type: 'reminder',
                title: 'Workout Reminder',
                message: 'Don\'t forget to log your workout today!',
                timestamp: new Date(Date.now() - 1800000), // 30 minutes ago
                read: false
            }
        ];
        
        unreadCount = notifications.filter(n => !n.read).length;
        updateNotificationBadge();
        renderNotifications();
    }

    function renderNotifications() {
        const notificationList = document.getElementById('notificationList');
        
        if (notifications.length === 0) {
            notificationList.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-bell text-3xl mb-2"></i>
                    <p>No notifications</p>
                </div>
            `;
            return;
        }

        notificationList.innerHTML = notifications.map(notification => {
            const typeIcon = getTypeIcon(notification.type);
            const typeColor = getTypeColor(notification.type);
            const timeAgo = getTimeAgo(notification.timestamp);
            
            // Get quick action button based on notification type
            const quickAction = getQuickAction(notification.type);
            
            return `
                <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-all duration-200 cursor-pointer notification-item" 
                     data-notification-id="${notification.id}" 
                     data-notification-type="${notification.type}"
                     onclick="handleNotificationClick('${notification.id}', '${notification.type}')">
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
                                ${quickAction}
                                ${!notification.read ? '<button onclick="event.stopPropagation(); markAsRead(\'' + notification.id + '\')" class="text-xs text-blue-600 hover:text-blue-800">Mark read</button>' : ''}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    function getNotificationColor(type) {
        switch(type) {
            case 'success': return 'bg-green-500';
            case 'warning': return 'bg-yellow-500';
            case 'error': return 'bg-red-500';
            case 'reminder': return 'bg-blue-500';
            default: return 'bg-gray-500';
        }
    }

    function formatTimestamp(timestamp) {
        const now = new Date();
        const diff = now - timestamp;
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);

        if (minutes < 60) return `${minutes}m ago`;
        if (hours < 24) return `${hours}h ago`;
        return `${days}d ago`;
    }

    function markAsRead(notificationId) {
        const notification = notifications.find(n => n.id === notificationId);
        if (notification) {
            notification.read = true;
            unreadCount = Math.max(0, unreadCount - 1);
            updateNotificationBadge();
            renderNotifications();
        }
    }

    function markAllAsRead() {
        notifications.forEach(n => n.read = true);
        unreadCount = 0;
        updateNotificationBadge();
        renderNotifications();
    }

    function clearAllNotifications() {
        notifications = [];
        unreadCount = 0;
        updateNotificationBadge();
        renderNotifications();
    }

    function updateNotificationBadge() {
        const badge = document.getElementById('notificationBadge');
        if (unreadCount > 0) {
            badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    // Add new notification function (can be called from other parts of the app)
    function addNotification(type, title, message) {
        const newNotification = {
            id: Date.now(),
            type: type,
            title: title,
            message: message,
            timestamp: new Date(),
            read: false
        };
        
        notifications.unshift(newNotification);
        unreadCount++;
        updateNotificationBadge();
        renderNotifications();
    }
    
    // Dismiss expiration warning
    function dismissWarning() {
        const warningBanner = document.querySelector('.bg-yellow-50, .bg-red-50');
        if (warningBanner) {
            warningBanner.style.display = 'none';
        }
    }
    
    // Real-time countdown timer for membership
    <?php if ($membership): ?>
    function updateCountdown() {
        const expiryDate = new Date('<?php echo $membership['expiry_date']; ?>').getTime();
        const now = new Date().getTime();
        const distance = expiryDate - now;
        
        if (distance > 0) {
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            
            const countdownElement = document.getElementById('countdown_<?php echo $membership['plan_id']; ?>');
            if (countdownElement) {
                if (days > 0) {
                    countdownElement.innerHTML = `${days} days, ${hours}h ${minutes}m`;
                } else if (hours > 0) {
                    countdownElement.innerHTML = `${hours}h ${minutes}m`;
                } else {
                    countdownElement.innerHTML = `${minutes}m`;
                }
                
                // Update color based on remaining time
                if (days <= 1) {
                    countdownElement.className = 'text-lg font-bold text-red-600';
                } else if (days <= 7) {
                    countdownElement.className = 'text-lg font-bold text-yellow-600';
                } else {
                    countdownElement.className = 'text-lg font-bold text-gray-800';
                }
            }
        } else {
            // Membership expired
            const countdownElement = document.getElementById('countdown_<?php echo $membership['plan_id']; ?>');
            if (countdownElement) {
                countdownElement.innerHTML = 'EXPIRED';
                countdownElement.className = 'text-lg font-bold text-red-600';
            }
        }
    }
    
    // Update countdown every minute
    setInterval(updateCountdown, 60000);
    updateCountdown(); // Initial call
    
    // Check for expiration warnings and show notifications
    function checkExpirationWarnings() {
        const expiryDate = new Date('<?php echo $membership['expiry_date']; ?>').getTime();
        const now = new Date().getTime();
        const distance = expiryDate - now;
        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        
        if (days <= 7 && days > 0) {
            // Show notification if not already shown today
            const lastWarning = localStorage.getItem('lastExpirationWarning');
            const today = new Date().toDateString();
            
            if (lastWarning !== today) {
                addNotification('warning', 'Membership Expiring Soon', 
                    `Your membership expires in ${days} day${days > 1 ? 's' : ''}. Please renew to continue enjoying our facilities.`);
                localStorage.setItem('lastExpirationWarning', today);
            }
        }
    }
    
    // Check warnings every hour
    setInterval(checkExpirationWarnings, 3600000);
    checkExpirationWarnings(); // Initial check
    <?php endif; ?>

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
                        { id: 1, title: 'Welcome!', message: 'Welcome to Almo Fitness!', read: false, type: 'welcome' },
                        { id: 2, title: 'Equipment Ready', message: 'All gym equipment is available.', read: false, type: 'info' },
                        { id: 3, title: 'Workout Time', message: 'Time for your daily workout!', read: false, type: 'reminder' }
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
                    <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-all duration-200 cursor-pointer notification-item" 
                         data-notification-id="${notification.id}" 
                         data-notification-type="${notification.type}"
                         onclick="handleNotificationClick('${notification.id}', '${notification.type}')">
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
                                    ${!notification.read ? '<button onclick="event.stopPropagation(); markAsRead(\'' + notification.id + '\')" class="text-xs text-blue-600 hover:text-blue-800">Mark read</button>' : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            // Add hover effects and click feedback
            const notificationItems = notificationList.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(4px)';
                    this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
                });
                
                item.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                    this.style.boxShadow = 'none';
                });
                
                item.addEventListener('mousedown', function() {
                    this.style.transform = 'translateX(2px) scale(0.98)';
                });
                
                item.addEventListener('mouseup', function() {
                    this.style.transform = 'translateX(4px) scale(1)';
                });
            });
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
                    body: 'notification_id=' + encodeURIComponent(id) + '&notification_type=' + encodeURIComponent(notification.type)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove notification from the list
                        notifications = notifications.filter(n => n.id !== id);
                        unreadCount = data.unread_count || notifications.length;
                        
                        // Update UI
                        updateBadge();
                        renderNotifications();
                        
                        // Show success feedback
                        showNotificationAction('Notification marked as read! ✅', 'success');
                        
                        console.log('Notification marked as read, updated count:', unreadCount);
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
            // Mark all notifications as read in backend
            const unreadNotifications = notifications.filter(n => !n.read);
            let processedCount = 0;
            
            if (unreadNotifications.length === 0) {
                showNotificationAction('No unread notifications!', 'info');
                return;
            }
            
            unreadNotifications.forEach(notification => {
                fetch('mark_notification_read.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'notification_id=' + encodeURIComponent(notification.id) + '&notification_type=' + encodeURIComponent(notification.type)
                })
                .then(response => response.json())
                .then(data => {
                    processedCount++;
                    if (data.success) {
                        // Remove from local list
                        notifications = notifications.filter(n => n.id !== notification.id);
                    }
                    
                    // If all processed, update UI
                    if (processedCount === unreadNotifications.length) {
                        unreadCount = 0;
                        updateBadge();
                        renderNotifications();
                        showNotificationAction('All notifications marked as read! ✅', 'success');
                    }
                })
                .catch(error => {
                    console.error('Error marking notification as read:', error);
                    processedCount++;
                    
                    // If all processed, update UI
                    if (processedCount === unreadNotifications.length) {
                        unreadCount = 0;
                        updateBadge();
                        renderNotifications();
                        showNotificationAction('All notifications marked as read! ✅', 'success');
                    }
                });
            });
        };
        
        window.clearAllNotifications = function() {
            // Mark all as read first, then clear
            markAllAsRead();
            
            // Clear the list after a short delay
            setTimeout(() => {
                notifications = [];
                unreadCount = 0;
                updateBadge();
                renderNotifications();
                showNotificationAction('All notifications cleared! 🗑️', 'info');
            }, 1000);
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
                
                // Play notification sound if there are unread notifications
                if (unreadCount > 0) {
                    playNotificationSound();
                }
            }
        }
        
        // Play notification sound
        function playNotificationSound() {
            try {
                // Create a simple notification sound using Web Audio API
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
                oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
                
                gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.2);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.2);
            } catch (error) {
                console.log('Audio not supported, skipping sound');
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
        
        // Refresh notifications every 2 minutes for real-time updates
        setInterval(fetchNotifications, 120000);
        
        // Also refresh when the page becomes visible (user returns to tab)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                console.log('Page became visible, refreshing notifications...');
                fetchNotifications();
            }
        });
        
        // Refresh notifications when user interacts with the page
        let userActivityTimeout;
        const resetUserActivity = () => {
            clearTimeout(userActivityTimeout);
            userActivityTimeout = setTimeout(() => {
                console.log('User inactive, refreshing notifications...');
                fetchNotifications();
            }, 30000); // Refresh after 30 seconds of inactivity
        };
        
        // Track user activity
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetUserActivity, true);
        });
        
        // Initial user activity reset
        resetUserActivity();
        
        // Real-time notification counter sync across all pages
        const syncNotificationCount = () => {
            fetch('notification_sync.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_count'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const newCount = data.unread_count;
                    if (newCount !== unreadCount) {
                        console.log('Notification count changed from', unreadCount, 'to', newCount);
                        unreadCount = newCount;
                        updateBadge();
                        
                        // If count increased, show notification
                        if (newCount > 0 && newCount > (window.previousCount || 0)) {
                            showNotificationAction(`You have ${newCount} new notification${newCount > 1 ? 's' : ''}! 🔔`, 'info');
                        }
                        window.previousCount = newCount;
                    }
                }
            })
            .catch(error => {
                console.error('Error syncing notification count:', error);
            });
        };
        
        // Sync count every 30 seconds
        setInterval(syncNotificationCount, 30000);
        
        // Initial sync
        syncNotificationCount();
    });

    // Global functions
    window.handleNotificationClick = function(id, type) {
        console.log('Notification clicked:', id, type);
        
        // Mark as read when clicked
        const notification = notifications.find(n => n.id === id);
        if (notification && !notification.read) {
            markAsRead(id);
        }
        
        // Handle different notification types
        switch(type) {
            case 'membership_expired':
            case 'membership_expiring':
                // Redirect to membership page
                showNotificationAction('Redirecting to membership page...', 'info');
                setTimeout(() => {
                    window.location.href = 'membership.php';
                }, 1500);
                break;
                
            case 'visit_reminder':
                // Show motivational message
                showNotificationAction('Great reminder! Time to hit the gym! 💪', 'success');
                break;
                
            case 'weekly_goal':
                // Show progress encouragement
                showNotificationAction('Keep pushing! You\'re doing great! 🎯', 'success');
                break;
                
            case 'announcement':
                // Show announcement details
                showNotificationAction('Gym announcement viewed! 📢', 'info');
                break;
                
            case 'equipment_maintenance':
                // Redirect to equipment page
                showNotificationAction('Redirecting to equipment status...', 'info');
                setTimeout(() => {
                    window.location.href = 'equipment.php';
                }, 1500);
                break;
                
            case 'payment_pending':
                // Redirect to payment page
                showNotificationAction('Redirecting to payment page...', 'info');
                setTimeout(() => {
                    window.location.href = 'payment.php';
                }, 1500);
                break;
                
            case 'welcome':
                // Show welcome message
                showNotificationAction('Welcome to Almo Fitness! 🎉', 'success');
                break;
                
            default:
                // Generic click action
                showNotificationAction('Notification viewed! ✅', 'info');
                break;
        }
        
        // Add visual feedback
        const clickedItem = document.querySelector(`[data-notification-id="${id}"]`);
        if (clickedItem) {
            clickedItem.style.backgroundColor = '#f0f9ff';
            clickedItem.style.borderLeft = '4px solid #3b82f6';
            setTimeout(() => {
                clickedItem.style.backgroundColor = '';
                clickedItem.style.borderLeft = '';
            }, 2000);
        }
    };
    
    // Show notification action feedback
    function showNotificationAction(message, type) {
        // Create or update action feedback element
        let actionFeedback = document.getElementById('actionFeedback');
        if (!actionFeedback) {
            actionFeedback = document.createElement('div');
            actionFeedback.id = 'actionFeedback';
            actionFeedback.className = 'fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full';
            document.body.appendChild(actionFeedback);
        }
        
        // Set content and styling based on type
        const colors = {
            'success': 'bg-green-500 text-white',
            'info': 'bg-blue-500 text-white',
            'warning': 'bg-yellow-500 text-white',
            'error': 'bg-red-500 text-white'
        };
        
        actionFeedback.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg transition-all duration-300 transform ${colors[type] || colors.info}`;
        actionFeedback.innerHTML = `
            <div class="flex items-center space-x-2">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : type === 'error' ? 'times-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            </div>
        `;
        
        // Show the feedback
        setTimeout(() => {
            actionFeedback.classList.remove('translate-x-full');
        }, 100);
        
        // Hide after 3 seconds
        setTimeout(() => {
            actionFeedback.classList.add('translate-x-full');
        }, 3000);
    }

    // Get quick action button based on notification type
    function getQuickAction(type) {
        switch(type) {
            case 'membership_expired':
            case 'membership_expiring':
                return '<button onclick="event.stopPropagation(); window.location.href=\'membership.php\'" class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded hover:bg-red-200 transition-colors">Renew Now</button>';
                
            case 'visit_reminder':
                return '<button onclick="event.stopPropagation(); showNotificationAction(\'Great motivation! 💪\', \'success\')" class="text-xs bg-purple-100 text-purple-700 px-2 py-1 rounded hover:bg-purple-200 transition-colors">Motivate Me</button>';
                
            case 'weekly_goal':
                return '<button onclick="event.stopPropagation(); showNotificationAction(\'You can do it! 🎯\', \'success\')" class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded hover:bg-green-200 transition-colors">Stay Motivated</button>';
                
            case 'announcement':
                return '<button onclick="event.stopPropagation(); showNotificationAction(\'Announcement viewed! 📢\', \'info\')" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 transition-colors">View Details</button>';
                
            case 'equipment_maintenance':
                return '<button onclick="event.stopPropagation(); window.location.href=\'equipment.php\'" class="text-xs bg-yellow-100 text-yellow-700 px-2 py-1 rounded hover:bg-yellow-200 transition-colors">Check Status</button>';
                
            case 'payment_pending':
                return '<button onclick="event.stopPropagation(); window.location.href=\'payment.php\'" class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded hover:bg-indigo-200 transition-colors">Pay Now</button>';
                
            case 'welcome':
                return '<button onclick="event.stopPropagation(); showNotificationAction(\'Welcome! 🎉\', \'success\')" class="text-xs bg-pink-100 text-pink-700 px-2 py-1 rounded hover:bg-pink-200 transition-colors">Get Started</button>';
                
            default:
                return '';
        }
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

    // Update badge
    function updateBadge() {
        if (notificationBadge) {
            if (unreadCount > 0) {
                notificationBadge.textContent = unreadCount;
                notificationBadge.classList.remove('hidden');
                
                // Add pulse animation for new notifications
                notificationBadge.classList.add('animate-pulse');
                setTimeout(() => {
                    notificationBadge.classList.remove('animate-pulse');
                }, 2000);
                
                // Add bounce effect
                notificationBadge.style.transform = 'scale(1.2)';
                setTimeout(() => {
                    notificationBadge.style.transform = 'scale(1)';
                }, 300);
                
            } else {
                notificationBadge.classList.add('hidden');
            }
        }
    }

    window.markAllAsRead = function() {
        // Mark all notifications as read in backend
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
                // Clear all notifications
                notifications = [];
                unreadCount = data.unread_count;
                
                // Update UI
                updateBadge();
                renderNotifications();
                
                // Show success feedback
                showNotificationAction(data.message + ' ✅', 'success');
                
                console.log('All notifications marked as read, updated count:', unreadCount);
            }
        })
        .catch(error => {
            console.error('Error marking all notifications as read:', error);
            // Fallback to local update
            notifications = [];
            unreadCount = 0;
            updateBadge();
            renderNotifications();
            showNotificationAction('All notifications marked as read! ✅', 'success');
        });
    };
    
    window.clearAllNotifications = function() {
        // Clear all notifications in backend
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
                // Clear all notifications
                notifications = [];
                unreadCount = data.unread_count;
                
                // Update UI
                updateBadge();
                renderNotifications();
                
                // Show success feedback
                showNotificationAction(data.message + ' 🗑️', 'success');
                
                console.log('All notifications cleared, updated count:', unreadCount);
            }
        })
        .catch(error => {
            console.error('Error clearing all notifications:', error);
            // Fallback to local update
            notifications = [];
            unreadCount = 0;
            updateBadge();
            renderNotifications();
            showNotificationAction('All notifications cleared! 🗑️', 'success');
        });
    };
    </script>

    <!-- Exercise Details Modal -->
    <div id="exerciseDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4 overflow-hidden">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[60vh] flex flex-col">
            <!-- Fixed Header -->
            <div id="exerciseModalHeader" class="flex-shrink-0 px-4 py-3 border-b border-gray-200">
                <!-- Header will be loaded here -->
            </div>
            
            <!-- Scrollable Content -->
            <div id="exerciseModalContent" class="flex-1 overflow-y-auto px-4 py-3">
                <!-- Content will be dynamically loaded here -->
            </div>
            
            <!-- Fixed Footer -->
            <div id="exerciseModalFooter" class="flex-shrink-0 px-4 py-3 border-t border-gray-200 text-center">
                <!-- Footer will be loaded here -->
            </div>
        </div>
    </div>

    <?php include 'recommendations_modal.php'; ?>
</body>
</html>
<?php include 'footer.php'; ?> 