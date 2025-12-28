<?php

namespace Everest\Services\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

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
        $this->apiKey = config('modules.ai.key', '');
        $this->endpoint = config('modules.ai.endpoint', 'https://api.openai.com/v1');
        $this->model = config('modules.ai.model', 'gpt-3.5-turbo');

        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Send a query to the OpenAI-compatible endpoint and get a response.
     *
     * @throws \Exception
     */
    public function query(string $prompt, array $options = []): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('AI API key is not configured.');
        }

        try {
            $response = $this->client->post('chat/completions', [
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

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['choices'][0]['message']['content'])) {
                return trim($data['choices'][0]['message']['content']);
            }

            throw new \Exception('Invalid response from AI service.');
        } catch (GuzzleException $e) {
            Log::error('OpenAI Service Error: ' . $e->getMessage());
            throw new \Exception('Failed to communicate with AI service: ' . $e->getMessage());
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
        } catch (\Exception $e) {
            return false;
        }
    }
}
