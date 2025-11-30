<?php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../../core/Controller.php';
require_once __DIR__ . '/RankingEngine.php';

class RankingController extends Controller
{
    public function series(): void
    {
        $series = $_GET['series'] ?? 'capital';
        $rows = RankingEngine::seriesRanking($series);
        $this->json(['ok' => true, 'series' => $series, 'data' => $rows]);
    }
}
