<?php

return [
    /*
     * Enable or disable the AI module.
     */
    'enabled' => env('AI_ENABLED', false),

    /*
     * Set the API key for OpenAI-compatible API.
     */
    'key' => env('AI_KEY', ''),

    /*
     * Should clients/users be allowed
     * to use AI features?
     */
    'user_access' => env('AI_USER_ACCESS', false),

    /*
     * AI mode: 'openai' or 'ollama'
     */
    'mode' => env('AI_MODE', 'openai'),

    /*
     * API endpoint URL
     */
    'endpoint' => env('AI_ENDPOINT', 'https://api.openai.com/v1'),

    /*
     * AI model to use
     */
    'model' => env('AI_MODEL', 'gpt-3.5-turbo'),

    /*
     * Maximum tokens for AI responses
     */
    'max_tokens' => env('AI_MAX_TOKENS', 200),

    /*
     * System prompt for AI
     */
    'system_prompt' => env('AI_SYSTEM_PROMPT', 'You are a helpful assistant for a game server hosting panel. Provide clear, concise, and technical responses.'),
];
