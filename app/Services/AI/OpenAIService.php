<?php

namespace Everest\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Everest\Exceptions\Service\AI\AIServiceException;

class OpenAIService
{
    private Client $client;
    private string $apiKey;
    private string $endpoint;
    private string $model;

    /**
     * OpenAIService constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('modules.ai.key') ?: '';
        $this->endpoint = config('modules.ai.endpoint') ?: 'https://api.openai.com/v1';
        $this->model = config('modules.ai.model') ?: 'gpt-3.5-turbo';

        // Initialize client without authorization header to prevent credential exposure in logs
        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'timeout' => 30,
        ]);
    }

    /**
     * Send a query to the OpenAI-compatible endpoint and get a response.
     *
     * @throws AIServiceException
     */
    public function query(string $prompt, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw new AIServiceException('AI API key is not configured.');
        }

        try {
            $response = $this->client->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $options['model'] ?? $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens' => $options['max_tokens'] ?? 1000,
                    'temperature' => $options['temperature'] ?? 0.7,
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('AI Service JSON decode error: ' . json_last_error_msg());
                throw new AIServiceException('Failed to decode AI service response: ' . json_last_error_msg());
            }

            if (isset($data['choices'][0]['message']['content'])) {
                return trim($data['choices'][0]['message']['content']);
            }

            if (isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'Unknown error';
                Log::error('AI Service returned error: ' . $errorMsg);
                throw new AIServiceException('AI service error: ' . $errorMsg);
            }

            throw new AIServiceException('Invalid response format from AI service.');
        } catch (GuzzleException $e) {
            Log::error('OpenAI Service Error: ' . $e->getMessage());
            throw new AIServiceException('Failed to communicate with AI service: ' . $e->getMessage());
        }
    }

    /**
     * Test the connection to the AI endpoint.
     */
    public function testConnection(): bool
    {
        try {
            $this->query('Hello, this is a test message. Please respond with OK.');
            return true;
        } catch (AIServiceException $e) {
            Log::warning('AI Service connection test failed: ' . $e->getMessage());
            return false;
        }
    }
}
