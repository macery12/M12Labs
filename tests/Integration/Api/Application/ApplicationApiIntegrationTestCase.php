<?php

namespace Everest\Tests\Integration\Api\Application;

use Everest\Models\User;
use Everest\Models\ApiKey;
use Everest\Models\AdminRole;
use Illuminate\Testing\TestResponse;
use Everest\Services\Acl\Api\AdminAcl;
use Everest\Tests\Integration\IntegrationTestCase;
use Everest\Tests\Traits\Integration\CreatesTestModels;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Everest\Services\Authorization\AdminCapabilityRegistry;
use Everest\Tests\Traits\Http\IntegrationJsonRequestAssertions;

abstract class ApplicationApiIntegrationTestCase extends IntegrationTestCase
{
    use CreatesTestModels;
    use DatabaseTransactions;
    use IntegrationJsonRequestAssertions;

    private ApiKey $key;

    private User $user;

    /**
     * Bootstrap application API tests. Creates a default admin user and associated API key
     * and also sets some default headers required for accessing the API.
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createApiUser();
        $this->key = $this->createApiKey($this->user);

        $this
            ->withHeader('Accept', 'application/vnd.pterodactyl.v1+json')
            ->withHeader('Authorization', 'Bearer ' . $this->key->identifier . decrypt($this->key->token));
    }

    public function getApiUser(): User
    {
        return $this->user;
    }

    public function getApiKey(): ApiKey
    {
        return $this->key;
    }

    /**
     * Creates a new default API key and refreshes the headers using it.
     */
    protected function createNewDefaultApiKey(User $user, array $permissions = []): ApiKey
    {
        $this->key = $this->createApiKey($user, $permissions);

        $this->withHeader('Authorization', 'Bearer ' . $this->key->identifier . decrypt($this->key->token));

        return $this->key;
    }

    /**
     * Replace the default API key with one bound to an Access Profile holding
     * only the given capabilities. A key takes its authority solely from its
     * profile, never from the user who created it, so this is how a narrowly
     * scoped key is expressed.
     *
     * @param list<string> $capabilities
     */
    protected function createNewScopedApiKey(array $capabilities): ApiKey
    {
        return $this->createNewDefaultApiKey($this->getApiUser(), [
            'admin_role_id' => $this->createApiProfile($capabilities)->id,
        ]);
    }

    /**
     * Assert the key was refused by its Access Profile, rather than by some
     * other 403 path, such as a missing action declaration.
     */
    protected function assertApiKeyDenied(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertJsonPath('errors.0.code', 'AccessDeniedHttpException')
            ->assertJsonPath('errors.0.detail', 'This API key does not have permission to perform this action.');
    }

    /**
     * Create an administrative user.
     */
    protected function createApiUser(): User
    {
        $owner = AdminRole::query()->where('is_owner', true)->firstOrFail();

        return User::factory()->create([
            'admin_role_id' => $owner->id,
            'root_admin' => true,
        ]);
    }

    /**
     * Create a new application API key for a given user model.
     */
    protected function createApiKey(User $user, array $permissions = []): ApiKey
    {
        $profileId = $permissions['admin_role_id'] ?? null;
        unset($permissions['admin_role_id']);

        if ($profileId === null) {
            $profileId = $this->createApiProfile(app(AdminCapabilityRegistry::class)->all())->id;
        }

        return ApiKey::factory()->create(array_merge([
            'user_id' => $user->id,
            'admin_role_id' => $profileId,
            'key_type' => ApiKey::TYPE_APPLICATION,
            'acl_enforced' => true,
            'r_servers' => AdminAcl::READ | AdminAcl::WRITE,
            'r_nodes' => AdminAcl::READ | AdminAcl::WRITE,
            'r_allocations' => AdminAcl::READ | AdminAcl::WRITE,
            'r_users' => AdminAcl::READ | AdminAcl::WRITE,
            'r_locations' => AdminAcl::READ | AdminAcl::WRITE,
            'r_nests' => AdminAcl::READ | AdminAcl::WRITE,
            'r_eggs' => AdminAcl::READ | AdminAcl::WRITE,
            'r_database_hosts' => AdminAcl::READ | AdminAcl::WRITE,
            'r_server_databases' => AdminAcl::READ | AdminAcl::WRITE,
        ], $permissions));
    }

    /**
     * @param list<string> $capabilities
     */
    private function createApiProfile(array $capabilities): AdminRole
    {
        return AdminRole::query()->forceCreate([
            'name' => 'Integration API ' . bin2hex(random_bytes(6)),
            'description' => 'Application API integration test profile.',
            'sort_id' => 999,
            'permissions' => $capabilities,
            'color' => null,
            'is_system' => false,
            'is_owner' => false,
            'api_eligible' => true,
        ]);
    }
}
