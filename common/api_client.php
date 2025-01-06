<?php

class APIClient
{
    private $config;

    public function __construct()
    {
        $this->config = require 'config.php';
    }

    /**
     * Make a request to OpenAI or Claude API.
     * 
     * @param string $provider 'openai' or 'claude'
     * @param string $endpoint The API endpoint (e.g., '/chat/completions')
     * @param array $payload Request payload
     * @return array|false The API response or false on failure
     */
    public function request($provider, $endpoint, $payload)
    {
        if (!isset($this->config[$provider])) {
            throw new Exception("Invalid provider: $provider");
        }

        $apiKey = $this->config[$provider]['api_key'];
        $baseUrl = $this->config[$provider]['base_url'];
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            "Authorization: Bearer $apiKey",
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        // Log error details if needed
        error_log("API request failed. HTTP code: $httpCode, Response: $response");

        return false;
    }

    /**
     * OpenAIまたはClaude APIにリクエストを送信します。
     * 
     * @param string $provider 'openai'または'claude'
     * @param string $prompt プロンプト
     * @return string|false 生成されたテキストまたは失敗時はfalse
     */
    public function generateResponse($provider, $prompt)
    {
        $payload = [];
        if ($provider === 'openai') {
            $payload = [
                'model' => 'gpt-4o-mini', // 必要に応じてモデル名を確認
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a helpful assistant.'], // systemメッセージを追加
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 2000,
            ];
            $response = $this->request('openai', '/chat/completions', $payload);
        } elseif ($provider === 'claude') {
            $payload = [
                'prompt' => $prompt,
                'max_tokens_to_sample' => 100,
                'temperature' => 0.7,
            ];
            $response = $this->request('claude', '/completions', $payload);
        }

        return $response ? $response['choices'][0]['message']['content'] : false;
    }
}
