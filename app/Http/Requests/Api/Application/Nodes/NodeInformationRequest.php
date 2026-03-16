<?php

namespace Everest\Http\Requests\Api\Application\Nodes;

use Everest\Models\AdminRole;

class NodeInformationRequest extends GetNodesRequest
{
    public function permission(): string
    {
        return AdminRole::NODES_READ;
    }
}
