<?php
session_start();
require_once '../db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Please log in to submit feedback']);
    exit();
}

// Validate input
if (!isset($_POST['trainer_id']) || !isset($_POST['rating'])) {
    echo json_encode(['error' => 'Missing required fields']);
    exit();
}

$user_id = $_SESSION['user_id'];
$trainer_id = $_POST['trainer_id'];
$rating = intval($_POST['rating']);
$comment = $_POST['comment'] ?? '';

// Validate rating
if ($rating < 1 || $rating > 5) {
    echo json_encode(['error' => 'Invalid rating value']);
    exit();
}

try {
    // Begin transaction
    $conn->begin_transaction();

    // Check if user has already given feedback to this trainer
    $check_sql = "SELECT id FROM feedback WHERE user_id = ? AND trainer_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $user_id, $trainer_id);
    $check_stmt->execute();
    $existing_feedback = $check_stmt->get_result();

    if ($existing_feedback->num_rows > 0) {
        // Update existing feedback
        $update_sql = "UPDATE feedback SET rating = ?, comment = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND trainer_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("isii", $rating, $comment, $user_id, $trainer_id);
    } else {
        // Insert new feedback
        $insert_sql = "INSERT INTO feedback (user_id, trainer_id, rating, comment) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("iiis", $user_id, $trainer_id, $rating, $comment);
    }

    if (!$stmt->execute()) {
        throw new Exception("Error executing feedback query");
    }

    // Get updated trainer stats
    $avg_sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as feedback_count FROM feedback WHERE trainer_id = ?";
    $avg_stmt = $conn->prepare($avg_sql);
    $avg_stmt->bind_param("i", $trainer_id);
    $avg_stmt->execute();
    $result = $avg_stmt->get_result()->fetch_assoc();

    // Commit transaction
    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Feedback submitted successfully',
        'avg_rating' => round($result['avg_rating'], 1),
        'feedback_count' => $result['feedback_count']
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    echo json_encode(['error' => 'Error submitting feedback: ' . $e->getMessage()]);
}

// Close connections
if (isset($check_stmt)) $check_stmt->close();
if (isset($stmt)) $stmt->close();
if (isset($avg_stmt)) $avg_stmt->close();
$conn->close();
?> 