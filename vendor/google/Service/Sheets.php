<?php
/**
 * A greatly simplified Google API Sheets Service class.
 *
 * @package    Wpcs_Price_Tracker
 * @subpackage Wpcs_Price_Tracker/vendor/google
 * @since      2.2.0
 */

if (!class_exists('Google_Service_Sheets')) {
    class Google_Service_Sheets {
        public $spreadsheets;
        public $spreadsheets_values;

        public function __construct(Google_Client $client) {
            $this->spreadsheets = new class($client) {
                private $client;
                public function __construct($client) { $this->client = $client; }
                public function get($spreadsheetId) {
                    $token = $this->client->getAccessToken();
                    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}";
                    $response = wp_remote_get($url, [
                        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']]
                    ]);
                    // Error handling can be added here
                    return json_decode(wp_remote_retrieve_body($response));
                }
            };
            $this->spreadsheets_values = new class($client) {
                private $client;
                public function __construct($client) { $this->client = $client; }
                public function get($spreadsheetId, $range) {
                    $token = $this->client->getAccessToken();
                    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/" . urlencode($range);
                    $response = wp_remote_get($url, [
                        'headers' => ['Authorization' => 'Bearer ' . $token['access_token']]
                    ]);
                    
                    $body = json_decode(wp_remote_retrieve_body($response));
                    if (isset($body->error)) {
                         $error_message = 'Google API Error: ' . $body->error->message;
                        if (isset($body->error->details) && is_array($body->error->details)) {
                            foreach($body->error->details as $detail) {
                                if(is_object($detail) && isset($detail->reason)) {
                                    $error_message .= ' Reason: ' . $detail->reason;
                                }
                            }
                        }
                        throw new Exception($error_message);
                    }
                    return new Google_Service_Sheets_ValueRange($body);
                }
                public function batchUpdate($spreadsheetId, Google_Service_Sheets_BatchUpdateValuesRequest $postBody) {
                    $token = $this->client->getAccessToken();
                    $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values:batchUpdate";
                    $response = wp_remote_post($url, [
                        'method' => 'POST',
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token['access_token'],
                            'Content-Type' => 'application/json'
                        ],
                        'body' => json_encode($postBody)
                    ]);
                    
                    $body = json_decode(wp_remote_retrieve_body($response));
                    if (isset($body->error)) {
                        $error_message = 'Google API Write Error: ' . $body->error->message;
                        throw new Exception($error_message);
                    }
                }
            };
        }
    }
}
if (!class_exists('Google_Service_Sheets_ValueRange')) {
    class Google_Service_Sheets_ValueRange {
        public $range;
        public $values;

        public function __construct($data = []) {
            if (is_object($data)) {
                if (isset($data->range)) $this->range = $data->range;
                if (isset($data->values)) $this->values = $data->values;
            } elseif (is_array($data)) {
                if (isset($data['range'])) $this->range = $data['range'];
                if (isset($data['values'])) $this->values = $data['values'];
            }
        }
        public function getValues() { return $this->values; }
    }
}

if (!class_exists('Google_Service_Sheets_BatchUpdateValuesRequest')) {
    class Google_Service_Sheets_BatchUpdateValuesRequest {
        public $data;
        public $valueInputOption;
        public function __construct(array $data) {
            foreach ($data as $key => $val) {
                $this->{$key} = $val;
            }
        }
    }
}

