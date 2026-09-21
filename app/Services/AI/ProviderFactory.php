<?php

namespace Everest\Services\AI;

use Everest\Services\AI\Data\ProviderConfig;
use Everest\Services\AI\Contracts\AiProvider;
use Everest\Services\AI\Providers\OllamaProvider;
use Everest\Services\AI\Providers\AnthropicProvider;
use Everest\Exceptions\Service\AI\AIServiceException;
use Everest\Services\AI\Providers\OpenRouterProvider;
use Everest\Services\AI\Providers\OpenAiCompatibleProvider;

/**
 * Resolves the configured provider driver.
 *
 * Drivers receive a fully resolved ProviderConfig so they never touch the
 * database or {@see AiConfiguration}, which keeps them trivially testable
 * against a fixed configuration.
 */
class ProviderFactory
{
    /**
     * Sensible endpoint per provider when the admin has not set one.
     */
    public const DEFAULT_ENDPOINTS = [
        ProviderConfig::PROVIDER_ANTHROPIC => 'https://api.anthropic.com/v1',
        ProviderConfig::PROVIDER_OPENAI => 'https://api.openai.com/v1',
        ProviderConfig::PROVIDER_OPENROUTER => OpenRouterProvider::ENDPOINT,
        ProviderConfig::PROVIDER_OLLAMA => 'http://127.0.0.1:11434/v1',
        ProviderConfig::PROVIDER_OPENAI_COMPATIBLE => '',
    ];

    /**
     * Build the configured provider, optionally bounded by a caller's remaining
     * wall-clock allowance.
     *
     * @throws AIServiceException
     */
    public function make(?int $timeoutSeconds = null): AiProvider
    {
        $config = $this->config();

        return $this->fromConfig(
            $timeoutSeconds === null ? $config : $config->withTimeout($timeoutSeconds)
        );
    }

    /**
     * @throws AIServiceException
     */
    public function fromConfig(ProviderConfig $config): AiProvider
    {
        if ($config->provider === ProviderConfig::PROVIDER_OPENROUTER) {
            $config = $this->canonicalOpenRouterConfig($config);
        }

        return match ($config->provider) {
            ProviderConfig::PROVIDER_ANTHROPIC => new AnthropicProvider($config),
            ProviderConfig::PROVIDER_OPENROUTER => new OpenRouterProvider($config),
            ProviderConfig::PROVIDER_OLLAMA => new OllamaProvider($config),
            ProviderConfig::PROVIDER_OPENAI,
            ProviderConfig::PROVIDER_OPENAI_COMPATIBLE => new OpenAiCompatibleProvider($config),
            default => throw new AIServiceException('Unsupported AI provider: ' . $config->provider),
        };
    }

    /** Resolve the effective connection and provider settings. */
    public function config(): ProviderConfig
    {
        $provider = $this->provider();

        $endpoint = AiConfiguration::string('endpoint');
        if ($provider === ProviderConfig::PROVIDER_OPENROUTER) {
            $endpoint = OpenRouterProvider::ENDPOINT;
        } elseif ($endpoint === '') {
            $endpoint = self::DEFAULT_ENDPOINTS[$provider] ?? '';
        }

        $contextTokens = AiConfiguration::integer('context_tokens');

        return new ProviderConfig(
            provider: $provider,
            endpoint: $endpoint,
            apiKey: AiConfiguration::secret('key'),
            model: $this->model(),
            maxTokens: AiConfiguration::integer('max_tokens', 1024),
            temperature: AiConfiguration::number('temperature', 0.3),
            systemPrompt: $this->systemPrompt(),
            keepAlive: AiConfiguration::string('keep_alive', '10m') ?: '10m',
            timeout: AiConfiguration::integer('timeout', 300),
            connectTimeout: AiConfiguration::integer('connect_timeout', 10),
            contextTokens: $contextTokens ?: null,
        );
    }

    /**
     * The configured provider key.
     *
     * Falls back to the legacy `mode` setting so installs that predate the
     * multi-provider rework keep working without an explicit migration step —
     * both of its values (`openai`, `ollama`) are still valid provider keys.
     */
    public function provider(): string
    {
        $provider = AiConfiguration::string('provider');

        if ($provider === '') {
            $provider = AiConfiguration::string('mode', 'ollama') ?: 'ollama';
        }

        return in_array($provider, ProviderConfig::PROVIDERS, true)
            ? $provider
            : ProviderConfig::PROVIDER_OLLAMA;
    }

    /**
     * The single model used by every AI surface.
     */
    public function model(): string
    {
        if ($this->provider() === ProviderConfig::PROVIDER_OPENROUTER) {
            return OpenRouterProvider::MODEL;
        }

        return AiConfiguration::string('model');
    }

    /** Database, environment and direct factory callers cannot redirect OpenRouter. */
    private function canonicalOpenRouterConfig(ProviderConfig $config): ProviderConfig
    {
        return new ProviderConfig(
            provider: ProviderConfig::PROVIDER_OPENROUTER,
            endpoint: OpenRouterProvider::ENDPOINT,
            apiKey: $config->apiKey,
            model: OpenRouterProvider::MODEL,
            maxTokens: $config->maxTokens,
            temperature: $config->temperature,
            systemPrompt: $config->systemPrompt,
            keepAlive: $config->keepAlive,
            timeout: $config->timeout,
            connectTimeout: $config->connectTimeout,
            contextTokens: $config->contextTokens,
        );
    }

    /**
     * The house system prompt.
     *
     * A blank stored value falls back to the packaged default rather than to no
     * prompt at all: an admin who clears the field is asking for the default
     * back, and a model given no framing at all answers as a generic chatbot
     * with no idea it is inside a game server panel.
     */
    public function systemPrompt(): string
    {
        $prompt = trim(AiConfiguration::string('system_prompt'));

        return $prompt !== ''
            ? $prompt
            : trim(AiConfiguration::string('default_system_prompt') ?: AiConfiguration::string('system_prompt'));
    }
}
