<?php

namespace Everest\Tests\Integration\Console;

use Everest\Models\Node;
use Everest\Tests\Integration\IntegrationTestCase;

class MakeNodeCommandTest extends IntegrationTestCase
{
    /**
     * The ports and base folder were written under the upstream column names
     * (daemonListen, daemonSFTP, daemonBase), which this schema renamed, so
     * every run failed with "Unknown column" on insert.
     */
    public function testTheCommandCreatesANodeWithItsPortsAndBaseFolder(): void
    {
        $this->artisan('p:node:make', [
            '--name' => 'cli-node',
            '--description' => 'Created from the CLI',
            '--fqdn' => 'node.example.com',
            '--public' => '1',
            '--scheme' => 'https',
            '--proxy' => '0',
            '--maintenance' => '0',
            '--maxMemory' => '2048',
            '--overallocateMemory' => '0',
            '--maxDisk' => '4096',
            '--overallocateDisk' => '0',
            '--uploadSize' => '100',
            '--daemonListeningPort' => '9443',
            '--daemonSFTPPort' => '2222',
            '--daemonBase' => '/srv/volumes',
        ])->assertSuccessful();

        $node = Node::query()->where('name', 'cli-node')->firstOrFail();

        $this->assertSame(9443, $node->listen_port_http);
        $this->assertSame(9443, $node->public_port_http);
        $this->assertSame(2222, $node->listen_port_sftp);
        $this->assertSame(2222, $node->public_port_sftp);
        $this->assertSame('/srv/volumes', $node->daemon_base);
    }
}
