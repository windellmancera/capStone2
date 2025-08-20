<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member/member_login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Test queries to see if members are found
$tests = [];

// Test 1: Count total members
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'member'");
$tests['total_members'] = $result->fetch_assoc()['count'];

// Test 2: Count active members
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'member' AND membership_end_date >= CURDATE()");
$tests['active_members'] = $result->fetch_assoc()['count'];

// Test 3: Get recent members
$recent_members = $conn->query("
    SELECT u.*, mp.name as plan_name
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role = 'member'
    ORDER BY u.id DESC
    LIMIT 5
");

$tests['recent_members'] = [];
while ($row = $recent_members->fetch_assoc()) {
    $tests['recent_members'][] = $row;
}

// Test 4: Check if attendance table exists
$table_exists = $conn->query("SHOW TABLES LIKE 'attendance'")->num_rows > 0;
$tests['attendance_table_exists'] = $table_exists;

// Test 5: Count today's attendance
if ($table_exists) {
    $result = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM attendance WHERE DATE(check_in_time) = CURDATE()");
    $tests['today_attendance'] = $result->fetch_assoc()['count'];
} else {
    $tests['today_attendance'] = 'Table does not exist';
}

// Test 6: Count pending payments
$result = $conn->query("SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Pending'");
$tests['pending_payments'] = $result->fetch_assoc()['count'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Members Test</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Admin Members Test Results</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- Test Results -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Test Results</h2>
                <div class="space-y-2">
                    <p><strong>Total Members:</strong> 
                        <span class="<?php echo $tests['total_members'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tests['total_members']; ?>
                        </span>
                    </p>
                    <p><strong>Active Members:</strong> 
                        <span class="<?php echo $tests['active_members'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tests['active_members']; ?>
                        </span>
                    </p>
                    <p><strong>Today's Attendance:</strong> 
                        <span class="<?php echo is_numeric($tests['today_attendance']) ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tests['today_attendance']; ?>
                        </span>
                    </p>
                    <p><strong>Pending Payments:</strong> 
                        <span class="<?php echo $tests['pending_payments'] > 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tests['pending_payments']; ?>
                        </span>
                    </p>
                    <p><strong>Attendance Table:</strong> 
                        <span class="<?php echo $tests['attendance_table_exists'] ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $tests['attendance_table_exists'] ? '✅ Exists' : '❌ Missing'; ?>
                        </span>
                    </p>
                </div>
            </div>

            <!-- Recent Members -->
            <div class="bg-white p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Recent Members</h2>
                <?php if (!empty($tests['recent_members'])): ?>
                    <div class="space-y-2">
                        <?php foreach ($tests['recent_members'] as $member): ?>
                            <div class="border-b pb-2">
                                <p><strong>ID:</strong> <?php echo $member['id']; ?></p>
                                <p><strong>Username:</strong> <?php echo $member['username']; ?></p>
                                <p><strong>Plan:</strong> <?php echo $member['plan_name'] ?? 'No Plan'; ?></p>
                                <p><strong>Role:</strong> <?php echo $member['role']; ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-red-600">No members found!</p>
                <?php endif; ?>
            </div>

            <!-- Status Summary -->
            <div class="bg-blue-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Status Summary</h2>
                <?php if ($tests['total_members'] > 0): ?>
                    <div class="text-green-600 font-semibold">
                        ✅ SUCCESS! Members are now showing up in admin queries.
                    </div>
                    <p class="mt-2">The admin dashboard should now display:</p>
                    <ul class="list-disc list-inside mt-2 space-y-1">
                        <li>Total Members: <?php echo $tests['total_members']; ?></li>
                        <li>Active Members: <?php echo $tests['active_members']; ?></li>
                        <li>Today's Check-ins: <?php echo $tests['today_attendance']; ?></li>
                        <li>Pending Payments: <?php echo $tests['pending_payments']; ?></li>
                    </ul>
                <?php else: ?>
                    <div class="text-red-600 font-semibold">
                        ❌ No members found. Check if users have role = 'member'.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Next Steps -->
            <div class="bg-green-50 p-6 rounded-lg shadow md:col-span-2">
                <h2 class="text-xl font-semibold mb-4">Next Steps</h2>
                <ol class="list-decimal list-inside space-y-2">
                    <li>Go to: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/admin/dashboard.php</code></li>
                    <li>Check if the member counts are now showing correctly</li>
                    <li>Test the QR code scanning: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/admin/attendance_scanner.php</code></li>
                    <li>If attendance table is missing, run: <code class="bg-gray-200 px-2 py-1 rounded">http://localhost/capstone1/check_attendance_table.php</code></li>
                </ol>
            </div>

        </div>
    </div>
</body>
</html> 