<?php

namespace Everest\Services\AI;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Exception\GuzzleException;
use Everest\Exceptions\Service\AI\AIServiceException;
use Everest\Models\Setting;

class OpenAIService
{
    private Client $client;
    private string $apiKey;
    private string $endpoint;
    private string $model;
    private string $mode;
    private string $systemPrompt;
    private float $temperature;

    /**
     * Token/latency data from the last non-streamed query().
     * Shape: ['model' => string, 'prompt_tokens' => int|null, 'completion_tokens' => int|null, 'total_tokens' => int|null]
     */
    private array $lastUsage = [];

    public function getLastUsage(): array
    {
        return $this->lastUsage;
    }

    /**
     * OpenAIService constructor.
     */
    public function __construct()
    {
        // All settings must be read from the database (via Setting::get) so that values
        // saved through the admin UI are actually used. Config/env values serve as fallbacks
        // only — they are NOT updated when settings are changed via the panel.
        $this->apiKey = Setting::get('settings::modules:ai:key', config('modules.ai.key')) ?: '';
        $this->endpoint = Setting::get('settings::modules:ai:endpoint', config('modules.ai.endpoint', 'https://api.openai.com/v1')) ?: 'https://api.openai.com/v1';
        $this->model = Setting::get('settings::modules:ai:model', config('modules.ai.model', 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
        $this->mode = Setting::get('settings::modules:ai:mode', config('modules.ai.mode', 'openai')) ?: 'openai';
        $this->systemPrompt = Setting::get('settings::modules:ai:system_prompt', config('modules.ai.system_prompt'))
            ?: 'You are an expert game server technician specializing in crash analysis and debugging. When given server logs, identify the root cause concisely and list specific actionable steps to resolve it. Format responses as: Cause: [what went wrong]. Fix: [numbered steps]. For general questions, give direct technical answers. Be concise.';
        $this->temperature = (float) (Setting::get('settings::modules:ai:temperature', config('modules.ai.temperature', 0.3)) ?? 0.3);

        $this->client = new Client([
            'base_uri' => rtrim($this->endpoint, '/') . '/',
            'timeout' => 120,
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
                                ['type' => 'input_text', 'text' => $options['system_prompt'] ?? $this->systemPrompt],
                            ],
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => $prompt],
                            ],
                        ],
                    ],
                    'max_output_tokens' => $options['max_tokens'] ?? (int) config('modules.ai.max_tokens', 200),
                ];
            } else {
                // Ollama / OpenAI-compatible format
                // Lower temperature (0.3) gives more deterministic, factual debugging answers.
                // num_ctx sets the context window so large logs are not silently truncated.
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
                    'max_tokens' => $options['max_tokens'] ?? (int) Setting::get('settings::modules:ai:max_tokens', config('modules.ai.max_tokens', 500)),
                    'temperature' => $options['temperature'] ?? $this->temperature,
                    'stream' => $options['stream'] ?? false,
                    'options' => [
                        'num_ctx' => 4096,
                    ],
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
                    $this->lastUsage = [
                        'model' => $options['model'] ?? $this->model,
                        'prompt_tokens' => $data['usage']['input_tokens'] ?? null,
                        'completion_tokens' => $data['usage']['output_tokens'] ?? null,
                        'total_tokens' => $data['usage']['total_tokens'] ?? null,
                    ];
                    return trim($data['output_text']);
                }
            } else {
                // Ollama / chat-completions response format
                if (isset($data['choices'][0]['message']['content'])) {
                    $this->lastUsage = [
                        'model' => $options['model'] ?? $this->model,
                        'prompt_tokens' => $data['usage']['prompt_tokens'] ?? null,
                        'completion_tokens' => $data['usage']['completion_tokens'] ?? null,
                        'total_tokens' => $data['usage']['total_tokens'] ?? null,
                    ];
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
     * $options['messages'] — optional pre-built array of {role, content} objects for multi-turn context.
     * When provided, $prompt is ignored and the messages array is sent directly (with the system prompt prepended).
     *
     * @throws AIServiceException
     */
    public function queryStream(string $prompt, array $options = []): \Generator
    {
        // Only require API key for OpenAI mode, not for Ollama
        if ($this->mode !== 'ollama' && empty($this->apiKey)) {
            throw new AIServiceException('AI API key is not configured.');
        }

        $systemPrompt = $options['system_prompt'] ?? $this->systemPrompt;

        // Build the messages array — either from multi-turn history or a single prompt
        $conversationMessages = $options['messages'] ?? null;
        if (!is_array($conversationMessages) || count($conversationMessages) === 0) {
            $conversationMessages = [['role' => 'user', 'content' => $prompt]];
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
                // OpenAI new API format — prepend system as first message in input array
                $inputMessages = array_merge(
                    [[
                        'role' => 'system',
                        'content' => [['type' => 'input_text', 'text' => $systemPrompt]],
                    ]],
                    array_map(fn ($m) => [
                        'role' => $m['role'],
                        'content' => [['type' => 'input_text', 'text' => $m['content']]],
                    ], $conversationMessages)
                );

                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'input' => $inputMessages,
                    'max_output_tokens' => $options['max_tokens'] ?? (int) Setting::get('settings::modules:ai:max_tokens', config('modules.ai.max_tokens', 500)),
                ];
            } else {
                // Ollama / OpenAI-compatible format — prepend system message
                $maxTokens = $options['max_tokens'] ?? (int) Setting::get('settings::modules:ai:max_tokens', config('modules.ai.max_tokens', 500));
                $payload = [
                    'model' => $options['model'] ?? $this->model,
                    'messages' => array_merge(
                        [['role' => 'system', 'content' => $systemPrompt]],
                        $conversationMessages
                    ),
                    'max_tokens' => $maxTokens,
                    'temperature' => $options['temperature'] ?? $this->temperature,
                    'stream' => true,
                    'options' => [
                        // num_ctx: context window. 2048 is enough for our compact prompts
                        // (~500 input tokens + up to 500 output). Smaller = faster model load
                        // and scheduling on Ollama; 4096 only needed for multi-turn history.
                        'num_ctx' => count($conversationMessages) > 2 ? 4096 : 2048,
                        // num_predict: tell Ollama exactly how many tokens to generate.
                        // Without this Ollama may use -1 (unlimited) or a model default,
                        // leading to runaway generation and inflated latency.
                        'num_predict' => $maxTokens,
                    ],
                ];
            }

            $endpoint = $this->mode === 'openai' ? 'responses' : 'chat/completions';

            // Ollama is single-threaded — serialise concurrent requests with a cache lock
            // to prevent garbled output when multiple users query simultaneously.
            // We wrap only the actual HTTP call, not the streaming read, so the lock is
            // released once the request is established and streaming begins.
            $lock = $this->mode === 'ollama'
                ? Cache::lock('ai_ollama_stream_lock', 90)
                : null;

            if ($lock && !$lock->block(30)) {
                throw new AIServiceException('AI is currently busy. Please try again in a moment.');
            }

            try {
                // 'stream' => true tells Guzzle to NOT buffer the response body.
                // Without this, Guzzle waits for the full Ollama reply before returning,
                // causing the frontend to spin until the model finishes generating.
                $response = $this->client->post($endpoint, [
                    'stream' => true,
                    'headers' => $headers,
                    'json' => $payload,
                ]);
            } finally {
                // Release the lock immediately once the HTTP connection is established —
                // the streaming read happens outside the lock so others aren't blocked during output.
                $lock?->release();
            }

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
