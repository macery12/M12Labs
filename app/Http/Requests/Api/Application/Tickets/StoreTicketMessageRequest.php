<?php

namespace Everest\Http\Requests\Api\Application\Tickets;

use Everest\Models\AdminRole;
use Everest\Models\TicketMessage;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class StoreTicketMessageRequest extends ApplicationApiRequest
{
    public function rules(): array
    {
        return array_merge(TicketMessage::rules(), [
            'internal_note' => 'sometimes|boolean',
        ]);
    }

    public function permission(): string
    {
        return AdminRole::TICKETS_MESSAGE;
    }
}
