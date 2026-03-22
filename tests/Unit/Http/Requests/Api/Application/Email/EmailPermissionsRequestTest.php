<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Application\Email;

use Everest\Http\Requests\Api\Application\Email\GetDeferredQueueRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailActivityRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailNotificationSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailQuotaInfoRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailTemplateKeysRequest;
use Everest\Http\Requests\Api\Application\Email\GetUserEmailQuotaRequest;
use Everest\Http\Requests\Api\Application\Email\ManageDeferredEmailRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailNotificationSettingRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateUserEmailQuotaRequest;
use Everest\Http\Requests\Api\Application\Email\ViewEmailActivityRequest;
use Everest\Models\AdminRole;
use Everest\Tests\TestCase;

class EmailPermissionsRequestTest extends TestCase
{
    public function testReadRequestsUseEmailReadPermission(): void
    {
        $requests = [
            new GetEmailActivityRequest(),
            new ViewEmailActivityRequest(),
            new GetEmailTemplateKeysRequest(),
            new GetDeferredQueueRequest(),
            new GetEmailNotificationSettingsRequest(),
            new GetEmailQuotaInfoRequest(),
            new GetUserEmailQuotaRequest(),
        ];

        foreach ($requests as $request) {
            $this->assertSame(AdminRole::EMAIL_READ, $request->permission());
        }
    }

    public function testUpdateRequestsUseEmailUpdatePermission(): void
    {
        $this->assertSame(AdminRole::EMAIL_UPDATE, (new UpdateEmailNotificationSettingRequest())->permission());
        $this->assertSame(AdminRole::EMAIL_UPDATE, (new UpdateUserEmailQuotaRequest())->permission());
    }

    public function testDeferredQueueActionsUseEmailSendPermission(): void
    {
        $this->assertSame(AdminRole::EMAIL_SEND, (new ManageDeferredEmailRequest())->permission());
    }
}
