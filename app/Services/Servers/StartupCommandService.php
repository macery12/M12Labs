<?php

namespace Everest\Services\Servers;

use Everest\Models\Server;

class StartupCommandService
{
    /**
     * Generates a startup command for a given server instance.
     */
    public function handle(Server $server, bool $hideAllValues = false): string
    {
        $find = ['{{SERVER_MEMORY}}', '{{SERVER_IP}}', '{{SERVER_PORT}}'];
        $replace = [$server->memory, $server->allocation->ip, $server->allocation->port];

        foreach ($server->variables as $variable) {
            $find[] = '{{' . $variable->env_variable . '}}';
            
            // Use server_value if it's not null and not empty, otherwise use default_value
            $value = ($variable->server_value !== null && $variable->server_value !== '') 
                ? $variable->server_value 
                : $variable->default_value;
            
            $replace[] = ($variable->user_viewable && !$hideAllValues) ? $value : '[hidden]';
        }

        return str_replace($find, $replace, $server->startup);
    }
}
