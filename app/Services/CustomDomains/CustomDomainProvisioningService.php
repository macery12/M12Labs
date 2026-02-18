<?php

namespace Everest\Services\CustomDomains;

use Exception;
use Everest\Models\Server;
use Everest\Models\Allocation;
use Everest\Models\CustomDomain;
use Everest\Models\ServerCustomDomain;
use Everest\Models\Billing\Order;
use Everest\Models\CustomDomainDnsLog;
use Everest\Exceptions\DisplayException;
use Illuminate\Support\Facades\DB;

class CustomDomainProvisioningService
{
    private const MINECRAFT_SERVICE_TAG_MAP = [
        'minecraft' => '_minecraft._',
        'velocity' => '_minecraft._',
        'bungeecord' => '_minecraft._',
        'bedrock' => '_minecraft._',
    ];

    private const RUST_HINTS = [
        'rust',
    ];

    private const WEB_INTERFACE_HINTS = [
        'web',
        'nginx',
        'apache',
        'http',
        'dashboard',
        'panel',
    ];

    public function __construct(private CloudflareDnsService $cloudflare)
    {
    }

    public function getAvailableDomains(?Server $server = null): array
    {
        $domains = CustomDomain::query()->where('enabled', true)->orderBy('domain')->get();

        if (!$server) {
            return $domains->all();
        }

        return $domains->filter(fn (CustomDomain $domain) => $this->supportsServer($domain, $server))->values()->all();
    }

    public function createFromPayload(Server $server, array $payload): void
    {
        if (empty($payload)) {
            return;
        }

        $server->loadMissing('allocation');
        $resolvedPort = (int) ($server->allocation?->port ?? 0);
        if ($resolvedPort < 1) {
            throw new DisplayException('Custom domain mappings can only be created after the server allocation is ready.');
        }

        DB::transaction(function () use ($server, $payload, $resolvedPort) {
            foreach ($payload as $entry) {
                $domainId = (int) ($entry['domain_id'] ?? 0);
                $subdomain = strtolower(trim((string) ($entry['subdomain'] ?? '')));
                $port = $resolvedPort;
                $protocol = 'both';
                $requestedRecordType = isset($entry['record_type']) ? strtolower(trim((string) $entry['record_type'])) : null;
                $recordType = $this->resolveRecordTypeForServer($server, $requestedRecordType);
                $serviceTag = isset($entry['service_tag']) ? trim((string) $entry['service_tag']) : null;
                $serviceTag = $this->normalizeServiceTagPrefix($serviceTag);

                if ($recordType !== 'srv') {
                    $serviceTag = null;
                } elseif ($serviceTag === null && $this->getDnsModeForServer($server) === 'rust') {
                    $serviceTag = '_rust._';
                }

                $domain = CustomDomain::query()->where('enabled', true)->findOrFail($domainId);
                if (!$this->supportsServer($domain, $server)) {
                    throw new DisplayException('The selected custom domain is not available for this server type.');
                }

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
                        'record_type' => $recordType,
                        'service_tag' => $serviceTag,
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
            $mapping->loadMissing(['customDomain.apiKey', 'server.node', 'server.egg', 'server.nest', 'allocation']);

            $token = trim((string) ($mapping->customDomain->apiKey?->token ?? ''));
            if ($token === '') {
                throw new DisplayException('No API key is configured for this custom domain.');
            }

            $zoneId = $mapping->customDomain->cloudflare_zone_id;
            if (empty($zoneId)) {
                $zone = $this->cloudflare->getZoneByName($mapping->customDomain->domain, $token);
                if (!$zone) {
                    throw new Exception('Cloudflare zone could not be resolved for domain: ' . $mapping->customDomain->domain);
                }

                $zoneId = $zone['id'];
                $mapping->customDomain->forceFill(['cloudflare_zone_id' => $zoneId])->save();
            }

            $recordType = $this->resolveRecordTypeForServer($mapping->server, $mapping->record_type);
            $useSrv = $recordType === 'srv';

            $target = $useSrv
                ? $this->resolveTarget($mapping)
                : $this->resolveSrvTargetHostname($mapping);
            $records = [];

            $existingRecords = (array) ($mapping->dns_records ?? []);

            $hostRecord = $this->cloudflare->createOrUpdateAOrCnameRecord(
                $zoneId,
                $mapping->full_domain,
                $target,
                $token,
                $useSrv ? null : 'CNAME'
            );
            $records[] = [
                'kind' => 'host',
                'id' => $hostRecord['id'] ?? null,
                'type' => $hostRecord['type'] ?? null,
            ];

            $service = $this->resolveServiceTag($mapping);
            $protocols = ['tcp', 'udp'];

            if ($useSrv && $mapping->subdomain !== '*' && $service !== null) {
                $srvTarget = $this->resolveSrvTargetHostname($mapping);

                foreach ($protocols as $proto) {
                    $srvRecord = $this->cloudflare->createOrUpdateSrvRecord(
                        $zoneId,
                        $mapping->full_domain,
                        $service,
                        $proto,
                        $mapping->port,
                        $srvTarget,
                        $token
                    );

                    $records[] = [
                        'kind' => 'srv',
                        'id' => $srvRecord['id'] ?? null,
                        'type' => 'SRV',
                        'proto' => $proto,
                    ];
                }
            }

            $recordIdsToKeep = array_filter(array_map(fn (array $record) => $record['id'] ?? null, $records));
            foreach ($existingRecords as $existingRecord) {
                $recordId = $existingRecord['id'] ?? null;
                if (!$recordId || in_array($recordId, $recordIdsToKeep, true)) {
                    continue;
                }

                try {
                    $this->cloudflare->deleteRecord($zoneId, (string) $recordId, $token);
                } catch (\Throwable $exception) {
                    $this->writeLog($mapping, 'delete', 'failed', ['record_id' => $recordId], $exception->getMessage());
                }
            }

            $mapping->forceFill([
                'record_type' => $recordType,
                'dns_records' => $records,
                'status' => 'active',
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
        $mapping->loadMissing('customDomain.apiKey');
        $zoneId = $mapping->customDomain->cloudflare_zone_id;
        $token = trim((string) ($mapping->customDomain->apiKey?->token ?? ''));

        if (!$zoneId || $token === '') {
            return;
        }

        $records = $mapping->dns_records ?? [];
        foreach ($records as $record) {
            $recordId = $record['id'] ?? null;
            if (!$recordId) {
                continue;
            }

            try {
                $this->cloudflare->deleteRecord($zoneId, $recordId, $token);
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

    public function resolveSuggestedServiceTag(Server $server, ?CustomDomain $domain = null): ?string
    {
        if (!$this->isSrvSupportedForServer($server)) {
            return null;
        }

        if ($domain) {
            $eggTags = (array) ($domain->egg_service_tags ?? []);
            $eggIdKey = (string) (int) ($server->egg_id ?? 0);

            if ($eggIdKey !== '0' && array_key_exists($eggIdKey, $eggTags) && is_string($eggTags[$eggIdKey])) {
                return $this->normalizeServiceTagPrefix($eggTags[$eggIdKey]);
            }

            if ($domain->service_tag) {
                return $this->normalizeServiceTagPrefix((string) $domain->service_tag);
            }
        }

        $labels = strtolower(trim(($server->egg?->name ?? '') . ' ' . ($server->nest?->name ?? '')));
        if ($labels === '') {
            return null;
        }

        foreach (self::WEB_INTERFACE_HINTS as $hint) {
            if (str_contains($labels, $hint)) {
                return null;
            }
        }

        foreach (self::MINECRAFT_SERVICE_TAG_MAP as $needle => $tag) {
            if (str_contains($labels, $needle)) {
                return $this->normalizeServiceTagPrefix($tag);
            }
        }

        return null;
    }

    public function getDefaultServiceTagForEgg(?string $eggName, ?string $nestName = null): ?string
    {
        $labels = strtolower(trim(($eggName ?? '') . ' ' . ($nestName ?? '')));
        if ($labels === '') {
            return null;
        }

        foreach (self::WEB_INTERFACE_HINTS as $hint) {
            if (str_contains($labels, $hint)) {
                return null;
            }
        }

        foreach (self::MINECRAFT_SERVICE_TAG_MAP as $needle => $tag) {
            if (str_contains($labels, $needle)) {
                return $this->normalizeServiceTagPrefix($tag);
            }
        }

        return null;
    }

    public function getDnsModeForServer(Server $server): string
    {
        return $this->resolveDnsModeFromLabels($this->serverLabels($server));
    }

    public function getDnsModeForEgg(?string $eggName, ?string $nestName = null): string
    {
        $labels = strtolower(trim(($eggName ?? '') . ' ' . ($nestName ?? '')));

        return $this->resolveDnsModeFromLabels($labels);
    }

    public function isSrvSupportedForServer(Server $server): bool
    {
        return in_array($this->getDnsModeForServer($server), ['minecraft', 'rust'], true);
    }

    public function resolveRecordTypeForServer(Server $server, ?string $requestedRecordType = null): string
    {
        $mode = $this->getDnsModeForServer($server);
        $requested = strtolower(trim((string) $requestedRecordType));

        if ($mode === 'minecraft') {
            return $requested === 'cname' ? 'cname' : 'srv';
        }

        if ($mode === 'rust') {
            return $requested === 'srv' ? 'srv' : 'cname';
        }

        return 'cname';
    }

    public function getDnsRecommendationForServer(Server $server): array
    {
        return $this->getDnsRecommendationForMode($this->getDnsModeForServer($server));
    }

    public function getDnsRecommendationForEgg(?string $eggName, ?string $nestName = null): array
    {
        return $this->getDnsRecommendationForMode($this->getDnsModeForEgg($eggName, $nestName));
    }

    private function getDnsRecommendationForMode(string $mode): array
    {

        if ($mode === 'minecraft') {
            return [
                'mode' => 'minecraft',
                'recommended_record_type' => 'srv',
                'srv_supported' => true,
                'allow_record_type_selection' => true,
                'forced_record_type' => null,
                'notice' => 'SRV is recommended for Minecraft-family servers. CNAME is also supported.',
                'connection_hint' => 'Use SRV for best compatibility (usually no :port), or CNAME if you prefer connecting with :port.',
            ];
        }

        if ($mode === 'rust') {
            return [
                'mode' => 'rust',
                'recommended_record_type' => 'cname',
                'srv_supported' => true,
                'allow_record_type_selection' => true,
                'forced_record_type' => null,
                'notice' => 'CNAME is recommended for Rust. SRV is available but not recommended.',
                'connection_hint' => 'Best option: CNAME with :port (example: play.example.com:28015).',
            ];
        }

        return [
            'mode' => 'generic',
            'recommended_record_type' => 'cname',
            'srv_supported' => false,
            'allow_record_type_selection' => false,
            'forced_record_type' => 'cname',
            'notice' => 'CNAME is the only supported option for this game profile.',
            'connection_hint' => 'Use the mapped domain with :port when connecting.',
        ];
    }

    private function resolveDnsModeFromLabels(string $labels): string
    {
        foreach (self::RUST_HINTS as $hint) {
            if (str_contains($labels, $hint)) {
                return 'rust';
            }
        }

        foreach (self::MINECRAFT_SERVICE_TAG_MAP as $needle => $_) {
            if (str_contains($labels, $needle)) {
                return 'minecraft';
            }
        }

        return 'generic';
    }

    private function supportsServer(CustomDomain $domain, Server $server): bool
    {
        $allowedNests = array_values(array_filter((array) ($domain->allowed_nest_ids ?? []), fn ($id) => is_numeric($id)));
        $allowedEggs = array_values(array_filter((array) ($domain->allowed_egg_ids ?? []), fn ($id) => is_numeric($id)));

        $nestAllowed = empty($allowedNests) || in_array((int) $server->nest_id, array_map('intval', $allowedNests), true);
        $eggAllowed = empty($allowedEggs) || in_array((int) $server->egg_id, array_map('intval', $allowedEggs), true);

        return $nestAllowed && $eggAllowed;
    }

    private function resolveServiceTag(ServerCustomDomain $mapping): ?string
    {
        if ($this->resolveRecordTypeForServer($mapping->server, $mapping->record_type) !== 'srv') {
            return null;
        }

        $customTag = $this->normalizeServiceTagPrefix((string) ($mapping->service_tag ?? ''));
        if ($customTag !== null) {
            return $customTag;
        }

        if ($this->getDnsModeForServer($mapping->server) === 'rust') {
            return '_rust._';
        }

        return $this->resolveSuggestedServiceTag($mapping->server, $mapping->customDomain);
    }

    private function normalizeServiceTagPrefix(?string $serviceTag): ?string
    {
        $value = strtolower(trim((string) $serviceTag));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^_([a-z0-9][a-z0-9-]*)\._(?:tcp|udp)$/', $value, $matches) === 1) {
            return '_' . $matches[1] . '._';
        }

        if (preg_match('/^_([a-z0-9][a-z0-9-]*)\._$/', $value, $matches) === 1) {
            return '_' . $matches[1] . '._';
        }

        if (preg_match('/^_?([a-z0-9][a-z0-9-]*)$/', $value, $matches) === 1) {
            return '_' . $matches[1] . '._';
        }

        throw new DisplayException('Invalid service tag. Use format like _minecraft._');
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

    private function serverLabels(Server $server): string
    {
        return strtolower(trim(($server->egg?->name ?? '') . ' ' . ($server->nest?->name ?? '')));
    }
}
