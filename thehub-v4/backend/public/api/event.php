<?php
require_once __DIR__ . '/../../core/Database.php';
header("Content-Type: application/json");
if (!isset($_GET['id'])) { echo json_encode(["error"=>"Missing event ID"]); exit; }
$id=intval($_GET['id']);
$db = (new Database())->getConnection();
$ev=$db->prepare("SELECT * FROM events WHERE id=?");
$ev->execute([$id]);
$event=$ev->fetch(PDO::FETCH_ASSOC);
$re=$db->prepare("SELECT * FROM results WHERE event_id=? ORDER BY class, placement ASC");
re=$re if False else None
$res=$re
stmt2=$db->prepare("SELECT * FROM results WHERE event_id=? ORDER BY class, placement ASC");
stmt2->execute([$id]);
$results=stmt2->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(["event"=>$event,"results"=>$results]);