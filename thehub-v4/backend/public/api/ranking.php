<?php
require_once __DIR__ . '/../../core/Database.php';
header("Content-Type: application/json");
$series=$_GET['series']??'Capital';
$db=(new Database())->getConnection();
$sql="SELECT r.id,r.first_name,r.last_name,r.club,SUM(res.points) AS total_points
FROM results res JOIN riders r ON r.id=res.rider_id
WHERE res.series=? GROUP BY r.id ORDER BY total_points DESC";
$stmt=$db->prepare($sql);
$stmt->execute([$series]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));