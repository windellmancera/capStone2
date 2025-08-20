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

// Get user information from database
$user_id = $_SESSION['user_id'];
$user = getUserPaymentStatus($conn, $user_id);

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
    $file_extension = strtolower(pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION));
    $new_filename = "profile_" . $user_id . "_" . time() . "." . $file_extension;
    $target_file = $target_dir . $new_filename;
    
    // Check if image file is a actual image or fake image
    $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
    if($check !== false) {
        // Allow certain file formats
        if($file_extension == "jpg" || $file_extension == "jpeg" || $file_extension == "png" || $file_extension == "gif") {
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                // Update database with new profile picture
                $update_sql = "UPDATE users SET profile_picture = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $new_filename, $user_id);
                $update_stmt->execute();
                
                // Redirect to refresh the page
                header("Location: profile.php?success=1");
                exit();
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
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = $current_user['username'] ?? $current_user['email'] ?? 'User';
$page_title = 'Profile';

// Check if user has an active membership plan (not daily)
$membership_sql = "SELECT ph.id as payment_id, mp.id as plan_id, mp.duration, mp.name as plan_name,
                        DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as expiry_date
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Almo Fitness</title>
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

            <!-- Main Content Container -->
            <div class="flex flex-col gap-6">
                <!-- Profile Information Row -->
                <div class="flex flex-col lg:flex-row gap-6">
                    <!-- Left Column - Profile Picture and Demographics -->
                    <div class="flex-1 flex flex-col gap-6">
                        <!-- Profile Picture Box -->
                        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                <i class="fas fa-user-circle text-red-500 mr-2"></i>Profile Picture
                            </h3>
                            <div class="flex flex-col items-center">
                                <div class="relative mb-2">
                                    <img src="<?php echo !empty($user['profile_picture']) ? '../uploads/profile_pictures/' . htmlspecialchars($user['profile_picture']) : '../image/default-avatar.png'; ?>" 
                                         alt="Profile Picture" 
                                         class="w-36 h-36 rounded-full object-cover border-2 border-white shadow-md ring-2 ring-red-200">
                                    <div class="absolute -bottom-1 -right-1 h-6 w-6 bg-green-400 rounded-full border-2 border-white flex items-center justify-center shadow-sm">
                                        <i class="fas fa-check text-xs text-white"></i>
                                    </div>
                                </div>
                                
                                <form action="profile.php" method="POST" enctype="multipart/form-data" class="w-full">
                                    <div class="flex flex-col items-center">
                                        <label class="w-auto flex items-center px-3 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-md shadow-sm tracking-wide uppercase border border-red-600 cursor-pointer hover:from-red-600 hover:to-red-700 transition-all duration-300 transform hover:scale-105">
                                            <i class="fas fa-cloud-upload-alt mr-2 text-xs"></i>
                                            <span class="text-xs font-bold leading-normal">Choose a file</span>
                                            <input type="file" name="profile_picture" class="hidden" 
                                                   accept=".jpg,.jpeg,.png,.gif"
                                                   onchange="this.form.submit()">
                                        </label>
                                        <p class="text-xs text-gray-500 mt-1 font-medium">Supported formats: JPG, PNG, GIF</p>
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
                                    <?php if ($user['qr_code'] && file_exists("../uploads/qr_codes/" . $user['qr_code'])): ?>
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
                                <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                                    <i class="fas fa-user text-red-500 mr-3"></i>Demographics
                                </h3>
                                <button onclick="toggleEdit('demographics')" class="text-red-600 hover:text-red-700 font-medium transition-colors duration-200">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </button>
                            </div>
                            
                            <!-- View Mode -->
                            <div id="demographics-view" class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600">Full Name</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['full_name'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Mobile Number</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['mobile_number'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Gender</p>
                                        <p class="font-medium"><?php echo htmlspecialchars(ucfirst($user['gender']) ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Date of Birth</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['date_of_birth'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div class="col-span-2">
                                        <p class="text-sm text-gray-600">Home Address</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['home_address'] ?? 'Not set'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <div id="demographics-edit" class="hidden">
                                <form id="demographics-form" class="space-y-4">
                                    <input type="hidden" name="update_type" value="demographics">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Full Name</label>
                                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Mobile Number</label>
                                            <input type="tel" name="mobile_number" value="<?php echo htmlspecialchars($user['mobile_number'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Gender</label>
                                            <select name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                                <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                                <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                                <option value="other" <?php echo ($user['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Date of Birth</label>
                                            <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-600 mb-1">Home Address</label>
                                            <textarea name="home_address" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500" 
                                                      rows="2"><?php echo htmlspecialchars($user['home_address'] ?? ''); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="flex justify-end space-x-2 mt-4">
                                        <button type="button" onclick="toggleEdit('demographics')" 
                                                class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
                                            Cancel
                                        </button>
                                        <button type="submit" 
                                                class="px-4 py-2 text-white bg-red-600 rounded-lg hover:bg-red-700">
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Emergency Contact Box -->
                        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-8 hover:shadow-2xl transition-all duration-300">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-2xl font-bold text-gray-800 flex items-center">
                                    <i class="fas fa-phone-alt text-red-500 mr-3"></i>Emergency Contact
                                </h3>
                                <button onclick="toggleEdit('emergency')" class="text-red-600 hover:text-red-700 font-medium transition-colors duration-200">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </button>
                            </div>

                            <!-- View Mode -->
                            <div id="emergency-view" class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600">Contact Name</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['emergency_contact_name'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600">Contact Number</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['emergency_contact_number'] ?? 'Not set'); ?></p>
                                    </div>
                                    <div class="col-span-2">
                                        <p class="text-sm text-gray-600">Relationship</p>
                                        <p class="font-medium"><?php echo htmlspecialchars($user['emergency_contact_relationship'] ?? 'Not set'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Edit Mode -->
                            <div id="emergency-edit" class="hidden">
                                <form id="emergency-form" class="space-y-4">
                                    <input type="hidden" name="update_type" value="emergency_contact">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Contact Name</label>
                                            <input type="text" name="emergency_contact_name" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                        <div>
                                            <label class="block text-sm text-gray-600 mb-1">Contact Number</label>
                                            <input type="tel" name="emergency_contact_number" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_number'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                        <div class="col-span-2">
                                            <label class="block text-sm text-gray-600 mb-1">Relationship</label>
                                            <input type="text" name="emergency_contact_relationship" 
                                                   value="<?php echo htmlspecialchars($user['emergency_contact_relationship'] ?? ''); ?>" 
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-red-500">
                                        </div>
                                    </div>
                                    <div class="flex justify-end space-x-2 mt-4">
                                        <button type="button" onclick="toggleEdit('emergency')" 
                                                class="px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
                                            Cancel
                                        </button>
                                        <button type="submit" 
                                                class="px-4 py-2 text-white bg-red-600 rounded-lg hover:bg-red-700">
                                            Save Changes
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
                                <?php if (!empty($user['membership_type'])): ?>
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
                                            <span class="font-bold text-green-600"><?php echo htmlspecialchars($user['membership_type']); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Status:</span>
                                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold"><?php echo getMembershipStatusBadge($user); ?></span>
                                        </div>
                                        <?php if (!empty($user['membership_start_date'])): ?>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Started:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($user['membership_start_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user['membership_end_date'])): ?>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Expires:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Days Remaining:</span>
                                            <span class="font-bold text-green-600"><?php echo getMembershipDaysRemaining($user); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Active Membership -->
                                <?php if (!empty($user['plan_name']) && $user['payment_status'] === 'Approved'): ?>
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
                                            <span class="font-bold text-green-600"><?php echo htmlspecialchars($user['plan_name']); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Status:</span>
                                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-bold">
                                                Active
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Price:</span>
                                            <span class="font-bold text-green-600">₱<?php echo number_format($user['plan_price'] ?? 0, 2); ?></span>
                                        </div>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Duration:</span>
                                            <span class="font-bold text-gray-800"><?php echo $user['plan_duration'] ?? 0; ?> days</span>
                                        </div>
                                        <?php if (!empty($user['membership_end_date'])): ?>
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-600 font-medium">Expires:</span>
                                            <span class="font-bold text-gray-800"><?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
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
                            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                <i class="fas fa-dumbbell text-red-500 mr-2"></i>Fitness Goals
                            </h3>
                            <?php 
                            $fitness_goals = $predictive_analysis->getFitnessGoalsRecommendations();
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

                                <?php if (isset($fitness_goals['fitness'])): ?>
                                <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-md p-3 border border-green-200 shadow-sm hover:shadow-md transition-all duration-300">
                                    <div class="flex justify-between items-center mb-2">
                                        <div class="flex items-center">
                                            <div class="p-1 rounded-full bg-green-500 text-white mr-2">
                                                <i class="fas fa-bullseye text-xs"></i>
                                            </div>
                                            <h5 class="text-xs font-bold text-gray-700">Fitness Goal</h5>
                                        </div>
                                        <span class="text-sm font-bold text-green-600"><?php echo htmlspecialchars($fitness_goals['fitness']['current_goal']); ?></span>
                                    </div>
                                    <div class="text-xs text-gray-600 space-y-1">
                                        <?php foreach ($fitness_goals['fitness']['recommendations'] as $recommendation): ?>
                                        <p class="flex items-start">
                                            <i class="fas fa-check-circle text-green-500 mr-1 mt-0.5 flex-shrink-0 text-xs"></i>
                                            <?php echo htmlspecialchars($recommendation); ?>
                                        </p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
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
                                    <button onclick="openRecommendationsModal()" class="ml-auto text-purple-600 hover:text-purple-800 text-sm font-medium flex items-center">
                                        <i class="fas fa-external-link-alt mr-1"></i>View Full Recommendations
                                    </button>
                                </h3>
                            <?php 
                            $workout_recommendations = $predictive_analysis->getWorkoutRecommendations();
                            ?>
                            
                            <!-- Workout Schedule -->
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4 mb-4 border border-purple-200 shadow-sm hover:shadow-md transition-all duration-300">
                                <div class="flex items-center mb-3">
                                    <div class="p-2 rounded-full bg-purple-500 text-white mr-2">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <h5 class="text-sm font-bold text-gray-700">Recommended Schedule</h5>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="bg-white rounded-md p-3 shadow-sm">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Frequency:</p>
                                        <p class="text-base font-bold text-purple-600"><?php echo $workout_recommendations['workout_plan']['frequency']; ?></p>
                                    </div>
                                    <div class="bg-white rounded-md p-3 shadow-sm">
                                        <p class="text-xs text-gray-600 font-medium mb-1">Duration:</p>
                                        <p class="text-base font-bold text-purple-600"><?php echo $workout_recommendations['workout_plan']['duration']; ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Workout Split -->
                            <div class="bg-gradient-to-br from-indigo-50 to-indigo-100 rounded-lg p-4 mb-4 border border-indigo-200 shadow-sm hover:shadow-md transition-all duration-300">
                                <div class="flex items-center mb-3">
                                    <div class="p-2 rounded-full bg-indigo-500 text-white mr-2">
                                        <i class="fas fa-list-ul"></i>
                                    </div>
                                    <h5 class="text-sm font-bold text-gray-700">Weekly Split</h5>
                                </div>
                                <div class="grid grid-cols-1 gap-2">
                                    <?php foreach ($workout_recommendations['workout_plan']['split']['schedule'] as $day => $workout): ?>
                                    <div class="flex justify-between items-center bg-white rounded-md p-2 shadow-sm">
                                        <span class="text-xs font-medium text-gray-700"><?php echo $day; ?></span>
                                        <span class="text-xs font-bold text-indigo-600"><?php echo $workout; ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Exercise Types -->
                            <div class="bg-gradient-to-br from-teal-50 to-teal-100 rounded-lg p-4 border border-teal-200 shadow-sm hover:shadow-md transition-all duration-300">
                                <div class="flex items-center mb-3">
                                    <div class="p-2 rounded-full bg-teal-500 text-white mr-2">
                                        <i class="fas fa-dumbbell"></i>
                                    </div>
                                    <h5 class="text-sm font-bold text-gray-700">Recommended Exercises</h5>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-xs text-gray-600 font-medium mb-1">Primary Focus:</p>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach ($workout_recommendations['exercise_types']['primary'] as $exercise): ?>
                                            <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full font-medium shadow-sm">
                                                <?php echo htmlspecialchars($exercise); ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-xs text-gray-600 font-medium mb-1">Secondary Focus:</p>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach ($workout_recommendations['exercise_types']['secondary'] as $exercise): ?>
                                            <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full font-medium shadow-sm">
                                                <?php echo htmlspecialchars($exercise); ?>
                                            </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Attendance Patterns Box -->
                            <div class="bg-white rounded-lg shadow-md border border-gray-100 p-4 hover:shadow-lg transition-all duration-300">
                                <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                                    <i class="fas fa-chart-pie text-pink-500 mr-2"></i>Attendance Patterns
                                </h3>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="bg-gradient-to-br from-pink-50 to-pink-100 rounded-lg p-4 border border-pink-200 shadow-sm hover:shadow-md transition-all duration-300">
                                        <div class="flex items-center mb-3">
                                            <div class="p-2 rounded-full bg-pink-500 text-white mr-2">
                                                <i class="fas fa-calendar-day text-xs"></i>
                                            </div>
                                            <h5 class="text-sm font-bold text-gray-700">Preferred Days</h5>
                                        </div>
                                        <div class="space-y-2">
                                            <?php foreach ($member_analytics['attendance_patterns']['preferred_days'] as $day): ?>
                                            <div class="bg-white rounded-md px-3 py-2 shadow-sm">
                                                <span class="text-xs font-bold text-pink-600"><?php echo $day; ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200 shadow-sm hover:shadow-md transition-all duration-300">
                                        <div class="flex items-center mb-3">
                                            <div class="p-2 rounded-full bg-purple-500 text-white mr-2">
                                                <i class="fas fa-clock text-xs"></i>
                                            </div>
                                            <h5 class="text-sm font-bold text-gray-700">Preferred Hours</h5>
                                        </div>
                                        <div class="space-y-2">
                                            <?php foreach ($member_analytics['attendance_patterns']['preferred_hours'] as $hour): ?>
                                            <div class="bg-white rounded-md px-3 py-2 shadow-sm">
                                                <span class="text-xs font-bold text-purple-600"><?php echo $hour; ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Monthly Visit Stats -->
                                <div class="mt-4">
                                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200 shadow-sm hover:shadow-md transition-all duration-300">
                                        <div class="flex items-center mb-3">
                                            <div class="p-2 rounded-full bg-orange-500 text-white mr-2">
                                                <i class="fas fa-chart-bar text-xs"></i>
                                            </div>
                                            <h5 class="text-sm font-bold text-gray-700">Monthly Statistics</h5>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div class="bg-white rounded-md p-3 shadow-sm">
                                                <p class="text-xs text-gray-600 font-medium mb-1">Total Visits</p>
                                                <p class="text-base font-bold text-orange-600">
                                                    <?php echo $member_analytics['attendance_patterns']['total_visits']; ?>
                                                </p>
                                            </div>
                                            <div class="bg-white rounded-md p-3 shadow-sm">
                                                <p class="text-xs text-gray-600 font-medium mb-1">Average Duration</p>
                                                <p class="text-base font-bold text-orange-600">
                                                    <?php echo $member_analytics['attendance_patterns']['avg_duration']; ?> min
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

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
        await submitForm(e.target);
    });

    document.getElementById('emergency-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        await submitForm(e.target);
    });

    async function submitForm(form) {
        try {
            const formData = new FormData(form);
            const response = await fetch('update_profile.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                showToast(result.message, true);
                location.reload(); // Reload to show updated information
            } else {
                showToast(result.message, false);
            }
        } catch (error) {
            showToast('An error occurred while updating the profile', false);
        }
    }

    // QR Code Auto-refresh
    let qrRefreshInterval;
    
    function refreshQRCode() {
        const container = document.getElementById('qrCodeContainer');
        const button = document.querySelector('button[onclick="refreshQRCode()"]');
        
        // Show loading state
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Refreshing...';
        
        fetch('generate_qr.php')
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
    </script>
    <?php include 'recommendations_modal.php'; ?>
</body>
</html>
<?php include 'footer.php'; ?> 