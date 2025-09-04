<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require '../db.php';

// Get parameters from POST request
$category = $_POST['category'] ?? '';
$type = $_POST['type'] ?? '';

// Initialize response array
$response = [
    'equipment' => [],
    'totalQuantity' => 0,
    'availableQuantity' => 0,
    'maintenanceQuantity' => 0
];

try {
    // Build query based on category and type
    $where_clause = '';
    $params = [];
    $types = '';
    
    switch ($type) {
        case 'total':
            // Show all equipment
            $where_clause = '';
            break;
            
        case 'status':
            // Filter by status
            if ($category !== 'all') {
                $where_clause = "WHERE status = ?";
                $params[] = $category;
                $types = 's';
            }
            break;
            
        case 'quantity_status':
            // Filter by status for quantity calculations
            if ($category !== 'all') {
                $where_clause = "WHERE status = ?";
                $params[] = $category;
                $types = 's';
            }
            break;
            
        case 'quantity_threshold':
            // Filter by quantity threshold (low stock)
            $where_clause = "WHERE quantity <= 3";
            break;
            
        default:
            // Default to all equipment
            $where_clause = '';
    }
    
    // Build the main query
    $sql = "SELECT * FROM equipment $where_clause ORDER BY name ASC";
    
    // Prepare and execute the query
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    // Fetch equipment data
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $response['equipment'][] = $row;
        }
    }
    
    // Calculate additional statistics
    if (!empty($response['equipment'])) {
        $response['totalQuantity'] = array_sum(array_column($response['equipment'], 'quantity'));
        
        // Calculate quantities by status
        foreach ($response['equipment'] as $item) {
            switch ($item['status']) {
                case 'Available':
                    $response['availableQuantity'] += ($item['quantity'] ?? 0);
                    break;
                case 'Maintenance':
                    $response['maintenanceQuantity'] += ($item['quantity'] ?? 0);
                    break;
            }
        }
    }
    
    // Return success response
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'equipment' => [],
        'totalQuantity' => 0,
        'availableQuantity' => 0,
        'maintenanceQuantity' => 0
    ]);
}

$conn->close();
?>
