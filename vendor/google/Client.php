<?php
/**
 * A greatly simplified Google API Client class.
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/vendor/google
 * @since      2.2.0
 */

if (!class_exists('Google_Client')) {
    class Google_Client {
        const SCOPE_SPREADSHEETS_READONLY = 'https://www.googleapis.com/auth/spreadsheets.readonly';

        private $authConfig;
        private $accessToken;
        private $scopes = [];

        public function setAuthConfig(array $config) {
            $this->authConfig = $config;
        }

        public function setScopes($scopes) {
            $this->scopes = is_array($scopes) ? $scopes : [$scopes];
        }

        public function getAccessToken() {
            return $this->accessToken;
        }

        public function fetchAccessTokenWithAssertion() {
            $iat = time();
            $payload = [
                'iss'   => $this->authConfig['client_email'],
                'aud'   => 'https://oauth2.googleapis.com/token',
                'exp'   => $iat + 3600,
                'iat'   => $iat,
                'scope' => implode(' ', $this->scopes),
            ];

            $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = base64_encode(json_encode($payload));
            $signature_input = "$header.$payload";

            openssl_sign($signature_input, $signature, $this->authConfig['private_key'], 'sha256');
            $jwt = "$header.$payload." . base64_encode($signature);
            
            $response = wp_remote_post('https://oauth2.googleapis.com/token', [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ],
            ]);

            if (is_wp_error($response)) {
                throw new Exception('Token request failed: ' . $response->get_error_message());
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['error'])) {
                $error_description = $body['error_description'] ?? 'No description provided.';
                throw new Exception('Token error: ' . $body['error'] . ' - ' . $error_description);
            }

            $this->accessToken = $body;
            return $this->accessToken;
        }
    }
}

