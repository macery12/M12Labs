<?php

namespace Everest\Tests\Integration\Api\Application\Servers;

use Everest\Models\Nest;
use Everest\Models\Egg;
use Illuminate\Http\Response;
use Everest\Models\ServerPreset;
use Everest\Transformers\Api\Application\ServerPresetTransformer;
use Everest\Tests\Integration\Api\Application\ApplicationApiIntegrationTestCase;

class ServerPresetControllerTest extends ApplicationApiIntegrationTestCase
{
    /**
     * Test that a server preset can be created with an empty description.
     */
    public function testCreateServerPresetWithEmptyDescription()
    {
        $nest = Nest::query()->first();
        $egg = Egg::query()->where('nest_id', $nest->id)->first();

        $data = [
            'name' => 'TEST',
            'description' => '',
            'cpu' => 0,
            'memory' => 3000,
            'disk' => 10000,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
        ];

        $response = $this->postJson('/api/application/servers/presets', $data);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonStructure([
            'object',
            'attributes' => ['id', 'uuid', 'name', 'description', 'cpu', 'memory', 'disk', 'nest_id', 'egg_id', 'created_at', 'updated_at'],
        ]);

        $response->assertJson([
            'object' => 'server_preset',
            'attributes' => [
                'name' => 'TEST',
                'description' => '',
                'cpu' => 0,
                'memory' => 3000,
                'disk' => 10000,
                'nest_id' => $nest->id,
                'egg_id' => $egg->id,
            ],
        ]);

        // Clean up
        $presetId = $response->json('attributes.id');
        ServerPreset::destroy($presetId);
    }

    /**
     * Test that a server preset can be created with null description.
     */
    public function testCreateServerPresetWithNullDescription()
    {
        $nest = Nest::query()->first();
        $egg = Egg::query()->where('nest_id', $nest->id)->first();

        $data = [
            'name' => 'TEST_NULL',
            'cpu' => 0,
            'memory' => 3000,
            'disk' => 10000,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
        ];

        $response = $this->postJson('/api/application/servers/presets', $data);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonStructure([
            'object',
            'attributes' => ['id', 'uuid', 'name', 'description', 'cpu', 'memory', 'disk', 'nest_id', 'egg_id', 'created_at', 'updated_at'],
        ]);

        // Clean up
        $presetId = $response->json('attributes.id');
        ServerPreset::destroy($presetId);
    }

    /**
     * Test that a server preset can be created with a valid description.
     */
    public function testCreateServerPresetWithDescription()
    {
        $nest = Nest::query()->first();
        $egg = Egg::query()->where('nest_id', $nest->id)->first();

        $data = [
            'name' => 'TEST_WITH_DESC',
            'description' => 'This is a test description',
            'cpu' => 100,
            'memory' => 2048,
            'disk' => 5000,
            'nest_id' => $nest->id,
            'egg_id' => $egg->id,
        ];

        $response = $this->postJson('/api/application/servers/presets', $data);
        $response->assertStatus(Response::HTTP_CREATED);
        $response->assertJsonStructure([
            'object',
            'attributes' => ['id', 'uuid', 'name', 'description', 'cpu', 'memory', 'disk', 'nest_id', 'egg_id', 'created_at', 'updated_at'],
        ]);

        $response->assertJson([
            'object' => 'server_preset',
            'attributes' => [
                'name' => 'TEST_WITH_DESC',
                'description' => 'This is a test description',
                'cpu' => 100,
                'memory' => 2048,
                'disk' => 5000,
            ],
        ]);

        // Clean up
        $presetId = $response->json('attributes.id');
        ServerPreset::destroy($presetId);
    }

    /**
     * Test that getting all presets works.
     */
    public function testGetAllServerPresets()
    {
        $response = $this->getJson('/api/application/servers/presets');
        $response->assertStatus(Response::HTTP_OK);
        $response->assertJsonStructure([
            'object',
            'data',
            'meta' => ['pagination' => ['total', 'count', 'per_page', 'current_page', 'total_pages']],
        ]);
    }
}
