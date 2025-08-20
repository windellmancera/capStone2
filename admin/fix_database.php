<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Add confirmation step to prevent accidental execution
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Database Fix Tool - Confirmation Required</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .danger { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .btn { display: inline-block; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-secondary { background: #6c757d; color: white; margin-left: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>⚠️ Database Fix Tool - Confirmation Required</h2>
            
            <div class='warning'>
                <strong>Warning:</strong> This tool will modify your database structure and may affect your system.
            </div>
            
            <div class='danger'>
                <strong>⚠️ CRITICAL:</strong> This tool will:
                <ul>
                    <li>Add new columns to existing tables</li>
                    <li>Create new tables if they don't exist</li>
                    <li>Insert sample data</li>
                    <li>Modify database structure</li>
                </ul>
            </div>
            
            <p><strong>Are you absolutely sure you want to proceed?</strong></p>
            
            <a href='?confirm=yes' class='btn btn-danger'>CONFIRM - I understand the risks</a>
            <a href='dashboard.php' class='btn btn-secondary'>Cancel - Go back to Dashboard</a>
        </div>
    </body>
    </html>";
    exit();
}

// Simple database fix script
require '../db.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Fix Tool - Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        .warning { color: orange; }
        .btn { display: inline-block; padding: 10px 20px; text-decoration: none; background: #007bff; color: white; border-radius: 5px; margin: 10px 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>🔧 Database Fix Results</h2>
        <p><strong>Executed by:</strong> " . htmlspecialchars($_SESSION['username'] ?? 'Admin') . " at " . date('Y-m-d H:i:s') . "</p>
        <hr>";

echo "<h2>Fixing Database - Adding Missing Columns and Tables</h2>";

// Check if columns exist and add them if they don't
$columns_to_add = [
    'full_name' => "ALTER TABLE users ADD COLUMN full_name VARCHAR(100) AFTER username",
    'mobile_number' => "ALTER TABLE users ADD COLUMN mobile_number VARCHAR(20) AFTER full_name",
    'gender' => "ALTER TABLE users ADD COLUMN gender ENUM('male', 'female', 'other') AFTER mobile_number",
    'home_address' => "ALTER TABLE users ADD COLUMN home_address TEXT AFTER gender",
    'date_of_birth' => "ALTER TABLE users ADD COLUMN date_of_birth DATE AFTER home_address",
    'profile_picture' => "ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) AFTER date_of_birth",
    'qr_code' => "ALTER TABLE users ADD COLUMN qr_code VARCHAR(255) AFTER profile_picture",
    'emergency_contact_name' => "ALTER TABLE users ADD COLUMN emergency_contact_name VARCHAR(255) AFTER qr_code",
    'emergency_contact_number' => "ALTER TABLE users ADD COLUMN emergency_contact_number VARCHAR(20) AFTER emergency_contact_name",
    'emergency_contact_relationship' => "ALTER TABLE users ADD COLUMN emergency_contact_relationship VARCHAR(50) AFTER emergency_contact_number",
    'balance' => "ALTER TABLE users ADD COLUMN balance DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Outstanding balance' AFTER emergency_contact_relationship",
    'freeze_credits' => "ALTER TABLE users ADD COLUMN freeze_credits INT DEFAULT 2 COMMENT 'Number of times a member can freeze their membership per year' AFTER balance"
];

foreach ($columns_to_add as $column_name => $sql) {
    // Check if column exists
    $check_sql = "SHOW COLUMNS FROM users LIKE '$column_name'";
    $result = $conn->query($check_sql);
    
    if ($result->num_rows == 0) {
        // Column doesn't exist, add it
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Added column: $column_name</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding column $column_name: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: blue;'>ℹ Column $column_name already exists</p>";
    }
}

// Update existing records to have default values
$update_sql = "UPDATE users SET full_name = username WHERE full_name IS NULL OR full_name = ''";
if ($conn->query($update_sql)) {
    echo "<p style='color: green;'>✓ Updated existing records with default values</p>";
} else {
    echo "<p style='color: orange;'>⚠ Could not update existing records: " . $conn->error . "</p>";
}

// Check if membership_plans table exists, if not create it
$check_table = "SHOW TABLES LIKE 'membership_plans'";
$table_result = $conn->query($check_table);

if ($table_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Membership plans table doesn't exist. Creating it...</p>";
    
    $create_membership_plans = "CREATE TABLE membership_plans (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        duration INT NOT NULL COMMENT 'Duration in days',
        price DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_membership_plans)) {
        echo "<p style='color: green;'>✓ Created membership_plans table</p>";
        
        // Insert default membership plans
        $insert_plans = "INSERT INTO membership_plans (name, description, duration, price) VALUES
        ('Daily Pass', 'Perfect for trying us out', 1, 150.00),
        ('Monthly Plan', 'Most popular choice', 30, 2000.00),
        ('Annual Plan', 'Best value for money', 365, 20000.00),
        ('Premium Plan', 'Ultimate fitness experience', 365, 30000.00)";
        
        if ($conn->query($insert_plans)) {
            echo "<p style='color: green;'>✓ Added default membership plans</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding membership plans: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating membership_plans table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Membership plans table already exists</p>";
}

// Check if payment_history table exists, if not create it
$check_payment_history = "SHOW TABLES LIKE 'payment_history'";
$payment_history_result = $conn->query($check_payment_history);

if ($payment_history_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Payment history table doesn't exist. Creating it...</p>";
    
    $create_payment_history = "CREATE TABLE payment_history (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_date DATETIME NOT NULL,
        payment_method ENUM('Cash', 'GCash', 'PayMaya', 'GoTyme') NOT NULL,
        payment_status ENUM('Pending', 'Pending Verification', 'Completed', 'Failed', 'Refunded') NOT NULL DEFAULT 'Pending',
        proof_of_payment VARCHAR(255),
        reference_number VARCHAR(100),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    
    if ($conn->query($create_payment_history)) {
        echo "<p style='color: green;'>✓ Created payment_history table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating payment_history table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Payment history table already exists</p>";
}

// Check if membership_freeze table exists, if not create it
$check_membership_freeze = "SHOW TABLES LIKE 'membership_freeze'";
$membership_freeze_result = $conn->query($check_membership_freeze);

if ($membership_freeze_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Membership freeze table doesn't exist. Creating it...</p>";
    
    $create_membership_freeze = "CREATE TABLE membership_freeze (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason TEXT,
        status ENUM('Pending', 'Approved', 'Rejected', 'Completed') NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    
    if ($conn->query($create_membership_freeze)) {
        echo "<p style='color: green;'>✓ Created membership_freeze table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating membership_freeze table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Membership freeze table already exists</p>";
}

// Check if payments table exists and has correct structure
$check_payments = "SHOW TABLES LIKE 'payments'";
$payments_result = $conn->query($check_payments);

if ($payments_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Payments table doesn't exist. Creating it...</p>";
    
    $create_payments = "CREATE TABLE payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        membership_plan_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method ENUM('Cash', 'GCash') NOT NULL,
        payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        proof_of_payment VARCHAR(255),
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        notes TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (membership_plan_id) REFERENCES membership_plans(id)
    )";
    
    if ($conn->query($create_payments)) {
        echo "<p style='color: green;'>✓ Created payments table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating payments table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Payments table already exists</p>";
}

// Check if equipment_categories table exists, if not create it
$check_equipment_categories = "SHOW TABLES LIKE 'equipment_categories'";
$equipment_categories_result = $conn->query($check_equipment_categories);

if ($equipment_categories_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Equipment categories table doesn't exist. Creating it...</p>";
    
    $create_equipment_categories = "CREATE TABLE equipment_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_equipment_categories)) {
        echo "<p style='color: green;'>✓ Created equipment_categories table</p>";
        
        // Insert default equipment categories
        $insert_categories = "INSERT INTO equipment_categories (name, description) VALUES
        ('Smart Equipment', 'Modern equipment with digital tracking capabilities'),
        ('Fitness Machines', 'Traditional gym machines for various exercises'),
        ('High-Intensity Tools', 'Equipment designed for high-intensity workouts')";
        
        if ($conn->query($insert_categories)) {
            echo "<p style='color: green;'>✓ Added default equipment categories</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding equipment categories: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating equipment_categories table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Equipment categories table already exists</p>";
}

// Check if equipment table exists, if not create it
$check_equipment = "SHOW TABLES LIKE 'equipment'";
$equipment_result = $conn->query($check_equipment);

if ($equipment_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Equipment table doesn't exist. Creating it...</p>";
    
    $create_equipment = "CREATE TABLE equipment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        category ENUM('cardio', 'strength', 'functional', 'free-weights') NOT NULL,
        category_id INT,
        type VARCHAR(50),
        status ENUM('Available', 'In Use', 'Maintenance') DEFAULT 'Available',
        features TEXT,
        tracking_capabilities TEXT,
        image_url VARCHAR(255),
        quantity INT DEFAULT 1,
        last_maintenance_date DATE,
        next_maintenance_date DATE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES equipment_categories(id)
    )";
    
    if ($conn->query($create_equipment)) {
        echo "<p style='color: green;'>✓ Created equipment table</p>";
        
        // Insert sample equipment data
        $insert_equipment = "INSERT INTO equipment (name, description, category, type, features, tracking_capabilities, quantity) VALUES
        ('Treadmill Pro X1', 'Advanced treadmill with built-in workout programs', 'cardio', 'Cardio', 'Heart rate monitoring, Incline control, Speed control, LCD display', 'Distance tracking, Calorie tracking, Heart rate monitoring, Speed tracking', 5),
        ('Smith Machine', 'Professional smith machine for weight training', 'strength', 'Strength', 'Safety locks, Adjustable hooks, Weight storage', 'Weight tracking', 2),
        ('Smart Bike Elite', 'Interactive cycling experience with virtual routes', 'cardio', 'Cardio', 'Virtual routes, Resistance control, Tablet holder, Bluetooth connectivity', 'Distance tracking, Speed tracking, Resistance level tracking, Heart rate monitoring', 8),
        ('Power Rack', 'Heavy-duty power rack for strength training', 'strength', 'Strength', 'Multiple safety catches, Pull-up bar, Band pegs', NULL, 2),
        ('Smart Rowing Machine', 'Advanced rowing machine with performance tracking', 'cardio', 'Cardio', 'Digital display, Adjustable resistance, Ergonomic handle', 'Stroke rate tracking, Distance tracking, Power output tracking', 3),
        ('Adjustable Dumbbells', 'Quick-change weight adjustment system', 'free-weights', 'Free Weights', 'Quick-lock adjustment, Compact design', NULL, 10),
        ('Smart Cable Machine', 'Dual pulley system with weight tracking', 'strength', 'Strength', 'Digital weight selection, Multiple attachments, Exercise tracking', 'Weight tracking, Rep counting, Set tracking', 2),
        ('Olympic Bench Press', 'Professional Olympic bench with safety spotters', 'strength', 'Strength', 'Safety spotters, Adjustable seat, Weight storage', NULL, 2),
        ('Kettlebell Set', 'Set of kettlebells ranging from 5-40 kg', 'free-weights', 'Free Weights', 'Various weights, Durable construction', NULL, 4),
        ('Battle Ropes', '40ft battle ropes for functional training', 'functional', 'Functional', 'Heavy-duty construction, Multiple grip options', NULL, 2),
        ('Spin Bike', 'Commercial grade spinning bike with digital display', 'cardio', 'Cardio', 'Digital display, Adjustable resistance, Comfortable seat', 'Speed tracking, Resistance tracking', 8),
        ('TRX Suspension', 'Suspension training system', 'functional', 'Functional', 'Adjustable straps, Multiple exercise options', NULL, 6),
        ('Olympic Barbell Set', 'Olympic barbell with weight plates', 'free-weights', 'Free Weights', 'Professional grade, Multiple weight options', NULL, 5),
        ('Leg Press Machine', 'Plate loaded leg press machine', 'strength', 'Strength', 'Adjustable seat, Safety locks, Weight storage', NULL, 2),
        ('Medicine Ball Set', 'Set of medicine balls from 2-20 kg', 'functional', 'Functional', 'Various weights, Durable construction', NULL, 3)";
        
        if ($conn->query($insert_equipment)) {
            echo "<p style='color: green;'>✓ Added sample equipment data</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding equipment data: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating equipment table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Equipment table already exists</p>";
}

// Check if equipment_usage table exists, if not create it
$check_equipment_usage = "SHOW TABLES LIKE 'equipment_usage'";
$equipment_usage_result = $conn->query($check_equipment_usage);

if ($equipment_usage_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Equipment usage table doesn't exist. Creating it...</p>";
    
    $create_equipment_usage = "CREATE TABLE equipment_usage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        equipment_id INT NOT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME,
        duration_minutes INT,
        duration INT,
        calories_burned DECIMAL(10,2),
        distance DECIMAL(10,2),
        intensity_level VARCHAR(20),
        heart_rate_avg INT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (equipment_id) REFERENCES equipment(id)
    )";
    
    if ($conn->query($create_equipment_usage)) {
        echo "<p style='color: green;'>✓ Created equipment_usage table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating equipment_usage table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Equipment usage table already exists</p>";
}

// Check if trainers table exists, if not create it
$check_trainers = "SHOW TABLES LIKE 'trainers'";
$trainers_result = $conn->query($check_trainers);

if ($trainers_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Trainers table doesn't exist. Creating it...</p>";
    
    $create_trainers = "CREATE TABLE trainers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        name VARCHAR(255) NOT NULL,
        specialization VARCHAR(255) NOT NULL,
        experience_years INT NOT NULL,
        bio TEXT,
        certification TEXT,
        image_url VARCHAR(255),
        contact_number VARCHAR(20),
        email VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    
    if ($conn->query($create_trainers)) {
        echo "<p style='color: green;'>✓ Created trainers table</p>";
        
        // Insert sample trainers
        $insert_trainers = "INSERT INTO trainers (name, specialization, experience_years, bio, image_url, contact_number, email) VALUES
        ('John Smith', 'Weight Training', 8, 'Certified personal trainer specializing in strength training and muscle building. Helped numerous clients achieve their fitness goals.', 'images/trainers/john.jpg', '09123456789', 'john.smith@almofitness.com'),
        ('Maria Garcia', 'Yoga & Pilates', 6, 'Certified yoga instructor with expertise in various yoga styles. Passionate about helping clients improve flexibility and mindfulness.', 'images/trainers/maria.jpg', '09234567890', 'maria.garcia@almofitness.com'),
        ('Mike Johnson', 'CrossFit', 5, 'CrossFit Level 2 trainer with a background in competitive athletics. Specializes in high-intensity workouts and functional fitness.', 'images/trainers/mike.jpg', '09345678901', 'mike.johnson@almofitness.com'),
        ('Sarah Lee', 'Nutrition & Weight Loss', 7, 'Certified nutritionist and personal trainer. Expert in creating comprehensive weight loss programs combining exercise and diet.', 'images/trainers/sarah.jpg', '09456789012', 'sarah.lee@almofitness.com'),
        ('David Wilson', 'Sports Conditioning', 10, 'Former professional athlete with expertise in sports-specific training and rehabilitation. Helps athletes improve performance and prevent injuries.', 'images/trainers/david.jpg', '09567890123', 'david.wilson@almofitness.com')";
        
        if ($conn->query($insert_trainers)) {
            echo "<p style='color: green;'>✓ Added sample trainers</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding trainers: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating trainers table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Trainers table already exists</p>";
}

// Check if classes table exists, if not create it
$check_classes = "SHOW TABLES LIKE 'classes'";
$classes_result = $conn->query($check_classes);

if ($classes_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Classes table doesn't exist. Creating it...</p>";
    
    $create_classes = "CREATE TABLE classes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        trainer_id INT,
        schedule_time TIME NOT NULL,
        schedule_days VARCHAR(255) NOT NULL,
        duration_minutes INT NOT NULL,
        max_capacity INT NOT NULL,
        difficulty_level ENUM('Beginner', 'Intermediate', 'Advanced') NOT NULL,
        category ENUM('Yoga', 'HIIT', 'Strength', 'Cardio', 'Pilates', 'Zumba', 'CrossFit') NOT NULL,
        room_location VARCHAR(100),
        status ENUM('Active', 'Cancelled', 'Full') DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trainer_id) REFERENCES trainers(id)
    )";
    
    if ($conn->query($create_classes)) {
        echo "<p style='color: green;'>✓ Created classes table</p>";
        
        // Insert sample classes
        $insert_classes = "INSERT INTO classes (name, description, trainer_id, schedule_time, schedule_days, duration_minutes, max_capacity, difficulty_level, category, room_location) VALUES
        ('Morning Yoga Flow', 'Start your day with energizing yoga poses and breathing exercises', 1, '07:00:00', 'Mon,Wed,Fri', 60, 20, 'Beginner', 'Yoga', 'Studio 1'),
        ('HIIT Blast', 'High-intensity interval training for maximum calorie burn', 2, '08:30:00', 'Tue,Thu', 45, 15, 'Intermediate', 'HIIT', 'Main Floor'),
        ('Power Lifting', 'Strength training focusing on the three main lifts', 3, '10:00:00', 'Mon,Wed,Fri', 90, 12, 'Advanced', 'Strength', 'Weight Room'),
        ('Cardio Dance', 'Fun dance-based cardio workout for all levels', 4, '17:30:00', 'Tue,Thu', 60, 25, 'Beginner', 'Zumba', 'Studio 2'),
        ('CrossFit WOD', 'Workout of the day combining strength and conditioning', 2, '18:00:00', 'Mon,Wed,Fri', 60, 15, 'Intermediate', 'CrossFit', 'CrossFit Box'),
        ('Evening Pilates', 'Core-strengthening and flexibility work', 1, '19:00:00', 'Tue,Thu', 45, 20, 'Beginner', 'Pilates', 'Studio 1'),
        ('Spin & Burn', 'High-energy spinning class with interval training', 3, '06:30:00', 'Mon,Wed,Fri', 45, 20, 'Intermediate', 'Cardio', 'Spin Room'),
        ('Advanced HIIT', 'Challenging high-intensity workout for experienced athletes', 4, '19:30:00', 'Mon,Wed', 45, 12, 'Advanced', 'HIIT', 'Main Floor')";
        
        if ($conn->query($insert_classes)) {
            echo "<p style='color: green;'>✓ Added sample classes</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding classes: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating classes table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Classes table already exists</p>";
}

// Check if class_enrollments table exists, if not create it
$check_class_enrollments = "SHOW TABLES LIKE 'class_enrollments'";
$class_enrollments_result = $conn->query($check_class_enrollments);

if ($class_enrollments_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Class enrollments table doesn't exist. Creating it...</p>";
    
    $create_class_enrollments = "CREATE TABLE class_enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        user_id INT NOT NULL,
        enrollment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        status ENUM('Active', 'Cancelled', 'Completed') DEFAULT 'Active',
        FOREIGN KEY (class_id) REFERENCES classes(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )";
    
    if ($conn->query($create_class_enrollments)) {
        echo "<p style='color: green;'>✓ Created class_enrollments table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating class_enrollments table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Class enrollments table already exists</p>";
}

// Check if services table exists, if not create it
$check_services = "SHOW TABLES LIKE 'services'";
$services_result = $conn->query($check_services);

if ($services_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Services table doesn't exist. Creating it...</p>";
    
    $create_services = "CREATE TABLE services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        image_url VARCHAR(255),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_services)) {
        echo "<p style='color: green;'>✓ Created services table</p>";
        
        // Insert sample services
        $insert_services = "INSERT INTO services (name, description, price, image_url) VALUES
        ('Personal Training', 'One-on-one training sessions with our certified personal trainers. Customized workout plans and nutrition guidance included.', 1500.00, 'images/services/personal-training.jpg'),
        ('Group Classes', 'Join our energetic group classes including Zumba, Yoga, Spinning, and HIIT. Perfect for those who enjoy working out with others.', 800.00, 'images/services/group-classes.jpg'),
        ('Nutrition Consultation', 'Get personalized nutrition advice from our certified nutritionists. Includes meal planning and dietary recommendations.', 1000.00, 'images/services/nutrition.jpg'),
        ('Gym Membership', 'Full access to our state-of-the-art gym equipment and facilities. Includes locker room access and basic fitness assessment.', 2000.00, 'images/services/gym.jpg'),
        ('CrossFit Training', 'High-intensity functional training in a group setting. Led by certified CrossFit trainers.', 1200.00, 'images/services/crossfit.jpg')";
        
        if ($conn->query($insert_services)) {
            echo "<p style='color: green;'>✓ Added sample services</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding services: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating services table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Services table already exists</p>";
}

// Check if announcements table exists, if not create it
$check_announcements = "SHOW TABLES LIKE 'announcements'";
$announcements_result = $conn->query($check_announcements);

if ($announcements_result->num_rows == 0) {
    echo "<p style='color: orange;'>⚠ Announcements table doesn't exist. Creating it...</p>";
    
    $create_announcements = "CREATE TABLE announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Low',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($create_announcements)) {
        echo "<p style='color: green;'>✓ Created announcements table</p>";
        
        // Insert sample announcements
        $insert_announcements = "INSERT INTO announcements (title, content, priority) VALUES
        ('Urgent: Temporary Closure', 'Due to an emergency maintenance, the gym will be closed tomorrow from 10 AM to 2 PM. We apologize for any inconvenience.', 'Urgent'),
        ('New Year Fitness Challenge', 'Join our 30-day New Year fitness challenge starting January 1st. Sign up at the front desk for a chance to win exciting prizes!', 'High'),
        ('Holiday Schedule Changes', 'The gym will be operating on special hours during the upcoming holiday season. Please check the schedule board for details.', 'High'),
        ('New Equipment Arrival', 'We are excited to announce the arrival of new state-of-the-art cardio equipment! Come try them out starting next week.', 'Medium'),
        ('Monthly Member Spotlight', 'Congratulations to John Smith for achieving his fitness goals! Read his inspiring journey on our blog.', 'Low'),
        ('Weekend Yoga Workshop', 'Join our special yoga workshop this weekend. Perfect for all skill levels. Limited spots available!', 'Medium')";
        
        if ($conn->query($insert_announcements)) {
            echo "<p style='color: green;'>✓ Added sample announcements</p>";
        } else {
            echo "<p style='color: red;'>✗ Error adding announcements: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: red;'>✗ Error creating announcements table: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color: blue;'>ℹ Announcements table already exists</p>";
}

// Add category column if it doesn't exist
$check_category = $conn->query("SHOW COLUMNS FROM equipment LIKE 'category'");
if ($check_category->num_rows === 0) {
    $conn->query("ALTER TABLE equipment ADD COLUMN category VARCHAR(50) DEFAULT 'Uncategorized' AFTER description");
    echo "Added category column\n";
}

// Add image_url column if it doesn't exist
$check_image = $conn->query("SHOW COLUMNS FROM equipment LIKE 'image_url'");
if ($check_image->num_rows === 0) {
    $conn->query("ALTER TABLE equipment ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER status");
    echo "Added image_url column\n";
}

echo "<h3>Database fix completed!</h3>";
echo "<p><a href='../signup.php' style='color: blue; text-decoration: underline;'>Go to Signup Page</a></p>";
echo "<p><a href='../member/payment.php' style='color: blue; text-decoration: underline;'>Go to Payment Page</a></p>";
echo "<p><a href='../member/equipment.php' style='color: blue; text-decoration: underline;'>Go to Equipment Page</a></p>";

$conn->close();
?> 