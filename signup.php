<?php
session_start();
require 'db.php';

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Basic Information
    $full_name = trim($_POST["full_name"]);
    $mobile_number = trim($_POST["mobile_number"]);
    $gender = $_POST["gender"];
    $home_address = trim($_POST["home_address"]);
    $email = trim($_POST["email"]);
    $date_of_birth = $_POST["date_of_birth"];
    
    // Account Details
    $username = trim($_POST["username"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];
    
    // Profile Picture
    $profile_picture = "";
    
    // Emergency Contact
    $emergency_contact_name = trim($_POST["emergency_contact_name"]);
    $emergency_contact_number = trim($_POST["emergency_contact_number"]);
    $emergency_contact_relationship = trim($_POST["emergency_contact_relationship"]);
    
    // Validation
    if (empty($full_name) || empty($mobile_number) || empty($gender) || empty($home_address) || 
        empty($email) || empty($date_of_birth) || empty($username) || empty($password) ||
        empty($emergency_contact_name) || empty($emergency_contact_number) || empty($emergency_contact_relationship)) {
        $error = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // Check if email already exists
        $check_email = "SELECT * FROM users WHERE email = ?";
        $check_stmt = $conn->prepare($check_email);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "This email is already registered. Please use a different email or login.";
        } else {
            // Check if username already exists
            $check_username = "SELECT * FROM users WHERE username = ?";
            $check_username_stmt = $conn->prepare($check_username);
            $check_username_stmt->bind_param("s", $username);
            $check_username_stmt->execute();
            $username_result = $check_username_stmt->get_result();
            
            if ($username_result->num_rows > 0) {
                $error = "This username is already taken. Please choose a different username.";
            } else {
                // File upload handling
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
                    $upload_dir = __DIR__ . '/uploads/profile_pictures/'; // Use absolute path
                    
                    // Create directory if it doesn't exist
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    // Get file extension
                    $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    
                    // Generate unique filename
                    $new_filename = uniqid() . '.' . $file_extension;
                    $target_file = $upload_dir . $new_filename;
                    
                    // Validate file type
                    $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($file_extension, $allowed_types)) {
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                            $profile_picture = $new_filename;
                        } else {
                            $error = "Failed to upload profile picture. Please try again. Error: " . error_get_last()['message'];
                            goto display_form;
                        }
                    } else {
                        $error = "Invalid file type. Only JPG, PNG, and GIF files are allowed.";
                        goto display_form;
                    }
                }
                
                if (empty($error)) {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'member'; // Set default role as member
                    
                    // Check if enhanced columns exist
                    $check_columns = "SHOW COLUMNS FROM users LIKE 'full_name'";
                    $columns_result = $conn->query($check_columns);
                    
                    if ($columns_result->num_rows > 0) {
                        // Enhanced columns exist, use full insert
                        $sql = "INSERT INTO users (username, full_name, mobile_number, gender, home_address, email, 
                                                 date_of_birth, profile_picture, password, role, 
                                                 emergency_contact_name, emergency_contact_number, emergency_contact_relationship) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        
                        if ($stmt) {
                            $stmt->bind_param("sssssssssssss", 
                                $username, $full_name, $mobile_number, $gender, $home_address, $email, 
                                $date_of_birth, $profile_picture, $hashed_password, $role,
                                $emergency_contact_name, $emergency_contact_number, $emergency_contact_relationship
                            );
                        } else {
                            $error = "Error: Could not prepare enhanced statement.";
                        }
                    } else {
                        // Enhanced columns don't exist, use basic insert
                        $sql = "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)";
                        $stmt = $conn->prepare($sql);
                        
                        if ($stmt) {
                            $stmt->bind_param("ssss", $username, $email, $hashed_password, $role);
                        } else {
                            $error = "Error: Could not prepare basic statement.";
                        }
                    }
                    
                    if ($stmt && $stmt->execute()) {
                        $_SESSION["user_id"] = $stmt->insert_id;
                        $_SESSION["email"] = $email;
                        $_SESSION["role"] = $role;
                        $_SESSION["username"] = $username;
                        $success = "Account created successfully! Redirecting to dashboard...";
                        header("Location: member/homepage.php");
                    } else {
                        if ($stmt) {
                            $error = "Error: Could not create account. Database error: " . $stmt->error;
                        } else {
                            $error = "Error: Could not create account. Database error: " . $conn->error;
                        }
                        error_log("Database Error in signup.php: " . ($stmt ? $stmt->error : $conn->error));
                    }
                }
            }
        }
    }
}

display_form:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Almo Fitness Gym</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: url('https://www.pixelstalk.net/wp-content/uploads/2016/06/Black-And-Red-Background-HD.jpg') no-repeat center center fixed;
            background-size: cover;
        }
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: -1;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-4xl">
        <div class="backdrop-blur-xl bg-white/10 rounded-3xl shadow-2xl border border-white/20 p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <img src="image/almo.jpg" alt="Almo Logo" class="w-20 h-20 rounded-full mx-auto mb-4 border-4 border-white/30">
                <h1 class="text-3xl font-bold text-white mb-2">Create Your Account</h1>
                <p class="text-white/80">Join Almo Fitness Gym and start your fitness journey today!</p>
            </div>

            <!-- Database Update Notice -->
            <?php
            $check_columns = "SHOW COLUMNS FROM users LIKE 'full_name'";
            $columns_result = $conn->query($check_columns);
            if ($columns_result->num_rows == 0): ?>
                <div class="bg-yellow-500/20 border border-yellow-500/50 text-yellow-200 p-4 rounded-lg mb-6 text-center">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Database Update Required:</strong> Please run the database update first. 
                    <a href="admin/fix_database.php" class="text-yellow-300 underline ml-2">Click here to update database</a>
                </div>
            <?php endif; ?>

            <!-- Error/Success Messages -->
            <?php if (!empty($error)): ?>
                <div class="bg-red-500/20 border border-red-500/50 text-red-200 p-4 rounded-lg mb-6 text-center">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="bg-green-500/20 border border-green-500/50 text-green-200 p-4 rounded-lg mb-6 text-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success; ?>
                    <div class="mt-3 text-sm">
                        <p class="mb-2">Your account has been created successfully! Here's what happens next:</p>
                        <ul class="text-left max-w-md mx-auto space-y-1">
                            <li><i class="fas fa-arrow-right mr-2"></i>You'll be redirected to your dashboard</li>
                            <li><i class="fas fa-arrow-right mr-2"></i>Complete your profile information</li>
                            <li><i class="fas fa-arrow-right mr-2"></i>Choose a membership plan</li>
                            <li><i class="fas fa-arrow-right mr-2"></i>Start your fitness journey!</li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Signup Form -->
            <form method="post" action="" enctype="multipart/form-data" class="space-y-8" id="signupForm">
                <!-- Progress Indicator -->
                <div class="bg-white/5 rounded-2xl p-6 border border-white/10">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">Form Progress</h3>
                        <span class="text-white/70 text-sm" id="progressText">1 of 4</span>
                    </div>
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full transition-all duration-300" id="progressBar" style="width: 25%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-white/60 mt-2">
                        <span>Basic Info</span>
                        <span>Emergency Contact</span>
                        <span>Account Details</span>
                        <span>Profile Picture</span>
                    </div>
                </div>
                <!-- Basic Information Section -->
                <div class="bg-white/5 rounded-2xl p-6 border border-white/10">
                    <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                        <i class="fas fa-user-circle mr-3 text-blue-400"></i>
                        Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Full Name -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-user mr-2"></i>Full Name
                                <span class="text-red-400 ml-1">*</span>
                            </label>
                            <input type="text" name="full_name" value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Enter your full legal name" required>
                            <p class="text-white/60 text-xs mt-1">
                                <i class="fas fa-info-circle mr-1"></i>Enter your complete legal name as it appears on official documents
                            </p>
                        </div>

                        <!-- Mobile Number -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-phone mr-2"></i>Mobile Number
                                <span class="text-red-400 ml-1">*</span>
                            </label>
                            <input type="tel" name="mobile_number" value="<?php echo isset($_POST['mobile_number']) ? htmlspecialchars($_POST['mobile_number']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="09123456789" required>
                            <p class="text-white/60 text-xs mt-1">
                                <i class="fas fa-info-circle mr-1"></i>Format: 09123456789 or +63 912 345 6789
                            </p>
                        </div>

                        <!-- Gender -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-venus-mars mr-2"></i>Gender
                            </label>
                            <select name="gender" class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" required>
                                <option value="" class="bg-gray-800">Select your gender</option>
                                <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'male') ? 'selected' : ''; ?> class="bg-gray-800">Male</option>
                                <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'female') ? 'selected' : ''; ?> class="bg-gray-800">Female</option>
                                <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] == 'other') ? 'selected' : ''; ?> class="bg-gray-800">Other</option>
                            </select>
                        </div>

                        <!-- Date of Birth -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-calendar mr-2"></i>Date of Birth
                            </label>
                            <input type="date" name="date_of_birth" value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" required>
                        </div>

                        <!-- Email Address -->
                        <div class="md:col-span-2">
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-envelope mr-2"></i>Email Address
                            </label>
                            <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Enter your email address" required>
                        </div>

                        <!-- Home Address -->
                        <div class="md:col-span-2">
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-home mr-2"></i>Home Address
                            </label>
                            <textarea name="home_address" rows="3" 
                                      class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all resize-none" 
                                      placeholder="Enter your complete home address" required><?php echo isset($_POST['home_address']) ? htmlspecialchars($_POST['home_address']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contact Section -->
                <div class="bg-white/5 rounded-2xl p-6 border border-white/10">
                    <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                        <i class="fas fa-ambulance mr-3 text-red-400"></i>
                        Emergency Contact
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Emergency Contact Name -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-user-plus mr-2"></i>Contact Name
                            </label>
                            <input type="text" name="emergency_contact_name" value="<?php echo isset($_POST['emergency_contact_name']) ? htmlspecialchars($_POST['emergency_contact_name']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Emergency contact person" required>
                        </div>

                        <!-- Emergency Contact Number -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-phone-alt mr-2"></i>Contact Number
                            </label>
                            <input type="tel" name="emergency_contact_number" value="<?php echo isset($_POST['emergency_contact_number']) ? htmlspecialchars($_POST['emergency_contact_number']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Emergency contact number" required>
                        </div>

                        <!-- Emergency Contact Relationship -->
                        <div class="md:col-span-2">
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-heart mr-2"></i>Relationship
                            </label>
                            <select name="emergency_contact_relationship" class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" required>
                                <option value="" class="bg-gray-800">Select relationship</option>
                                <option value="spouse" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'spouse') ? 'selected' : ''; ?> class="bg-gray-800">Spouse</option>
                                <option value="parent" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'parent') ? 'selected' : ''; ?> class="bg-gray-800">Parent</option>
                                <option value="sibling" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'sibling') ? 'selected' : ''; ?> class="bg-gray-800">Sibling</option>
                                <option value="child" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'child') ? 'selected' : ''; ?> class="bg-gray-800">Child</option>
                                <option value="friend" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'friend') ? 'selected' : ''; ?> class="bg-gray-800">Friend</option>
                                <option value="relative" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'relative') ? 'selected' : ''; ?> class="bg-gray-800">Relative</option>
                                <option value="other" <?php echo (isset($_POST['emergency_contact_relationship']) && $_POST['emergency_contact_relationship'] == 'other') ? 'selected' : ''; ?> class="bg-gray-800">Other</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Account Details Section -->
                <div class="bg-white/5 rounded-2xl p-6 border border-white/10">
                    <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                        <i class="fas fa-shield-alt mr-3 text-green-400"></i>
                        Account Details
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Username -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-user-tag mr-2"></i>Username
                            </label>
                            <input type="text" name="username" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Choose a username" required>
                        </div>

                        <!-- Password -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-lock mr-2"></i>Password
                            </label>
                            <input type="password" name="password" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Create a password" required>
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-lock mr-2"></i>Confirm Password
                            </label>
                            <input type="password" name="confirm_password" 
                                   class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:border-transparent transition-all" 
                                   placeholder="Confirm your password" required>
                        </div>
                    </div>
                </div>

                <!-- Profile Picture Section -->
                <div class="bg-white/5 rounded-2xl p-6 border border-white/10">
                    <h2 class="text-xl font-semibold text-white mb-6 flex items-center">
                        <i class="fas fa-camera mr-3 text-purple-400"></i>
                        Profile Picture
                    </h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-white/90 text-sm font-medium mb-2">
                                <i class="fas fa-upload mr-2"></i>Upload Profile Picture
                            </label>
                            <div class="flex items-center space-x-4">
                                <div class="flex-1">
                                    <input type="file" name="profile_picture" id="profile_picture" accept=".jpg,.jpeg,.png" 
                                           class="hidden" onchange="previewImage(this)">
                                    <label for="profile_picture" 
                                           class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-lg text-white/70 hover:bg-white/20 cursor-pointer transition-all flex items-center justify-center">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i>
                                        Choose File (JPG, JPEG, PNG)
                                    </label>
                                </div>
                                <div id="image_preview" class="hidden">
                                    <img id="preview_img" src="" alt="Preview" class="w-16 h-16 rounded-full object-cover border-2 border-white/30">
                                </div>
                            </div>
                            <p class="text-white/60 text-sm mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Optional: Upload a profile picture (JPG, JPEG, or PNG format)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="text-center">
                    <button type="submit" 
                            class="w-full md:w-auto px-8 py-4 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700 text-white font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                        <i class="fas fa-user-plus mr-2"></i>
                        Create Account
                    </button>
                </div>
            </form>

            <!-- Login Link -->
            <div class="text-center mt-8">
                <p class="text-white/80">
                    Already have an account? 
                    <a href="index.php" class="text-blue-400 hover:text-blue-300 font-medium transition-colors">
                        <i class="fas fa-sign-in-alt mr-1"></i>Log in here
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function previewImage(input) {
            const preview = document.getElementById('image_preview');
            const previewImg = document.getElementById('preview_img');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    preview.classList.remove('hidden');
                }
                
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.classList.add('hidden');
            }
        }

        // Form validation
        function validateSignupForm() {
            const form = document.querySelector('form');
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            // Clear previous error states
            clearFormErrors();

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    showFieldError(field, 'This field is required');
                    isValid = false;
                } else {
                    // Specific validations
                    if (field.name === 'mobile_number' && !/^[0-9+\-\s()]{7,15}$/.test(field.value.trim())) {
                        showFieldError(field, 'Please enter a valid mobile number');
                        isValid = false;
                    }
                    
                    if (field.name === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value.trim())) {
                        showFieldError(field, 'Please enter a valid email address');
                        isValid = false;
                    }
                    
                    if (field.name === 'date_of_birth') {
                        const birthDate = new Date(field.value);
                        const today = new Date();
                        const age = today.getFullYear() - birthDate.getFullYear();
                        if (age < 13) {
                            showFieldError(field, 'You must be at least 13 years old');
                            isValid = false;
                        }
                    }
                    
                    if (field.name === 'password' && field.value.length < 6) {
                        showFieldError(field, 'Password must be at least 6 characters long');
                        isValid = false;
                    }
                    
                    if (field.name === 'confirm_password' && field.value !== form.querySelector('[name="password"]').value) {
                        showFieldError(field, 'Passwords do not match');
                        isValid = false;
                    }
                }
            });

            return isValid;
        }

        function showFieldError(field, message) {
            field.classList.add('border-red-500', 'focus:ring-red-400');
            
            // Remove existing error message
            const existingError = field.parentNode.querySelector('.field-error');
            if (existingError) {
                existingError.remove();
            }
            
            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'field-error text-red-400 text-sm mt-1';
            errorDiv.textContent = message;
            field.parentNode.appendChild(errorDiv);
        }

        function clearFormErrors() {
            const fields = document.querySelectorAll('input, textarea, select');
            fields.forEach(field => {
                field.classList.remove('border-red-500', 'focus:ring-red-400');
            });
            
            const errors = document.querySelectorAll('.field-error');
            errors.forEach(error => error.remove());
        }

        // Add form submission validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                if (!validateSignupForm()) {
                    e.preventDefault();
                    // Scroll to first error
                    const firstError = document.querySelector('.field-error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });

            // Update progress bar as form is filled
            function updateProgress() {
                const requiredFields = form.querySelectorAll('[required]');
                const filledFields = Array.from(requiredFields).filter(field => field.value.trim() !== '');
                const progress = (filledFields.length / requiredFields.length) * 100;
                
                document.getElementById('progressBar').style.width = progress + '%';
                document.getElementById('progressText').textContent = filledFields.length + ' of ' + requiredFields.length;
                
                // Update progress bar color based on completion
                const progressBar = document.getElementById('progressBar');
                if (progress >= 100) {
                    progressBar.className = 'bg-gradient-to-r from-green-500 to-green-600 h-2 rounded-full transition-all duration-300';
                } else if (progress >= 75) {
                    progressBar.className = 'bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full transition-all duration-300';
                } else if (progress >= 50) {
                    progressBar.className = 'bg-gradient-to-r from-yellow-500 to-orange-500 h-2 rounded-full transition-all duration-300';
                } else {
                    progressBar.className = 'bg-gradient-to-r from-red-500 to-pink-500 h-2 rounded-full transition-all duration-300';
                }
            }

            // Add some interactive effects
            const inputs = document.querySelectorAll('input, textarea, select');
            
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('scale-105');
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('scale-105');
                    updateProgress();
                });
                
                input.addEventListener('input', updateProgress);
            });
            
            // Initial progress update
            updateProgress();
        });
    </script>
</body>
</html>
