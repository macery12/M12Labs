<?php

namespace Everest\Http\Requests\Api\Client\Tickets;

use Everest\Models\TicketMessage;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class StoreTicketMessageRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'message' => TicketMessage::$validationRules['message'],
        ];
    }
}
