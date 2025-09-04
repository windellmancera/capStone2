<?php
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "almo_fitness_db";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>