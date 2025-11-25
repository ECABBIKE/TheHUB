<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/ranking_functions.php';

$db = getDB();

echo "<h2>Debug Ranking Settings</h2>";

// Check field multipliers
echo "<h3>Field Size Multipliers:</h3>";
$fieldMult = getRankingFieldMultipliers($db);
echo "<pre>";
print_r($fieldMult);
echo "</pre>";

// Check event level multipliers
echo "<h3>Event Level Multipliers:</h3>";
$eventLevel = getEventLevelMultipliers($db);
echo "<pre>";
print_r($eventLevel);
echo "</pre>";

// Check time decay
echo "<h3>Time Decay Multipliers:</h3>";
$timeDecay = getRankingTimeDecay($db);
echo "<pre>";
print_r($timeDecay);
echo "</pre>";

// Check if any are 0
echo "<h3>Issues:</h3>";
if (empty($fieldMult)) echo "<p style='color:red'>❌ Field multipliers are EMPTY!</p>";
if (empty($eventLevel)) echo "<p style='color:red'>❌ Event level multipliers are EMPTY!</p>";
if (empty($timeDecay)) echo "<p style='color:red'>❌ Time decay settings are EMPTY!</p>";

if (isset($timeDecay['months_1_12']) && $timeDecay['months_1_12'] == 0) {
    echo "<p style='color:red'>❌ months_1_12 is 0 - this will make all recent race points = 0!</p>";
}
if (isset($timeDecay['months_13_24']) && $timeDecay['months_13_24'] == 0) {
    echo "<p style='color:orange'>⚠️ months_13_24 is 0 - this will make older race points = 0!</p>";
}
