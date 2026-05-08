<?php

return [
    /*
     * Enable or disable the AI module.
     */
    'enabled' => env('AI_ENABLED', false),

    /*
     * Set the API key for OpenAI or any OpenAI-compatible API.
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
    'model' => env('AI_MODEL', 'gpt-4.1-mini'),

    /*
     * Maximum tokens for AI responses
     */
    'max_tokens' => env('AI_MAX_TOKENS', 500),

    /*
     * Temperature for AI responses (0.0 = deterministic, 1.0 = creative)
     */
    'temperature' => env('AI_TEMPERATURE', 0.3),

    /*
     * System prompt for AI
     */
    'system_prompt' => env('AI_SYSTEM_PROMPT', 'You are an expert game server technician specializing in crash analysis and debugging. When given server logs, identify the root cause concisely and list specific actionable steps to resolve it. Format responses as: Cause: [what went wrong]. Fix: [numbered steps]. For general questions, give direct technical answers. Be concise.'),

    /*
     * Individual feature toggles.
     * These allow disabling specific AI components without disabling AI entirely.
     */
    'feature_server_assistant' => env('AI_FEATURE_SERVER_ASSISTANT', true),
    'feature_crash_analysis' => env('AI_FEATURE_CRASH_ANALYSIS', true),
];
