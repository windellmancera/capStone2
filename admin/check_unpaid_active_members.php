<?php
session_start();

// Ensure only admins can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

require '../db.php';

// Find active members who have no approved payments on record
$sql = "
    SELECT 
        u.id, u.username, u.email, u.full_name,
        u.membership_start_date, u.membership_end_date, u.created_at,
        (
            SELECT COUNT(*) 
            FROM payment_history ph 
            WHERE ph.user_id = u.id AND ph.payment_status = 'Approved'
        ) as approved_payments
    FROM users u
    WHERE u.role = 'member'
      AND (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE())
      AND NOT EXISTS (
            SELECT 1 FROM payment_history ph2 
            WHERE ph2.user_id = u.id AND ph2.payment_status = 'Approved'
      )
    ORDER BY u.id ASC
";

$result = $conn->query($sql);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Active Members Without Approved Payments</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-5xl mx-auto">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-gray-800">Active Members Without Approved Payments</h1>
            <a href="dashboard.php" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Back to Dashboard</a>
        </div>

        <div class="bg-white rounded-lg shadow border border-gray-200">
            <div class="p-4 border-b border-gray-200 flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-600">Count</p>
                    <p class="text-3xl font-bold text-red-600"><?php echo $result ? $result->num_rows : 0; ?></p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Join Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Membership End</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo $row['id']; ?></td>
                                    <td class="px-6 py-4 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($row['full_name'] ?: $row['username']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo $row['membership_start_date'] ? date('M d, Y', strtotime($row['membership_start_date'])) : date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo $row['membership_end_date'] ? date('M d, Y', strtotime($row['membership_end_date'])) : '—'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-6 text-center text-gray-500">All active members have at least one approved payment.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>


