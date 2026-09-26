<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Models\CustomLink;
use Everest\Transformers\Api\Client\LinkTransformer;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class LinkController extends ClientApiController
{
    /**
     * Returns every visible link in operator order. Placement is filtered on
     * the client so the dashboard and server sidebars share one cached query.
     */
    public function index(ClientApiRequest $request): array
    {
        $links = CustomLink::query()->where('visible', true)->ordered()->get();

        return $this->fractal->collection($links)
            ->transformWith(LinkTransformer::class)
            ->toArray();
    }
}
