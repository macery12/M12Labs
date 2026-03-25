<?php

namespace Everest\Http\Requests\Api\Client\Tickets;

use Everest\Models\Ticket;
use Everest\Models\TicketMessage;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class StoreTicketRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'title' => Ticket::$validationRules['title'],
            'message' => TicketMessage::$validationRules['message'],
        ];
    }
}
