<?php

namespace Everest\Tests\Unit\Services\AI;

use Mockery as m;
use GuzzleHttp\Client;
use Everest\Tests\TestCase;
use GuzzleHttp\Psr7\Response;
use Everest\Services\AI\OpenAIService;
use Everest\Exceptions\Service\AI\AIServiceException;

class OpenAIServiceTest extends TestCase
{
    /**
     * Test that OpenAI mode uses the new API endpoint format.
     */
    public function testOpenAIModeUsesNewEndpoint()
    {
        config()->set('modules.ai.mode', 'openai');
        config()->set('modules.ai.key', 'test-key');
        config()->set('modules.ai.model', 'gpt-4.1-mini');

        $response = new Response(200, [], json_encode([
            'output_text' => 'Hello! This is a test response.',
        ]));

        $client = m::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('responses', m::on(function ($args) {
                // Verify the request payload uses the new format
                $payload = $args['json'];

                // Check endpoint is 'responses' (implicit in the with() call)
                // Check new request structure
                $this->assertArrayHasKey('input', $payload);
                $this->assertArrayNotHasKey('messages', $payload);
                $this->assertArrayHasKey('max_output_tokens', $payload);
                $this->assertArrayNotHasKey('max_tokens', $payload);
                $this->assertArrayNotHasKey('stream', $payload);
                $this->assertArrayNotHasKey('temperature', $payload);

                // Verify input structure
                $this->assertIsArray($payload['input']);
                $this->assertEquals('system', $payload['input'][0]['role']);
                $this->assertArrayHasKey('content', $payload['input'][0]);
                $this->assertIsArray($payload['input'][0]['content']);
                $this->assertEquals('input_text', $payload['input'][0]['content'][0]['type']);

                return true;
            }))
            ->andReturn($response);

        $service = new OpenAIService();
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $client);

        $result = $service->query('Test prompt');
        $this->assertEquals('Hello! This is a test response.', $result);
    }

    /**
     * Test that Ollama mode still uses the old API endpoint format.
     */
    public function testOllamaModeUsesOldEndpoint()
    {
        config()->set('modules.ai.mode', 'ollama');
        config()->set('modules.ai.key', '');
        config()->set('modules.ai.model', 'llama2');

        $response = new Response(200, [], json_encode([
            'choices' => [
                [
                    'message' => [
                        'content' => 'Hello from Ollama!',
                    ],
                ],
            ],
        ]));

        $client = m::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('chat/completions', m::on(function ($args) {
                // Verify the request payload uses the old format
                $payload = $args['json'];

                // Check endpoint is 'chat/completions' (implicit in the with() call)
                // Check old request structure
                $this->assertArrayHasKey('messages', $payload);
                $this->assertArrayNotHasKey('input', $payload);
                $this->assertArrayHasKey('max_tokens', $payload);
                $this->assertArrayNotHasKey('max_output_tokens', $payload);
                $this->assertArrayHasKey('stream', $payload);
                $this->assertArrayHasKey('temperature', $payload);

                return true;
            }))
            ->andReturn($response);

        $service = new OpenAIService();
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $client);

        $result = $service->query('Test prompt');
        $this->assertEquals('Hello from Ollama!', $result);
    }

    /**
     * Test that OpenAI mode parses the new response format.
     */
    public function testOpenAIModeResponseParsing()
    {
        config()->set('modules.ai.mode', 'openai');
        config()->set('modules.ai.key', 'test-key');

        $response = new Response(200, [], json_encode([
            'output_text' => 'New API response format',
        ]));

        $client = m::mock(Client::class);
        $client->shouldReceive('post')->once()->andReturn($response);

        $service = new OpenAIService();
        $reflection = new \ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $client);

        $result = $service->query('Test');
        $this->assertEquals('New API response format', $result);
    }

    /**
     * Test that missing API key throws exception in OpenAI mode.
     */
    public function testMissingApiKeyThrowsException()
    {
        config()->set('modules.ai.mode', 'openai');
        config()->set('modules.ai.key', '');

        $service = new OpenAIService();

        $this->expectException(AIServiceException::class);
        $this->expectExceptionMessage('AI API key is not configured.');

        $service->query('Test');
    }

    /**
     * Test that the default model is updated to gpt-4.1-mini.
     */
    public function testDefaultModelIsUpdated()
    {
        config()->set('modules.ai.model', null);

        $service = new OpenAIService();
        $reflection = new \ReflectionClass($service);
        $modelProperty = $reflection->getProperty('model');
        $modelProperty->setAccessible(true);

        $this->assertEquals('gpt-4.1-mini', $modelProperty->getValue($service));
    }
}
