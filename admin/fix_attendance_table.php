<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

$message = '';
$messageClass = '';

// Check if check_out_time column exists
$check_column_sql = "SHOW COLUMNS FROM attendance LIKE 'check_out_time'";
$check_result = $conn->query($check_column_sql);

if ($check_result->num_rows == 0) {
    // Add check_out_time column
    $add_column_sql = "ALTER TABLE attendance ADD COLUMN check_out_time DATETIME NULL AFTER check_in_time";
    
    if ($conn->query($add_column_sql)) {
        $message = "✅ Successfully added check_out_time column to attendance table.";
        $messageClass = 'success';
    } else {
        $message = "❌ Error adding check_out_time column: " . $conn->error;
        $messageClass = 'error';
    }
} else {
    $message = "ℹ️ check_out_time column already exists in attendance table.";
    $messageClass = 'info';
}

// Get current table structure
$structure_sql = "DESCRIBE attendance";
$structure_result = $conn->query($structure_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Attendance Table - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen py-8">
        <div class="max-w-4xl mx-auto px-4">
            <div class="bg-white rounded-lg shadow-lg p-8">
                <h1 class="text-2xl font-bold text-gray-900 mb-6">Fix Attendance Table</h1>
                
                <?php if ($message): ?>
                    <div class="mb-6 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 border border-green-200 text-green-700' : ($messageClass === 'error' ? 'bg-red-100 border border-red-200 text-red-700' : 'bg-blue-100 border border-blue-200 text-blue-700'); ?>">
                        <div class="flex items-center">
                            <i class="fas <?php echo $messageClass === 'success' ? 'fa-check-circle' : ($messageClass === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'); ?> mr-2"></i>
                            <?php echo $message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Current Table Structure -->
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Current Attendance Table Structure</h2>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-2">Column</th>
                                        <th class="text-left py-2">Type</th>
                                        <th class="text-left py-2">Null</th>
                                        <th class="text-left py-2">Key</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($column = $structure_result->fetch_assoc()): ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="py-2 font-medium"><?php echo $column['Field']; ?></td>
                                            <td class="py-2"><?php echo $column['Type']; ?></td>
                                            <td class="py-2"><?php echo $column['Null']; ?></td>
                                            <td class="py-2"><?php echo $column['Key']; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Actions -->
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 mb-4">Actions</h2>
                        <div class="space-y-4">
                            <a href="dashboard.php" class="block w-full p-4 bg-red-600 text-white rounded-lg hover:bg-red-700 text-center">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Dashboard
                            </a>
                            
                            <a href="attendance_history.php" class="block w-full p-4 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-center">
                                <i class="fas fa-clock mr-2"></i>
                                View Attendance History
                            </a>
                            
                            <a href="generate_test_qr.php" class="block w-full p-4 bg-green-600 text-white rounded-lg hover:bg-green-700 text-center">
                                <i class="fas fa-qrcode mr-2"></i>
                                Generate Test QR Code
                            </a>
                        </div>
                        
                        <div class="mt-6 p-4 bg-yellow-50 rounded-lg">
                            <h3 class="text-md font-semibold text-yellow-800 mb-2">Note:</h3>
                            <p class="text-sm text-yellow-700">
                                The attendance system currently only records check-in times. 
                                Check-out functionality can be added later if needed.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html> 