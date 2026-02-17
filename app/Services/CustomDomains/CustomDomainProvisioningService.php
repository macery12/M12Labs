<?php

namespace Everest\Services\CustomDomains;

use Exception;
use Everest\Models\Order;
use Everest\Models\Server;
use Everest\Models\Setting;
use Everest\Models\Allocation;
use Everest\Models\CustomDomain;
use Everest\Models\ServerCustomDomain;
use Everest\Models\CustomDomainDnsLog;
use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\DB;

class CustomDomainProvisioningService
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
        private SslProvisioningService $sslProvisioning,
    ) {
    }

    public function getAvailableDomains(): array
    {
        return CustomDomain::query()->where('enabled', true)->orderBy('domain')->get()->all();
    }

    public function createFromPayload(Server $server, array $payload): void
    {
        if (empty($payload)) {
            return;
        }

        DB::transaction(function () use ($server, $payload) {
            foreach ($payload as $entry) {
                $domainId = (int) ($entry['domain_id'] ?? 0);
                $subdomain = strtolower(trim((string) ($entry['subdomain'] ?? '')));
                $port = (int) ($entry['port'] ?? $server->allocation->port);
                $protocol = 'both';
                $sslEnabled = (bool) ($entry['ssl_enabled'] ?? false);

                $domain = CustomDomain::query()->where('enabled', true)->findOrFail($domainId);
                $this->validateSubdomain($subdomain, $domain);

                $allocation = $server->allocations()->where('port', $port)->first();
                $fullDomain = $subdomain . '.' . $domain->domain;

                $existing = ServerCustomDomain::query()
                    ->where('full_domain', $fullDomain)
                    ->where('port', $port)
                    ->where('protocol', $protocol)
                    ->first();

                if ($existing && $existing->server_id !== $server->id) {
                    throw new DisplayException('The selected domain and port mapping is already in use by another server.');
                }

                ServerCustomDomain::query()->updateOrCreate(
                    [
                        'full_domain' => $fullDomain,
                        'port' => $port,
                        'protocol' => $protocol,
                    ],
                    [
                        'server_id' => $server->id,
                        'allocation_id' => $allocation?->id,
                        'custom_domain_id' => $domain->id,
                        'subdomain' => $subdomain,
                        'ssl_enabled' => $sslEnabled,
                        'ssl_status' => $sslEnabled ? 'pending' : 'disabled',
                        'status' => 'pending',
                        'last_error' => null,
                    ]
                );
            }
        });
    }

    public function syncFromOrder(Server $server, ?Order $order): void
    {
        if (!$order || !is_array($order->domain_payload)) {
            return;
        }

        $this->createFromPayload($server, $order->domain_payload);
    }

    public function provision(ServerCustomDomain $mapping): void
    {
        try {
            $mapping->loadMissing(['customDomain', 'server.node', 'allocation']);

            $zoneId = $mapping->customDomain->cloudflare_zone_id;
            if (empty($zoneId)) {
                $zone = $this->cloudflare->getZoneByName($mapping->customDomain->domain);
                if (!$zone) {
                    throw new Exception('Cloudflare zone could not be resolved for domain: ' . $mapping->customDomain->domain);
                }

                $zoneId = $zone['id'];
                $mapping->customDomain->forceFill(['cloudflare_zone_id' => $zoneId])->save();
            }

            $target = $this->resolveTarget($mapping);
            $records = [];

            $hostRecord = $this->cloudflare->createOrUpdateAOrCnameRecord($zoneId, $mapping->full_domain, $target);
            $records[] = [
                'kind' => 'host',
                'id' => $hostRecord['id'] ?? null,
                'type' => $hostRecord['type'] ?? null,
            ];

            $service = Setting::get('settings::modules:custom_domains:service', 'minecraft');
            $protocols = ['tcp', 'udp'];

            if ($mapping->subdomain !== '*') {
                $srvTarget = $this->resolveSrvTargetHostname($mapping);

                foreach ($protocols as $proto) {
                    $srvRecord = $this->cloudflare->createOrUpdateSrvRecord(
                        $zoneId,
                        $mapping->full_domain,
                        $service,
                        $proto,
                        $mapping->port,
                        $srvTarget
                    );

                    $records[] = [
                        'kind' => 'srv',
                        'id' => $srvRecord['id'] ?? null,
                        'type' => 'SRV',
                        'proto' => $proto,
                    ];
                }
            }

            $sslStatus = $mapping->ssl_enabled ? 'pending' : 'disabled';
            if ($mapping->ssl_enabled) {
                try {
                    $this->sslProvisioning->requestCertificate($mapping->full_domain);
                    $sslStatus = 'issued';
                } catch (\Throwable $exception) {
                    $sslStatus = 'failed';
                    $this->writeLog($mapping, 'ssl', 'failed', ['error' => $exception->getMessage()], $exception->getMessage());
                }
            }

            $mapping->forceFill([
                'dns_records' => $records,
                'status' => 'active',
                'ssl_status' => $sslStatus,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            $this->writeLog($mapping, 'sync', 'success', ['records' => $records], 'DNS provisioned successfully.');
        } catch (\Throwable $exception) {
            $mapping->forceFill([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'last_synced_at' => now(),
            ])->save();

            $this->writeLog($mapping, 'sync', 'failed', ['error' => $exception->getMessage()], $exception->getMessage());
        }
    }

    public function cleanup(ServerCustomDomain $mapping): void
    {
        $mapping->loadMissing('customDomain');
        $zoneId = $mapping->customDomain->cloudflare_zone_id;

        if (!$zoneId) {
            return;
        }

        $records = $mapping->dns_records ?? [];
        foreach ($records as $record) {
            $recordId = $record['id'] ?? null;
            if (!$recordId) {
                continue;
            }

            try {
                $this->cloudflare->deleteRecord($zoneId, $recordId);
            } catch (\Throwable $exception) {
                $this->writeLog($mapping, 'delete', 'failed', ['record_id' => $recordId], $exception->getMessage());
            }
        }

        $this->writeLog($mapping, 'delete', 'success', ['records' => $records], 'DNS records removed.');
    }

    private function validateSubdomain(string $subdomain, CustomDomain $domain): void
    {
        if (!preg_match('/^(\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)$/i', $subdomain)) {
            throw new DisplayException('Invalid subdomain value: ' . $subdomain);
        }

        if ($subdomain === '*' && !$domain->wildcard_enabled) {
            throw new DisplayException('Wildcard subdomains are disabled for ' . $domain->domain);
        }
    }

    private function resolveTarget(ServerCustomDomain $mapping): string
    {
        if ($mapping->allocation && filter_var($mapping->allocation->ip, FILTER_VALIDATE_IP)) {
            return $mapping->allocation->ip;
        }

        if ($mapping->server->allocation && filter_var($mapping->server->allocation->ip, FILTER_VALIDATE_IP)) {
            return $mapping->server->allocation->ip;
        }

        return (string) $mapping->server->node->fqdn;
    }

    private function resolveSrvTargetHostname(ServerCustomDomain $mapping): string
    {
        $raw = trim((string) $mapping->server->node->fqdn);

        if ($raw === '') {
            throw new DisplayException('Node hostname is not configured.');
        }

        $hostname = $raw;

        if (str_contains($hostname, '://')) {
            $parsed = parse_url($hostname, PHP_URL_HOST);
            $hostname = is_string($parsed) ? $parsed : '';
        } else {
            $hostname = preg_split('/[\/\?#]/', $hostname, 2)[0] ?? '';

            if (str_starts_with($hostname, '[') && str_contains($hostname, ']')) {
                $hostname = trim(explode(']', $hostname, 2)[0], '[]');
            } elseif (preg_match('/:[0-9]+$/', $hostname) === 1 && substr_count($hostname, ':') === 1) {
                $hostname = substr($hostname, 0, (int) strrpos($hostname, ':'));
            }
        }

        $hostname = strtolower(rtrim(trim($hostname), '.'));

        if ($hostname === '') {
            throw new DisplayException('Node hostname is invalid.');
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            throw new DisplayException('Node hostname must be a DNS hostname, not an IP address.');
        }

        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $hostname)) {
            throw new DisplayException('Node hostname is invalid for SRV target.');
        }

        return $hostname;
    }

    private function writeLog(ServerCustomDomain $mapping, string $action, string $status, array $payload = [], ?string $message = null): void
    {
        CustomDomainDnsLog::query()->create([
            'server_id' => $mapping->server_id,
            'server_custom_domain_id' => $mapping->id,
            'action' => $action,
            'status' => $status,
            'payload' => $payload,
            'message' => $message,
        ]);
    }
}
