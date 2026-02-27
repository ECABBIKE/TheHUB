<?php
/**
 * Cloudflare R2 Storage Client
 * Lättviktig S3-kompatibel klient med AWS Signature V4
 * Kräver inga externa beroenden (ren cURL)
 *
 * Cloudflare R2:
 * - S3-kompatibelt API
 * - $0 utgående bandbredd (egress)
 * - 10 GB gratis lagring
 * - Perfekt för bildhosting
 */

class R2Storage {
    private string $accountId;
    private string $accessKeyId;
    private string $secretAccessKey;
    private string $bucket;
    private string $publicUrl;
    private string $endpoint;
    private string $region = 'auto';

    private static ?R2Storage $instance = null;

    /**
     * Hämta singleton-instans (konfigurerad via env())
     */
    public static function getInstance(): ?R2Storage {
        if (self::$instance === null) {
            $accountId = env('R2_ACCOUNT_ID', '');
            $accessKeyId = env('R2_ACCESS_KEY_ID', '');
            $secretAccessKey = env('R2_SECRET_ACCESS_KEY', '');
            $bucket = env('R2_BUCKET', '');
            $publicUrl = env('R2_PUBLIC_URL', '');

            if (!$accountId || !$accessKeyId || !$secretAccessKey || !$bucket) {
                return null;
            }

            self::$instance = new self($accountId, $accessKeyId, $secretAccessKey, $bucket, $publicUrl);
        }
        return self::$instance;
    }

    /**
     * Kolla om R2 är konfigurerat
     */
    public static function isConfigured(): bool {
        return !empty(env('R2_ACCOUNT_ID', ''))
            && !empty(env('R2_ACCESS_KEY_ID', ''))
            && !empty(env('R2_SECRET_ACCESS_KEY', ''))
            && !empty(env('R2_BUCKET', ''));
    }

    public function __construct(string $accountId, string $accessKeyId, string $secretAccessKey, string $bucket, string $publicUrl = '') {
        $this->accountId = $accountId;
        $this->accessKeyId = $accessKeyId;
        $this->secretAccessKey = $secretAccessKey;
        $this->bucket = $bucket;
        // Sanera publicUrl - hantera korrupt .env med dubbla = (t.ex. "https://x.r2.dev=https://y.r2.dev")
        $cleanUrl = $publicUrl;
        if ($cleanUrl && substr_count($cleanUrl, 'https://') > 1) {
            // Ta sista giltiga https:// URL:en
            $parts = explode('https://', $cleanUrl);
            $cleanUrl = 'https://' . end($parts);
        }
        $this->publicUrl = rtrim($cleanUrl, '/');
        $this->endpoint = "https://{$accountId}.r2.cloudflarestorage.com";
    }

    /**
     * Ladda upp en fil till R2
     *
     * @param string $localPath Sökväg till lokal fil
     * @param string $key Objekt-nyckel (sökväg i bucketen, t.ex. "events/123/photo.jpg")
     * @param string $contentType MIME-typ
     * @return array ['success' => bool, 'url' => string, 'key' => string, 'error' => string]
     */
    public function upload(string $localPath, string $key, string $contentType = ''): array {
        if (!file_exists($localPath)) {
            return ['success' => false, 'error' => 'Filen finns inte: ' . $localPath];
        }

        $body = file_get_contents($localPath);
        if ($body === false) {
            return ['success' => false, 'error' => 'Kunde inte läsa filen'];
        }

        if (!$contentType) {
            $contentType = $this->guessContentType($localPath);
        }

        return $this->putObject($key, $body, $contentType);
    }

    /**
     * Ladda upp binärdata direkt (utan lokal fil)
     *
     * @param string $key Objekt-nyckel
     * @param string $body Filinnehållet
     * @param string $contentType MIME-typ
     * @return array
     */
    public function putObject(string $key, string $body, string $contentType = 'application/octet-stream'): array {
        $key = ltrim($key, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$key}";

        $headers = $this->signRequest('PUT', "/{$this->bucket}/{$key}", $body, [
            'Content-Type' => $contentType,
            'Content-Length' => (string)strlen($body),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL-fel: ' . $curlError];
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'key' => $key,
                'url' => $this->getPublicUrl($key),
                'size' => strlen($body),
            ];
        }

        return [
            'success' => false,
            'error' => "HTTP {$httpCode}: " . $this->parseErrorMessage($response),
            'http_code' => $httpCode,
        ];
    }

    /**
     * Radera ett objekt från R2
     *
     * @param string $key Objekt-nyckel
     * @return array ['success' => bool, 'error' => string]
     */
    public function deleteObject(string $key): array {
        $key = ltrim($key, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$key}";

        $headers = $this->signRequest('DELETE', "/{$this->bucket}/{$key}", '', []);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL-fel: ' . $curlError];
        }

        // 204 = deleted, 404 = already gone (both ok)
        if ($httpCode === 204 || $httpCode === 404) {
            return ['success' => true];
        }

        return [
            'success' => false,
            'error' => "HTTP {$httpCode}: " . $this->parseErrorMessage($response),
        ];
    }

    /**
     * Kolla om ett objekt existerar
     *
     * @param string $key Objekt-nyckel
     * @return bool
     */
    public function exists(string $key): bool {
        $key = ltrim($key, '/');
        $url = "{$this->endpoint}/{$this->bucket}/{$key}";

        $headers = $this->signRequest('HEAD', "/{$this->bucket}/{$key}", '', []);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Lista objekt i en mapp (prefix)
     *
     * @param string $prefix Sökvägsprefix (t.ex. "events/123/")
     * @param int $maxKeys Max antal resultat
     * @return array ['success' => bool, 'objects' => [...], 'error' => string]
     */
    public function listObjects(string $prefix = '', int $maxKeys = 1000): array {
        $query = http_build_query(array_filter([
            'list-type' => '2',
            'prefix' => $prefix,
            'max-keys' => $maxKeys,
        ]));

        $path = "/{$this->bucket}/";
        $url = "{$this->endpoint}/{$this->bucket}/?{$query}";

        $headers = $this->signRequest('GET', $path, '', [], $query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL-fel: ' . $curlError];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: " . $this->parseErrorMessage($response)];
        }

        // Parse XML response
        $objects = [];
        try {
            $xml = new SimpleXMLElement($response);
            // Register namespace if present
            $namespaces = $xml->getNamespaces(true);
            if (!empty($namespaces)) {
                $ns = reset($namespaces);
                $xml->registerXPathNamespace('s3', $ns);
                $contents = $xml->xpath('//s3:Contents');
            } else {
                $contents = $xml->Contents ?? [];
            }

            foreach ($contents as $obj) {
                $objects[] = [
                    'key' => (string)$obj->Key,
                    'size' => (int)$obj->Size,
                    'last_modified' => (string)$obj->LastModified,
                ];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'XML-parsningsfel: ' . $e->getMessage()];
        }

        return ['success' => true, 'objects' => $objects];
    }

    /**
     * Generera publik URL för ett objekt
     *
     * @param string $key Objekt-nyckel
     * @return string Publik URL
     */
    public function getPublicUrl(string $key): string {
        $key = ltrim($key, '/');
        if ($this->publicUrl) {
            return "{$this->publicUrl}/{$key}";
        }
        // Fallback till R2.dev-URL (kräver att public access är aktiverat)
        return "https://pub-{$this->accountId}.r2.dev/{$key}";
    }

    /**
     * Testa anslutningen till R2
     *
     * @return array ['success' => bool, 'message' => string, 'bucket' => string]
     */
    public function testConnection(): array {
        $result = $this->listObjects('', 1);
        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'Anslutning till R2 fungerar',
                'bucket' => $this->bucket,
                'endpoint' => $this->endpoint,
            ];
        }
        return [
            'success' => false,
            'message' => 'Kunde inte ansluta: ' . ($result['error'] ?? 'Okänt fel'),
            'bucket' => $this->bucket,
            'endpoint' => $this->endpoint,
        ];
    }

    /**
     * Generera en unik objekt-nyckel för ett eventfoto
     *
     * @param int $eventId Event-ID
     * @param string $filename Originalfilnamn
     * @return string Objekt-nyckel (t.ex. "events/123/a1b2c3d4_photo.jpg")
     */
    public static function generatePhotoKey(int $eventId, string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'jpg';
        $hash = substr(md5(uniqid(mt_rand(), true)), 0, 8);
        $safeName = preg_replace('/[^a-z0-9_.-]/', '', strtolower(pathinfo($filename, PATHINFO_FILENAME)));
        $safeName = substr($safeName, 0, 50);
        return "events/{$eventId}/{$hash}_{$safeName}.{$ext}";
    }

    /**
     * Optimera bild innan uppladdning (skala ner + komprimera)
     *
     * @param string $inputPath Sökväg till originalfil
     * @param int $maxWidth Max bredd i pixlar
     * @param int $quality JPEG-kvalitet (1-100)
     * @return array ['path' => string, 'width' => int, 'height' => int, 'size' => int]
     */
    public static function optimizeImage(string $inputPath, int $maxWidth = 1920, int $quality = 82): array {
        $info = getimagesize($inputPath);
        if (!$info) {
            return ['path' => $inputPath, 'width' => 0, 'height' => 0, 'size' => filesize($inputPath)];
        }

        [$origWidth, $origHeight, $type] = $info;

        // Skapa GD-resurs från originalfil
        $src = null;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $src = @imagecreatefromjpeg($inputPath);
                break;
            case IMAGETYPE_PNG:
                $src = @imagecreatefrompng($inputPath);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($inputPath);
                }
                break;
        }

        if (!$src) {
            return ['path' => $inputPath, 'width' => $origWidth, 'height' => $origHeight, 'size' => filesize($inputPath)];
        }

        // Beräkna ny storlek
        $newWidth = $origWidth;
        $newHeight = $origHeight;
        if ($origWidth > $maxWidth) {
            $ratio = $maxWidth / $origWidth;
            $newWidth = $maxWidth;
            $newHeight = (int)round($origHeight * $ratio);
        }

        // Skala om om det behövs
        if ($newWidth !== $origWidth) {
            $dst = imagecreatetruecolor($newWidth, $newHeight);
            // Bevara transparens för PNG
            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
            imagedestroy($src);
            $src = $dst;
        }

        // Spara optimerad version
        $tmpPath = tempnam(sys_get_temp_dir(), 'r2opt_');
        switch ($type) {
            case IMAGETYPE_PNG:
                imagepng($src, $tmpPath, 6);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagewebp')) {
                    imagewebp($src, $tmpPath, $quality);
                } else {
                    imagejpeg($src, $tmpPath, $quality);
                }
                break;
            default:
                imagejpeg($src, $tmpPath, $quality);
                break;
        }
        imagedestroy($src);

        return [
            'path' => $tmpPath,
            'width' => $newWidth,
            'height' => $newHeight,
            'size' => filesize($tmpPath),
        ];
    }

    /**
     * Generera thumbnail (mindre version)
     *
     * @param string $inputPath Sökväg till originalfil
     * @param int $maxWidth Max bredd
     * @param int $quality JPEG-kvalitet
     * @return array ['path' => string, 'width' => int, 'height' => int]
     */
    public static function generateThumbnail(string $inputPath, int $maxWidth = 400, int $quality = 75): array {
        return self::optimizeImage($inputPath, $maxWidth, $quality);
    }

    // =========================================================================
    // AWS Signature V4 Implementation
    // =========================================================================

    /**
     * Signera en HTTP-förfrågan med AWS Signature V4
     */
    private function signRequest(string $method, string $path, string $body, array $extraHeaders, string $queryString = ''): array {
        $service = 's3';
        $now = new \DateTime('UTC');
        $dateStamp = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');

        $host = str_replace('https://', '', $this->endpoint);

        $headers = array_merge([
            'Host' => $host,
            'x-amz-date' => $amzDate,
            'x-amz-content-sha256' => hash('sha256', $body),
        ], $extraHeaders);

        // Canonical headers (lowercase, sorted)
        $canonicalHeaders = '';
        $signedHeadersList = [];
        $sortedHeaders = [];
        foreach ($headers as $k => $v) {
            $sortedHeaders[strtolower($k)] = trim($v);
        }
        ksort($sortedHeaders);
        foreach ($sortedHeaders as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
            $signedHeadersList[] = $k;
        }
        $signedHeaders = implode(';', $signedHeadersList);

        // Canonical query string
        $canonicalQueryString = '';
        if ($queryString) {
            parse_str($queryString, $params);
            ksort($params);
            $canonicalQueryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        // Canonical request
        $payloadHash = hash('sha256', $body);
        $canonicalRequest = implode("\n", [
            $method,
            $this->uriEncodePath($path),
            $canonicalQueryString,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        // Scope
        $scope = "{$dateStamp}/{$this->region}/{$service}/aws4_request";

        // String to sign
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        // Signing key
        $signingKey = $this->getSignatureKey($this->secretAccessKey, $dateStamp, $this->region, $service);

        // Signature
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        // Authorization header
        $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        return $headers;
    }

    /**
     * Generera signing key
     */
    private function getSignatureKey(string $key, string $dateStamp, string $region, string $service): string {
        $kDate = hash_hmac('sha256', $dateStamp, "AWS4{$key}", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * URI-encode path components
     */
    private function uriEncodePath(string $path): string {
        $segments = explode('/', $path);
        $encoded = array_map(function($s) {
            return rawurlencode($s);
        }, $segments);
        return implode('/', $encoded);
    }

    /**
     * Formatera headers som array av "Key: Value" strängar för cURL
     */
    private function formatHeaders(array $headers): array {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "{$key}: {$value}";
        }
        return $formatted;
    }

    /**
     * Gissa MIME-typ baserat på filnamn
     */
    private function guessContentType(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
        ];
        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * Parsa felmeddelande från S3 XML-svar
     */
    private function parseErrorMessage(string $xml): string {
        if (empty($xml)) return 'Tomt svar';
        try {
            $doc = new \SimpleXMLElement($xml);
            return (string)($doc->Message ?? $doc->Code ?? 'Okänt fel');
        } catch (\Exception $e) {
            return substr($xml, 0, 200);
        }
    }
}
