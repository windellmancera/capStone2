<?php

class TrainerRecommendation {
    private $conn;
    private $user_id;
    private $user_data;
    private $fitness_data;
    private $has_feedback_table;

    public function __construct($conn, $user_id) {
        $this->conn = $conn;
        $this->user_id = $user_id;
        $this->checkTables();
        $this->loadUserData();
    }

    private function checkTables() {
        // Check if feedback table exists
        $result = $this->conn->query("SHOW TABLES LIKE 'feedback'");
        $this->has_feedback_table = ($result && $result->num_rows > 0);
    }

    private function loadUserData() {
        // Get user's fitness data
        $sql = "SELECT * FROM fitness_data WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $this->fitness_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    public function getRecommendedTrainers() {
        if (!$this->fitness_data) {
            return $this->getDefaultRecommendations();
        }

        $recommendations = [];
        $weights = [];

        // Get all trainers with optional feedback data
        $sql = "SELECT t.*, 
                       COUNT(DISTINCT c.id) as class_count" .
                       ($this->has_feedback_table ? 
                       ", AVG(CASE WHEN f.rating IS NOT NULL THEN f.rating ELSE NULL END) as avg_rating,
                        COUNT(DISTINCT f.id) as feedback_count" : 
                       ", NULL as avg_rating, 0 as feedback_count") . "
                FROM trainers t
                LEFT JOIN classes c ON t.id = c.trainer_id" .
                ($this->has_feedback_table ? 
                " LEFT JOIN feedback f ON t.id = f.trainer_id" : "") . "
                GROUP BY t.id";
        
        $trainers = $this->conn->query($sql);

        if (!$trainers) {
            return $this->getDefaultRecommendations();
        }

        while ($trainer = $trainers->fetch_assoc()) {
            $weight = $this->calculateTrainerWeight($trainer);
            $weights[$trainer['id']] = $weight;
            $recommendations[$trainer['id']] = $trainer;
        }

        // Sort trainers by weight
        arsort($weights);

        // Reorder recommendations based on weights
        $sorted_recommendations = [];
        foreach ($weights as $trainer_id => $weight) {
            $sorted_recommendations[] = array_merge(
                $recommendations[$trainer_id],
                ['match_score' => min(round(($weight / max($weights)) * 100), 100)]
            );
        }

        return $sorted_recommendations;
    }

    private function calculateTrainerWeight($trainer) {
        $weight = 0;

        // Base weight from trainer's experience
        $weight += $trainer['experience_years'] * 2;

        // Add rating weight if available
        if ($this->has_feedback_table && isset($trainer['avg_rating'])) {
            $weight += ($trainer['avg_rating'] ?? 3) * 5;
        }

        // Fitness level match
        if ($this->fitness_data['fitness_level'] === 'beginner' && $trainer['specialization'] === 'General Fitness') {
            $weight += 15;
        } elseif ($this->fitness_data['fitness_level'] === 'intermediate' && strpos($trainer['specialization'], 'Advanced') === false) {
            $weight += 10;
        } elseif ($this->fitness_data['fitness_level'] === 'advanced' && strpos($trainer['specialization'], 'Advanced') !== false) {
            $weight += 20;
        }

        // Goal match
        switch ($this->fitness_data['goal']) {
            case 'weight_loss':
                if (strpos(strtolower($trainer['specialization']), 'cardio') !== false ||
                    strpos(strtolower($trainer['specialization']), 'weight loss') !== false) {
                    $weight += 15;
                }
                break;
            case 'muscle_gain':
                if (strpos(strtolower($trainer['specialization']), 'strength') !== false ||
                    strpos(strtolower($trainer['specialization']), 'bodybuilding') !== false) {
                    $weight += 15;
                }
                break;
            case 'endurance':
                if (strpos(strtolower($trainer['specialization']), 'cardio') !== false ||
                    strpos(strtolower($trainer['specialization']), 'endurance') !== false) {
                    $weight += 15;
                }
                break;
        }

        // Medical condition consideration
        if ($this->fitness_data['has_medical_condition'] && $trainer['certification_level'] === 'Advanced') {
            $weight += 10;
        }

        // Activity level match
        switch ($this->fitness_data['activity_level']) {
            case 'sedentary':
                if (strpos(strtolower($trainer['specialization']), 'beginner') !== false) {
                    $weight += 10;
                }
                break;
            case 'active':
                if (strpos(strtolower($trainer['specialization']), 'advanced') !== false) {
                    $weight += 10;
                }
                break;
        }

        // Popularity and engagement
        $weight += min($trainer['class_count'] * 2, 20); // Cap at 20
        if ($this->has_feedback_table) {
            $weight += min($trainer['feedback_count'], 10); // Cap at 10
        }

        return $weight;
    }

    private function getDefaultRecommendations() {
        // Return trainers sorted by experience if no fitness data available
        $sql = "SELECT t.*, 
                       COUNT(DISTINCT c.id) as class_count" .
                       ($this->has_feedback_table ? 
                       ", AVG(CASE WHEN f.rating IS NOT NULL THEN f.rating ELSE NULL END) as avg_rating,
                        COUNT(DISTINCT f.id) as feedback_count" : 
                       ", NULL as avg_rating, 0 as feedback_count") . "
                FROM trainers t
                LEFT JOIN classes c ON t.id = c.trainer_id" .
                ($this->has_feedback_table ? 
                " LEFT JOIN feedback f ON t.id = f.trainer_id" : "") . "
                GROUP BY t.id
                ORDER BY t.experience_years DESC" .
                ($this->has_feedback_table ? ", avg_rating DESC" : "") . "
                LIMIT 10";
        
        $result = $this->conn->query($sql);
        if (!$result) {
            return [];
        }

        $recommendations = [];
        while ($trainer = $result->fetch_assoc()) {
            $base_score = ($trainer['experience_years'] / 20) * 100; // Normalize to 100
            if ($this->has_feedback_table && isset($trainer['avg_rating'])) {
                $base_score = ($base_score + (($trainer['avg_rating'] ?? 3) * 20)) / 2;
            }
            $recommendations[] = array_merge(
                $trainer,
                ['match_score' => round($base_score)]
            );
        }
        
        return $recommendations;
    }
} 