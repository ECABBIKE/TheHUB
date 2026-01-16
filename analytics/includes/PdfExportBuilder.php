<?php
/**
 * PdfExportBuilder
 *
 * Bygger professionella PDF-exporter med obligatorisk "Definitions & Provenance" box.
 * Alla PDF-exporter ska inkludera metadata for reproducerbarhet och GDPR-compliance.
 *
 * v3.0.1: Mandatory Definition Box, snapshot_id, KPI-definitioner
 *
 * @package TheHUB Analytics
 * @version 3.0.1
 */

require_once __DIR__ . '/AnalyticsConfig.php';
require_once __DIR__ . '/ExportLogger.php';
require_once __DIR__ . '/SVGChartRenderer.php';

class PdfExportBuilder {
    private PDO $pdo;
    private ExportLogger $logger;
    private SVGChartRenderer $chartRenderer;

    private string $title = '';
    private string $subtitle = '';
    private array $sections = [];
    private array $metadata = [];
    private int $snapshotId;
    private ?int $seasonYear = null;

    /** @var array KPI-definitioner att inkludera */
    private array $kpiDefinitions = [];

    /**
     * Constructor
     *
     * @param PDO $pdo Databasanslutning
     * @param int $snapshotId Obligatorisk snapshot ID
     */
    public function __construct(PDO $pdo, int $snapshotId) {
        $this->pdo = $pdo;
        $this->snapshotId = $snapshotId;
        $this->logger = new ExportLogger($pdo);
        $this->chartRenderer = new SVGChartRenderer();

        $this->metadata = [
            'platform_version' => AnalyticsConfig::PLATFORM_VERSION,
            'calculation_version' => AnalyticsConfig::CALCULATION_VERSION,
            'snapshot_id' => $snapshotId,
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Satt titel for rapporten
     *
     * @param string $title Huvudtitel
     * @param string $subtitle Undertitel (optional)
     * @return self
     */
    public function setTitle(string $title, string $subtitle = ''): self {
        $this->title = $title;
        $this->subtitle = $subtitle;
        return $this;
    }

    /**
     * Satt sasong/ar
     *
     * @param int $year Sasong
     * @return self
     */
    public function setSeasonYear(int $year): self {
        $this->seasonYear = $year;
        $this->metadata['season_year'] = $year;
        return $this;
    }

    /**
     * Lagg till en text-sektion
     *
     * @param string $heading Rubrik
     * @param string $content Innehall (kan vara HTML eller Markdown)
     * @return self
     */
    public function addSection(string $heading, string $content): self {
        $this->sections[] = [
            'type' => 'text',
            'heading' => $heading,
            'content' => $content,
        ];
        return $this;
    }

    /**
     * Lagg till en tabell
     *
     * @param string $heading Rubrik
     * @param array $headers Kolumnrubriker
     * @param array $rows Data-rader
     * @return self
     */
    public function addTable(string $heading, array $headers, array $rows): self {
        $this->sections[] = [
            'type' => 'table',
            'heading' => $heading,
            'headers' => $headers,
            'rows' => $rows,
        ];
        return $this;
    }

    /**
     * Lagg till ett diagram
     *
     * @param string $heading Rubrik
     * @param string $chartType Diagramtyp (line, bar, donut, stackedBar)
     * @param array $data Diagramdata
     * @param array $options Diagramoptioner
     * @return self
     */
    public function addChart(string $heading, string $chartType, array $data, array $options = []): self {
        $this->sections[] = [
            'type' => 'chart',
            'heading' => $heading,
            'chart_type' => $chartType,
            'data' => $data,
            'options' => $options,
        ];
        return $this;
    }

    /**
     * Lagg till KPI-definition som ska visas i Definition Box
     *
     * @param string $kpiKey KPI-nyckel
     * @param string|null $customDefinition Anpassad definition (eller hamta fran DB)
     * @return self
     */
    public function addKpiDefinition(string $kpiKey, ?string $customDefinition = null): self {
        if ($customDefinition) {
            $this->kpiDefinitions[$kpiKey] = $customDefinition;
        } else {
            // Hamta fran databasen
            $stmt = $this->pdo->prepare("
                SELECT definition_sv, definition, formula
                FROM analytics_kpi_definitions
                WHERE kpi_key = ?
                ORDER BY calculation_version DESC
                LIMIT 1
            ");
            $stmt->execute([$kpiKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $def = $row['definition_sv'] ?: $row['definition'];
                if ($row['formula']) {
                    $def .= " (Formel: {$row['formula']})";
                }
                $this->kpiDefinitions[$kpiKey] = $def;
            }
        }
        return $this;
    }

    /**
     * Lagg till flera standard-KPI-definitioner
     *
     * @param array $kpiKeys Lista med KPI-nycklar
     * @return self
     */
    public function addStandardKpiDefinitions(array $kpiKeys): self {
        foreach ($kpiKeys as $key) {
            $this->addKpiDefinition($key);
        }
        return $this;
    }

    /**
     * Bygg HTML-versionen av rapporten
     *
     * @return string HTML-kod
     */
    public function buildHtml(): string {
        $html = $this->getHtmlHeader();

        // Titel
        $html .= '<div class="report-header">';
        $html .= '<h1>' . htmlspecialchars($this->title) . '</h1>';
        if ($this->subtitle) {
            $html .= '<p class="subtitle">' . htmlspecialchars($this->subtitle) . '</p>';
        }
        $html .= '<p class="meta">Genererad: ' . $this->metadata['generated_at'] . '</p>';
        $html .= '</div>';

        // Sektioner
        foreach ($this->sections as $section) {
            $html .= $this->renderSection($section);
        }

        // MANDATORY: Definition Box
        $html .= $this->renderDefinitionBox();

        $html .= $this->getHtmlFooter();

        return $html;
    }

    /**
     * Rendera en sektion
     *
     * @param array $section Sektionsdata
     * @return string HTML
     */
    private function renderSection(array $section): string {
        $html = '<div class="section">';

        if ($section['heading']) {
            $html .= '<h2>' . htmlspecialchars($section['heading']) . '</h2>';
        }

        switch ($section['type']) {
            case 'text':
                $html .= '<div class="content">' . $section['content'] . '</div>';
                break;

            case 'table':
                $html .= $this->renderTable($section['headers'], $section['rows']);
                break;

            case 'chart':
                $html .= $this->renderChart($section['chart_type'], $section['data'], $section['options']);
                break;
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Rendera en tabell
     *
     * @param array $headers Kolumnrubriker
     * @param array $rows Data-rader
     * @return string HTML
     */
    private function renderTable(array $headers, array $rows): string {
        $html = '<table class="data-table">';
        $html .= '<thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Rendera ett diagram
     *
     * @param string $type Diagramtyp
     * @param array $data Data
     * @param array $options Optioner
     * @return string HTML
     */
    private function renderChart(string $type, array $data, array $options): string {
        $svg = match($type) {
            'line' => $this->chartRenderer->lineChart($data, $options),
            'bar' => $this->chartRenderer->barChart($data, $options),
            'donut' => $this->chartRenderer->donutChart($data, $options),
            'stackedBar' => $this->chartRenderer->stackedBarChart($data, $options),
            'sparkline' => $this->chartRenderer->sparkline($data['values'] ?? [], $options),
            default => '',
        };

        if (!$svg) {
            return '<p class="error">Kunde inte rendera diagram</p>';
        }

        // Konvertera till bild for PDF-kompatibilitet
        $dataUri = $this->chartRenderer->toDataUri($svg, true);

        return '<div class="chart"><img src="' . $dataUri . '" alt="Chart" /></div>';
    }

    /**
     * Rendera Definition Box (MANDATORY for alla PDF-exporter)
     *
     * @return string HTML
     */
    private function renderDefinitionBox(): string {
        $html = '<div class="definition-box">';
        $html .= '<h3>Definitions & Provenance</h3>';

        // Metadata
        $html .= '<div class="meta-section">';
        $html .= '<h4>Report Metadata</h4>';
        $html .= '<ul>';
        $html .= '<li><strong>Generated:</strong> ' . $this->metadata['generated_at'] . '</li>';
        $html .= '<li><strong>Platform Version:</strong> ' . $this->metadata['platform_version'] . '</li>';
        $html .= '<li><strong>Calculation Version:</strong> ' . $this->metadata['calculation_version'] . '</li>';
        $html .= '<li><strong>Snapshot ID:</strong> #' . $this->snapshotId . '</li>';
        if ($this->seasonYear) {
            $html .= '<li><strong>Season:</strong> ' . $this->seasonYear . '</li>';
        }
        $html .= '</ul>';
        $html .= '</div>';

        // KPI Definitions
        if (!empty($this->kpiDefinitions)) {
            $html .= '<div class="kpi-definitions">';
            $html .= '<h4>KPI Definitions</h4>';
            $html .= '<dl>';
            foreach ($this->kpiDefinitions as $key => $definition) {
                $html .= '<dt>' . htmlspecialchars($this->formatKpiName($key)) . '</dt>';
                $html .= '<dd>' . htmlspecialchars($definition) . '</dd>';
            }
            $html .= '</dl>';
            $html .= '</div>';
        }

        // Reproducibility note
        $html .= '<div class="reproducibility">';
        $html .= '<h4>Reproducibility</h4>';
        $html .= '<p>This report can be reproduced using snapshot #' . $this->snapshotId . '. ';
        $html .= 'Contact system administrator for verification.</p>';
        $html .= '</div>';

        // GDPR note
        $html .= '<div class="gdpr-note">';
        $html .= '<p class="small">Data processed in accordance with GDPR. ';
        $html .= 'Personal data is anonymized where applicable.</p>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Formatera KPI-nyckel till lasbart namn
     *
     * @param string $key KPI-nyckel
     * @return string Lasbart namn
     */
    private function formatKpiName(string $key): string {
        return ucwords(str_replace('_', ' ', $key));
    }

    /**
     * Hamta HTML header med CSS
     *
     * @return string HTML header
     */
    private function getHtmlHeader(): string {
        return '<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($this->title) . '</title>
    <style>
        :root {
            --color-accent: #37d4d6;
            --color-bg: #0b131e;
            --color-card: #0e1621;
            --color-text: #f8f2f0;
            --color-text-secondary: #c7cfdd;
            --color-border: rgba(55, 212, 214, 0.2);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Manrope", "Segoe UI", sans-serif;
            background: var(--color-bg);
            color: var(--color-text);
            line-height: 1.6;
            padding: 40px;
        }

        .report-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--color-accent);
        }

        h1 {
            font-family: "Oswald", sans-serif;
            font-size: 2.5em;
            color: var(--color-accent);
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 1.2em;
            color: var(--color-text-secondary);
        }

        .meta {
            font-size: 0.9em;
            color: var(--color-text-secondary);
            margin-top: 10px;
        }

        .section {
            margin-bottom: 40px;
        }

        h2 {
            font-family: "Cabin Condensed", sans-serif;
            font-size: 1.5em;
            color: var(--color-accent);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--color-border);
        }

        .content { color: var(--color-text-secondary); }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
        }

        .data-table th {
            background: rgba(55, 212, 214, 0.1);
            color: var(--color-accent);
            font-weight: 600;
        }

        .data-table tr:hover { background: rgba(55, 212, 214, 0.05); }

        .chart {
            text-align: center;
            margin: 20px 0;
        }

        .chart img {
            max-width: 100%;
            height: auto;
        }

        /* Definition Box - MANDATORY */
        .definition-box {
            margin-top: 60px;
            padding: 25px;
            background: var(--color-card);
            border: 2px solid var(--color-border);
            border-radius: 10px;
            page-break-inside: avoid;
        }

        .definition-box h3 {
            color: var(--color-accent);
            font-size: 1.3em;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--color-border);
        }

        .definition-box h4 {
            color: var(--color-text);
            font-size: 1em;
            margin: 15px 0 10px;
        }

        .definition-box ul {
            list-style: none;
            padding-left: 0;
        }

        .definition-box li {
            color: var(--color-text-secondary);
            margin-bottom: 5px;
            font-size: 0.9em;
        }

        .definition-box dl {
            margin: 10px 0;
        }

        .definition-box dt {
            color: var(--color-text);
            font-weight: 600;
            margin-top: 10px;
        }

        .definition-box dd {
            color: var(--color-text-secondary);
            margin-left: 15px;
            font-size: 0.9em;
        }

        .definition-box .small {
            font-size: 0.8em;
            color: var(--color-text-secondary);
            font-style: italic;
        }

        .error {
            color: #ef4444;
            font-style: italic;
        }

        @media print {
            body { background: white; color: black; padding: 20px; }
            :root {
                --color-accent: #0066cc;
                --color-text: #000;
                --color-text-secondary: #333;
                --color-border: #ccc;
                --color-card: #f5f5f5;
            }
            .definition-box { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
';
    }

    /**
     * Hamta HTML footer
     *
     * @return string HTML footer
     */
    private function getHtmlFooter(): string {
        return '
<footer style="margin-top: 40px; text-align: center; color: var(--color-text-secondary); font-size: 0.8em;">
    <p>TheHUB Analytics Platform ' . AnalyticsConfig::PLATFORM_VERSION . ' | GravitySeries</p>
</footer>
</body>
</html>';
    }

    /**
     * Exportera till PDF (kraver wkhtmltopdf eller liknande)
     *
     * @param string|null $outputPath Filsokvag (null = returnera binart)
     * @return string|bool PDF-data eller true vid fil-export
     */
    public function exportToPdf(?string $outputPath = null): string|bool {
        $html = $this->buildHtml();

        // Forsok anvanda wkhtmltopdf
        $wkhtmltopdf = $this->findWkhtmltopdf();

        if ($wkhtmltopdf) {
            return $this->exportWithWkhtmltopdf($html, $outputPath, $wkhtmltopdf);
        }

        // Fallback: Returnera HTML med instruktion om print to PDF
        if ($outputPath) {
            // Spara som HTML med .html extension
            $htmlPath = preg_replace('/\.pdf$/i', '.html', $outputPath);
            file_put_contents($htmlPath, $html);

            // Logga att PDF-konvertering inte ar tillganglig
            error_log('PdfExportBuilder: wkhtmltopdf not available, saved as HTML');

            return false;
        }

        return $html;
    }

    /**
     * Hitta wkhtmltopdf binary
     *
     * @return string|null Sokvag eller null
     */
    private function findWkhtmltopdf(): ?string {
        $paths = [
            '/usr/bin/wkhtmltopdf',
            '/usr/local/bin/wkhtmltopdf',
            'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Forsok hitta i PATH
        $which = shell_exec('which wkhtmltopdf 2>/dev/null');
        if ($which) {
            return trim($which);
        }

        return null;
    }

    /**
     * Exportera med wkhtmltopdf
     *
     * @param string $html HTML-innehall
     * @param string|null $outputPath Utfil
     * @param string $wkhtmltopdf Sokvag till wkhtmltopdf
     * @return string|bool
     */
    private function exportWithWkhtmltopdf(string $html, ?string $outputPath, string $wkhtmltopdf): string|bool {
        $tempHtml = tempnam(sys_get_temp_dir(), 'pdf_') . '.html';
        file_put_contents($tempHtml, $html);

        $tempPdf = $outputPath ?? tempnam(sys_get_temp_dir(), 'pdf_') . '.pdf';

        $cmd = sprintf(
            '%s --quiet --enable-local-file-access --page-size A4 --margin-top 15mm --margin-bottom 15mm --margin-left 15mm --margin-right 15mm %s %s 2>&1',
            escapeshellarg($wkhtmltopdf),
            escapeshellarg($tempHtml),
            escapeshellarg($tempPdf)
        );

        exec($cmd, $output, $returnCode);
        unlink($tempHtml);

        if ($returnCode !== 0) {
            error_log('wkhtmltopdf failed: ' . implode("\n", $output));
            return false;
        }

        if ($outputPath) {
            return true;
        }

        $pdf = file_get_contents($tempPdf);
        unlink($tempPdf);

        return $pdf;
    }

    /**
     * Logga exporten
     *
     * @param string $exportType Typ av rapport
     * @param array $data Exporterad data
     * @param array $options Extra optioner
     * @return int Export ID
     */
    public function logExport(string $exportType, array $data, array $options = []): int {
        $options['snapshot_id'] = $this->snapshotId;
        $options['year'] = $this->seasonYear;
        $options['format'] = 'pdf';

        return $this->logger->logExport($exportType, $data, $options);
    }

    /**
     * Hamta snapshot-information
     *
     * @return array|null Snapshot data
     */
    public function getSnapshotInfo(): ?array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM analytics_snapshots WHERE id = ?
        ");
        $stmt->execute([$this->snapshotId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
