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
    private string $mode;
    private string $systemPrompt;

    /**
     * OpenAIService constructor.
     */
    public function __construct()
    {
        $this->apiKey = config('modules.ai.key') ?: '';
        $this->endpoint = config('modules.ai.endpoint') ?: 'https://api.openai.com/v1';
        $this->model = config('modules.ai.model') ?: 'gpt-4.1-mini';
        $this->mode = config('modules.ai.mode') ?: 'openai';
        $this->systemPrompt = config('modules.ai.system_prompt') ?: 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.';

        // Initialize client without authorization header to prevent credential exposure in logs
        // Increase timeout to 120 seconds to handle longer AI responses
        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'timeout' => 120,
            #'stream' => true, // Enable streaming support
        ]);
    }

    /**
     * Send a query to the OpenAI-compatible endpoint and get a response.
     *
     * @throws AIServiceException
     */
    public function query(string $prompt, array $options = []): string
    {
        // Only require API key for OpenAI mode, not for Ollama
        if ($this->mode !== 'ollama' && empty($this->apiKey)) {
            throw new AIServiceException('AI API key is not configured.');
        }

        try {
            $headers = [
                'Content-Type' => 'application/json',
            ];
            
            // Only add Authorization header if API key is provided (OpenAI mode)
            if (!empty($this->apiKey)) {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }
            
            // Build request payload based on mode
            if ($this->mode === 'openai') {
                // OpenAI new API format
                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => [
                                ['type' => 'input_text', 'text' => $options['system_prompt'] ?? $this->systemPrompt]
                            ]
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => $prompt]
                            ]
                        ]
                    ],
                    'max_output_tokens' => $options['max_tokens'] ?? (int)config('modules.ai.max_tokens', 200),
                ];
            } else {
                // Ollama format (keep existing)
                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $options['system_prompt'] ?? $this->systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens' => $options['max_tokens'] ?? (int)config('modules.ai.max_tokens', 200),
                    'temperature' => $options['temperature'] ?? 0.7,
                    'stream' => $options['stream'] ?? false,
                ];
            }

            $endpoint = $this->mode === 'openai' ? 'responses' : 'chat/completions';
            
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('AI Service JSON decode error: ' . json_last_error_msg());
                throw new AIServiceException('Failed to decode AI service response: ' . json_last_error_msg());
            }

            if ($this->mode === 'openai') {
                // OpenAI new API response format
                if (isset($data['output_text'])) {
                    return trim($data['output_text']);
                }
            } else {
                // Ollama response format
                if (isset($data['choices'][0]['message']['content'])) {
                    return trim($data['choices'][0]['message']['content']);
                }
            }

            if (isset($data['error'])) {
                $errorMsg = $data['error']['message'] ?? 'Unknown error';
                Log::error('AI Service returned error: ' . $errorMsg);
                throw new AIServiceException('AI service error: ' . $errorMsg);
            }

            throw new AIServiceException('Invalid response format from AI service.');
        } catch (GuzzleException $e) {
            Log::error('OpenAI Service Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                Log::error('OpenAI Response Body: ' . (string) $e->getResponse()->getBody());
            }
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

    /**
     * Stream a query to the OpenAI-compatible endpoint and yield chunks.
     *
     * @throws AIServiceException
     */
    public function queryStream(string $prompt, array $options = []): \Generator
    {
        // Only require API key for OpenAI mode, not for Ollama
        if ($this->mode !== 'ollama' && empty($this->apiKey)) {
            throw new AIServiceException('AI API key is not configured.');
        }

        try {
            $headers = [
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ];
            
            // Only add Authorization header if API key is provided (OpenAI mode)
            if (!empty($this->apiKey)) {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }
            
            // Build request payload based on mode
            if ($this->mode === 'openai') {
                // OpenAI new API format
                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => [
                                ['type' => 'input_text', 'text' => $options['system_prompt'] ?? $this->systemPrompt]
                            ]
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => $prompt]
                            ]
                        ]
                    ],
                    'max_output_tokens' => $options['max_tokens'] ?? (int)config('modules.ai.max_tokens', 200),
                ];
            } else {
                // Ollama format (keep existing)
                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $options['system_prompt'] ?? $this->systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                    'max_tokens' => $options['max_tokens'] ?? (int)config('modules.ai.max_tokens', 200),
                    'temperature' => $options['temperature'] ?? 0.7,
                    'stream' => true,
                ];
            }

            $endpoint = $this->mode === 'openai' ? 'responses' : 'chat/completions';
            
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $payload,
            ]);

            $body = $response->getBody();
            $buffer = '';
            $currentEvent = null;

            while (!$body->eof()) {
                $chunk = $body->read(1024);
                $buffer .= $chunk;

                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    $line = trim($line);
                    if (empty($line) || $line === 'data: [DONE]') {
                        continue;
                    }

                    // Track event type for OpenAI's new streaming format
                    if (str_starts_with($line, 'event: ')) {
                        $currentEvent = substr($line, 7);
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $jsonData = substr($line, 6);
                        $data = json_decode($jsonData, true);

                        if (json_last_error() === JSON_ERROR_NONE) {
                            if ($this->mode === 'openai') {
                                // OpenAI new API: streaming sends event-based chunks with 'text' field
                                if ($currentEvent === 'response.output_text.delta' && isset($data['text'])) {
                                    yield $data['text'];
                                }
                            } else {
                                // Ollama: use existing format
                                if (isset($data['choices'][0]['delta']['content'])) {
                                    yield $data['choices'][0]['delta']['content'];
                                }
                            }
                        }
                        
                        $currentEvent = null; // Reset event after processing data
                    }
                }
            }
        } catch (GuzzleException $e) {
            Log::error('OpenAI Service Streaming Error: ' . $e->getMessage());
            if ($e->hasResponse()) {
                Log::error('OpenAI Streaming Response Body: ' . (string) $e->getResponse()->getBody());
            }
            throw new AIServiceException('Failed to communicate with AI service: ' . $e->getMessage());
        }
    }
}