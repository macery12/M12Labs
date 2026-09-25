<?php

namespace Everest\Tests\Integration\Api\Client\Server\Subuser;

use Everest\Models\User;
use Everest\Models\Subuser;
use Illuminate\Support\Str;
use Illuminate\Http\Response;
use Everest\Models\Permission;
use Illuminate\Foundation\Testing\WithFaker;
use PHPUnit\Framework\Attributes\DataProvider;
use Everest\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class CreateServerSubuserTest extends ClientApiIntegrationTestCase
{
    use WithFaker;

    /**
     * Test that an existing account can be added as a subuser for a server.
     */
    #[DataProvider('permissionsDataProvider')]
    public function testSubuserCanBeCreated(array $permissions)
    {
        [$user, $server] = $this->generateTestAccount($permissions);
        $email = User::factory()->create(['email' => $this->faker->email])->email;

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertOk();

        /** @var User $subuser */
        $subuser = User::query()->where('email', $email)->firstOrFail();

        $response->assertJsonPath('object', Subuser::RESOURCE_NAME);
        $response->assertJsonPath('attributes.uuid', $subuser->uuid);
        $response->assertJsonPath('attributes.permissions', [
            Permission::ACTION_USER_CREATE,
            Permission::ACTION_WEBSOCKET_CONNECT,
        ]);

        $expected = $response->json('attributes');
        unset($expected['permissions']);

        $this->assertJsonTransformedWith($expected, $subuser);
    }

    /**
     * Tests that an error is returned if a subuser attempts to create a new subuser and assign
     * permissions that their account does not also possess.
     */
    public function testErrorIsReturnedIfAssigningPermissionsNotAssignedToSelf()
    {
        [$user, $server] = $this->generateTestAccount([
            Permission::ACTION_USER_CREATE,
            Permission::ACTION_USER_READ,
            Permission::ACTION_CONTROL_CONSOLE,
        ]);

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $this->faker->email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
                Permission::ACTION_USER_UPDATE, // This permission is not assigned to the subuser.
            ],
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('errors.0.code', 'HttpForbiddenException');
        $response->assertJsonPath('errors.0.detail', 'Cannot assign permissions to a subuser that your account does not actively possess.');
    }

    /**
     * Throws some bad data at the API and ensures that a subuser cannot be created.
     */
    public function testSubuserWithExcessivelyLongEmailCannotBeCreated()
    {
        [$user, $server] = $this->generateTestAccount();

        // 191 is the hard limit for the column in MySQL. The local part stays within
        // RFC 5321's 64 characters so the service still treats it as an email.
        $email = Str::lower(Str::random(64)) . '@' . Str::lower(Str::random(61)) . '.' . Str::lower(Str::random(60)) . '.com';
        User::factory()->create(['email' => $email]);

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertOk();

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email . '.au',
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('errors.0.detail', 'The email must be between 1 and 191 characters.');
        $response->assertJsonPath('errors.0.meta.source_field', 'email');
    }

    /**
     * Test that creating a subuser when there is already an account with that email runs
     * as expected and does not create a new account.
     */
    public function testCreatingSubuserWithSameEmailAsExistingUserWorks()
    {
        [$user, $server] = $this->generateTestAccount();

        /** @var User $existing */
        $existing = User::factory()->create(['email' => $this->faker->email]);

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $existing->email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('object', Subuser::RESOURCE_NAME);
        $response->assertJsonPath('attributes.uuid', $existing->uuid);
    }

    /**
     * Test that an error is returned if the account associated with an email address is already
     * associated with the server instance.
     */
    public function testAddingSubuserThatAlreadyIsAssignedReturnsError()
    {
        [$user, $server] = $this->generateTestAccount();
        $email = User::factory()->create(['email' => $this->faker->email])->email;

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertOk();

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email,
            'permissions' => [
                Permission::ACTION_USER_CREATE,
            ],
        ]);

        $response->assertStatus(Response::HTTP_BAD_REQUEST);
        $response->assertJsonPath('errors.0.code', 'ServerSubuserExistsException');
        $response->assertJsonPath('errors.0.detail', 'A user with that email address is already assigned as a subuser for this server.');
    }

    public static function permissionsDataProvider(): array
    {
        return [[[]], [[Permission::ACTION_USER_CREATE]]];
    }
}
