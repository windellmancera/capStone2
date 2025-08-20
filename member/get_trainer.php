<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in to view trainer details']);
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Trainer ID is required']);
    exit();
}

$trainer_id = intval($_GET['id']);
$include_reviews = isset($_GET['include_reviews']) && $_GET['include_reviews'] === 'true';

try {
    // Get trainer basic info
    $trainer_sql = "SELECT t.*, 
                          AVG(f.rating) as avg_rating,
                          COUNT(DISTINCT f.id) as feedback_count
                   FROM trainers t
                   LEFT JOIN feedback f ON t.id = f.trainer_id
                   WHERE t.id = ?
                   GROUP BY t.id";
    
    $stmt = $conn->prepare($trainer_sql);
    $stmt->bind_param("i", $trainer_id);
    $stmt->execute();
    $trainer = $stmt->get_result()->fetch_assoc();

    if (!$trainer) {
        http_response_code(404);
        echo json_encode(['error' => 'Trainer not found']);
        exit();
    }

    // Format trainer data
    $trainer_data = [
        'id' => $trainer_id,
        'name' => $trainer['name'],
        'specialization' => $trainer['specialization'],
        'bio' => $trainer['bio'],
        'experience_years' => intval($trainer['experience_years']),
        'email' => $trainer['email'],
        'contact_number' => $trainer['contact_number'],
        'image_url' => $trainer['image_url'],
        'hourly_rate' => floatval($trainer['hourly_rate']),
        'avg_rating' => $trainer['avg_rating'] ? number_format(floatval($trainer['avg_rating']), 1) : null,
        'feedback_count' => intval($trainer['feedback_count'])
    ];

    // Include reviews if requested
    if ($include_reviews) {
        $reviews_sql = "SELECT f.*, u.username 
                       FROM feedback f
                       LEFT JOIN users u ON f.user_id = u.id
                       WHERE f.trainer_id = ?
                       ORDER BY f.created_at DESC";
        
        $stmt = $conn->prepare($reviews_sql);
        $stmt->bind_param("i", $trainer_id);
        $stmt->execute();
        $reviews_result = $stmt->get_result();
        
        $reviews = [];
        while ($review = $reviews_result->fetch_assoc()) {
            $reviews[] = [
                'rating' => intval($review['rating']),
                'comment' => $review['comment'],
                'username' => $review['username'],
                'created_at' => $review['created_at']
            ];
        }
        
        $trainer_data['reviews'] = $reviews;
    }

    echo json_encode($trainer_data);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?> 