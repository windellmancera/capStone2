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

            <!-- Add/Edit Trainer Form -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h2 id="formTitle" class="text-2xl font-semibold text-gray-800 mb-4">Add New Trainer</h2>
                <form id="trainerForm" action="" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="trainer_id" value="">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
                            <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Specialization *</label>
                            <input type="text" name="specialization" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Years of Experience *</label>
                            <input type="number" name="experience_years" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                            <input type="tel" name="contact_number" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Profile Image</label>
                            <input type="file" name="image" accept="image/*" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Price per Hour (₱)</label>
                            <input type="number" name="hourly_rate" step="0.01" min="0" value="50.00" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Bio</label>
                        <textarea name="bio" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                    </div>
                    <div class="flex justify-between">
                        <button type="button" onclick="resetForm()" class="px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200">
                            Cancel
                        </button>
                        <button type="submit" id="submitButton" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                            Add Trainer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Trainers List -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-2xl font-semibold text-gray-800 mb-4">Current Trainers</h2>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Name</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Specialization</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Experience</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Price/Hour</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Status</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-600">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if ($trainers && $trainers->num_rows > 0): ?>
                                <?php while($trainer = $trainers->fetch_assoc()): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center space-x-3">
                                                <?php if (!empty($trainer['image_url'])): ?>
                                                    <img src="<?php echo htmlspecialchars('../' . $trainer['image_url']); ?>" alt="<?php echo htmlspecialchars($trainer['name']); ?>" class="w-10 h-10 rounded-full object-cover">
                                                <?php else: ?>
                                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-medium text-gray-800"><?php echo htmlspecialchars($trainer['name']); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($trainer['email'] ?? 'No email'); ?></div>
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
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $trainer['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($trainer['status'] ?? 'active')); ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center space-x-2">
                                                <button onclick="editTrainer(<?php echo $trainer['id']; ?>)" class="text-blue-600 hover:text-blue-800">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="deleteTrainer(<?php echo $trainer['id']; ?>)" class="text-red-600 hover:text-red-800">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-3 text-center text-gray-500">No trainers found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
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
    </script>
</body>
</html> 
