<?php

class MarkItDownClient {
    private $apiUrl;
    private $apiKey;

    public function __construct(string $apiUrl, string $apiKey) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * ファイルをMarkdownに変換
     * 
     * @param string $filePath 変換するファイルのパス
     * @return array 変換結果（'markdown' => 変換されたテキスト, 'cached' => キャッシュ使用有無）
     * @throws Exception 変換失敗時
     */
    public function convertToMarkdown(string $filePath): array {
        error_log('Starting file conversion. File path: ' . $filePath, 3, './common/logs.txt');

        if (!file_exists($filePath)) {
            error_log('File not found: ' . $filePath, 3, './common/logs.txt');
            throw new Exception("File not found: {$filePath}");
        }

        $curl = curl_init();
        $postFields = [
            'file' => new CURLFile($filePath)
        ];

        error_log('Sending request to: ' . $this->apiUrl . '/convert', 3, './common/logs.txt');
        error_log('API Key: ' . $this->apiKey, 3, './common/logs.txt');

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl . '/convert',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        error_log('Response HTTP code: ' . $httpCode, 3, './common/logs.txt');
        error_log('Response body: ' . $response, 3, './common/logs.txt');

        if ($error = curl_error($curl)) {
            error_log('Curl error: ' . $error, 3, './common/logs.txt');
            curl_close($curl);
            throw new Exception("API request failed: {$error}");
        }
        
        curl_close($curl);

        if ($httpCode !== 200) {
            error_log('API error: Non-200 status code received', 3, './common/logs.txt');
            //throw new Exception("API returned error code: {$httpCode}");
            throw new Exception("変換できないファイルです。");
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error: ' . json_last_error_msg(), 3, './common/logs.txt');
            throw new Exception("Failed to parse API response");
        }

        error_log('Conversion successful', 3, './common/logs.txt');
        return $result;
    }

    /**
     * APIサーバーの健康状態をチェック
     * 
     * @return bool サーバーが正常に動作しているかどうか
     */
    public function checkHealth(): bool {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl . '/health',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        
        curl_close($curl);

        if ($httpCode !== 200) {
            return false;
        }

        $result = json_decode($response, true);
        return isset($result['status']) && $result['status'] === 'healthy';
    }
}