<?php

namespace Everest\Http\Requests\Api\Client\Servers;

use Everest\Http\Requests\Api\Client\ClientApiRequest;

class GetServerResourcesRequest extends ClientApiRequest
{
    /** A page of the server list is at most 100 servers. */
    public const MAX_SERVERS = 100;

    /**
     * Visibility is decided per server by the controller: an id the caller
     * cannot see is left out of the answer, exactly as it would 404 alone.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|string|max:' . (self::MAX_SERVERS * 37),
        ];
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        $ids = array_filter(array_map('trim', explode(',', (string) $this->query('ids'))), fn (string $id) => $id !== '');

        return array_slice(array_values(array_unique($ids)), 0, self::MAX_SERVERS);
    }
}
