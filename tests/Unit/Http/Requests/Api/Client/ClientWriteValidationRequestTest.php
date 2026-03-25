<?php

namespace Everest\Tests\Unit\Http\Requests\Api\Client;

use Everest\Tests\TestCase;
use Illuminate\Support\Facades\Validator;
use Everest\Http\Requests\Api\Client\Tickets\StoreTicketRequest;
use Everest\Http\Requests\Api\Client\Tickets\StoreTicketMessageRequest;
use Everest\Http\Requests\Api\Client\ServerGroups\StoreServerGroupRequest;
use Everest\Http\Requests\Api\Client\ServerGroups\UpdateServerGroupRequest;
use Everest\Http\Requests\Api\Client\ServerGroups\AssignServerToGroupRequest;
use Everest\Http\Requests\Api\Client\ServerGroups\RemoveServerFromGroupRequest;

class ClientWriteValidationRequestTest extends TestCase
{
    public function testStoreServerGroupRequestValidatesNameAndColor(): void
    {
        $request = new StoreServerGroupRequest();

        $this->assertFalse(Validator::make([
            'name' => 'ab',
            'color' => 'blue',
        ], $request->rules())->passes());

        $this->assertTrue(Validator::make([
            'name' => 'Favorites',
            'color' => '#A1B2C3',
        ], $request->rules())->passes());
    }

    public function testUpdateServerGroupRequestAllowsPartialValidatedUpdates(): void
    {
        $request = new UpdateServerGroupRequest();

        $this->assertFalse(Validator::make([
            'color' => 'invalid',
        ], $request->rules())->passes());

        $this->assertTrue(Validator::make([
            'name' => 'Renamed Group',
        ], $request->rules())->passes());
    }

    public function testServerGroupAssignmentRequestsRequireServerIdentifier(): void
    {
        $this->assertFalse(Validator::make([], (new AssignServerToGroupRequest())->rules())->passes());
        $this->assertFalse(Validator::make([], (new RemoveServerFromGroupRequest())->rules())->passes());
        $this->assertTrue(Validator::make(['server' => 'server-uuid'], (new AssignServerToGroupRequest())->rules())->passes());
        $this->assertTrue(Validator::make(['server' => 'server-uuid'], (new RemoveServerFromGroupRequest())->rules())->passes());
    }

    public function testStoreTicketRequestValidatesTitleAndMessage(): void
    {
        $request = new StoreTicketRequest();

        $this->assertFalse(Validator::make([
            'title' => 'Hi',
            'message' => 'No',
        ], $request->rules())->passes());

        $this->assertTrue(Validator::make([
            'title' => 'Billing issue',
            'message' => 'I need help with a recent order.',
        ], $request->rules())->passes());
    }

    public function testStoreTicketMessageRequestValidatesMessageLength(): void
    {
        $request = new StoreTicketMessageRequest();

        $this->assertFalse(Validator::make([
            'message' => 'No',
        ], $request->rules())->passes());

        $this->assertTrue(Validator::make([
            'message' => 'Here is the additional information you requested.',
        ], $request->rules())->passes());
    }
}
