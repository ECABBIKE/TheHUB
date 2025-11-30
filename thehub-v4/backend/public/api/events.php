<?php
require_once __DIR__ . '/../../core/Database.php';
header("Content-Type: application/json");
$db = (new Database())->getConnection();
$sql = "SELECT id, event_name, event_date, series, location FROM events ORDER BY event_date DESC";
$stmt = $db->query($sql);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));