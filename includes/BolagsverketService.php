<?php
/**
 * BolagsverketService - Lookup company info via Bolagsverket API (Värdefulla datamängder)
 *
 * Free API (EU requirement). Requires registration at:
 * https://bolagsverket.se/apierochoppnadata/vardefulladatamangder/kundanmalantillapiforvardefulladatamangder.5528.html
 *
 * OAuth 2.0 client credentials flow.
 * Rate limit: 60 requests/minute.
 */
class BolagsverketService
{
    private string $clientId;
    private string $clientSecret;
    private string $tokenUrl;
    private string $apiBaseUrl;
    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct()
    {
        $this->clientId = env('BOLAGSVERKET_CLIENT_ID', '');
        $this->clientSecret = env('BOLAGSVERKET_CLIENT_SECRET', '');
        $this->tokenUrl = env('BOLAGSVERKET_TOKEN_URL', 'https://api.bolagsverket.se/token');
        $this->apiBaseUrl = env('BOLAGSVERKET_API_URL', 'https://api.bolagsverket.se');
    }

    /**
     * Check if the service is configured with credentials
     */
    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Lookup company info by org number
     *
     * @param string $orgNumber Swedish org number (XXXXXX-XXXX or XXXXXXXXXX)
     * @return array|null Company info or null on failure
     */
    public function lookupByOrgNumber(string $orgNumber): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        // Normalize org number: remove dash, spaces
        $orgNumber = preg_replace('/[^0-9]/', '', $orgNumber);
        if (strlen($orgNumber) !== 10) {
            return null;
        }

        // Format as XXXXXX-XXXX for the API
        $formatted = substr($orgNumber, 0, 6) . '-' . substr($orgNumber, 6);

        try {
            $token = $this->getAccessToken();
            if (!$token) {
                error_log("BolagsverketService: Failed to get access token");
                return null;
            }

            // Try the company info endpoint
            $response = $this->apiRequest("/foretagsinformation/v2/grund/{$formatted}");
            if (!$response) {
                // Fallback: try without dash
                $response = $this->apiRequest("/foretagsinformation/v2/grund/{$orgNumber}");
            }

            if (!$response) {
                return null;
            }

            return $this->parseCompanyResponse($response);

        } catch (Exception $e) {
            error_log("BolagsverketService lookup error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get OAuth 2.0 access token (client credentials flow)
     */
    private function getAccessToken(): ?string
    {
        // Return cached token if still valid
        if ($this->accessToken && time() < $this->tokenExpiresAt - 30) {
            return $this->accessToken;
        }

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("BolagsverketService token error: HTTP {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            error_log("BolagsverketService: No access_token in response");
            return null;
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * Make authenticated API request
     */
    private function apiRequest(string $path): ?array
    {
        $url = $this->apiBaseUrl . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("BolagsverketService API error: HTTP {$httpCode} for {$path}");
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Parse the API response into a standardized format
     */
    private function parseCompanyResponse(array $data): array
    {
        // The exact response structure depends on Bolagsverket's API version.
        // This covers common field paths from their documentation.
        $result = [
            'org_number' => '',
            'org_name' => '',
            'org_form' => '',
            'org_address' => '',
            'org_postal_code' => '',
            'org_city' => '',
        ];

        // Top-level fields
        $result['org_number'] = $data['organisationsnummer'] ?? $data['orgnr'] ?? '';
        $result['org_name'] = $data['foretagsnamn'] ?? $data['namn'] ?? $data['name'] ?? '';
        $result['org_form'] = $data['organisationsform'] ?? $data['foretagsform'] ?? '';

        // Address - try multiple structures
        $address = $data['postadress'] ?? $data['adress'] ?? $data['address'] ?? [];
        if (is_array($address)) {
            $result['org_address'] = $address['utdelningsadress'] ?? $address['gatuadress'] ?? $address['adress'] ?? $address['street'] ?? '';
            $result['org_postal_code'] = $address['postnummer'] ?? $address['postnr'] ?? $address['postcode'] ?? '';
            $result['org_city'] = $address['postort'] ?? $address['ort'] ?? $address['city'] ?? '';
        }

        // SCB data may be nested under a different key
        if (empty($result['org_name']) && isset($data['grunduppgifter'])) {
            $grund = $data['grunduppgifter'];
            $result['org_name'] = $grund['foretagsnamn'] ?? $grund['namn'] ?? '';
            $result['org_form'] = $grund['organisationsform'] ?? '';
        }

        if (empty($result['org_address']) && isset($data['grunduppgifter']['postadress'])) {
            $addr = $data['grunduppgifter']['postadress'];
            $result['org_address'] = $addr['utdelningsadress'] ?? '';
            $result['org_postal_code'] = $addr['postnummer'] ?? '';
            $result['org_city'] = $addr['postort'] ?? '';
        }

        return $result;
    }
}
