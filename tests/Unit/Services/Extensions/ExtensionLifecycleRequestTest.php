<?php

namespace Everest\Tests\Unit\Services\Extensions;

use Everest\Tests\TestCase;
use Everest\Models\AdminRole;
use Illuminate\Support\Facades\Validator;
use Everest\Http\Requests\Api\Application\Extensions\BatchUpdateExtensionRequest;
use Everest\Http\Requests\Api\Application\Extensions\BatchInstallExtensionRequest;
use Everest\Http\Requests\Api\Application\Extensions\UpdateExtensionPackageRequest;
use Everest\Http\Requests\Api\Application\Extensions\BatchUninstallExtensionRequest;

class ExtensionLifecycleRequestTest extends TestCase
{
    public function testPackageUpdateEndpointsRequireUpdatePermission(): void
    {
        $this->assertSame(AdminRole::EXTENSIONS_UPDATE, (new UpdateExtensionPackageRequest())->permission());
        $this->assertSame(AdminRole::EXTENSIONS_UPDATE, (new BatchUpdateExtensionRequest())->permission());
    }

    public function testBatchRequestsRejectDuplicateExtensionIds(): void
    {
        $installRules = (new BatchInstallExtensionRequest())->rules();
        $updateRules = (new BatchUpdateExtensionRequest())->rules();
        $uninstallRules = (new BatchUninstallExtensionRequest())->rules();
        $install = Validator::make([
            'extensions' => [
                ['extension_id' => 'demo'],
                ['extension_id' => 'demo'],
            ],
        ], [
            'extensions' => $installRules['extensions'],
            'extensions.*.extension_id' => $installRules['extensions.*.extension_id'],
        ]);

        $update = Validator::make([
            'extensions' => [
                ['extension_id' => 'demo'],
                ['extension_id' => 'demo'],
            ],
        ], [
            'extensions' => $updateRules['extensions'],
            'extensions.*.extension_id' => $updateRules['extensions.*.extension_id'],
        ]);

        $uninstall = Validator::make([
            'extension_ids' => ['demo', 'demo'],
        ], [
            'extension_ids' => $uninstallRules['extension_ids'],
            'extension_ids.*' => $uninstallRules['extension_ids.*'],
        ]);

        $this->assertTrue($install->errors()->has('extensions.1.extension_id'));
        $this->assertTrue($update->errors()->has('extensions.1.extension_id'));
        $this->assertTrue($uninstall->errors()->has('extension_ids.1'));
    }

    public function testBatchRequestsAcceptPerItemConsentFields(): void
    {
        $hash = str_repeat('a', 64);
        $installRules = (new BatchInstallExtensionRequest())->rules();
        $updateRules = (new BatchUpdateExtensionRequest())->rules();

        $this->assertArrayHasKey('extensions.*.approved_capability_hash', $installRules);
        $this->assertArrayHasKey('extensions.*.approved_capability_hash', $updateRules);
        $this->assertArrayHasKey('extensions.*.acknowledge_modified_files', $updateRules);

        $validator = Validator::make([
            'extensions' => [[
                'approved_capability_hash' => $hash,
                'acknowledge_modified_files' => true,
            ]],
        ], [
            'extensions.*.approved_capability_hash' => $updateRules['extensions.*.approved_capability_hash'],
            'extensions.*.acknowledge_modified_files' => $updateRules['extensions.*.acknowledge_modified_files'],
        ]);

        $this->assertFalse($validator->errors()->has('extensions.0.approved_capability_hash'));
        $this->assertFalse($validator->errors()->has('extensions.0.acknowledge_modified_files'));
    }
}
