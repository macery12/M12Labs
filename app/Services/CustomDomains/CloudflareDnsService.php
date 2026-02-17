<?php

namespace Everest\Services\CustomDomains;

use Exception;
use Everest\Models\Setting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class CloudflareDnsService
{
    private function client(): PendingRequest
    {
        $token = trim((string) config('modules.custom_domains.cloudflare.token', ''));

        if ($token === '') {
            $token = trim((string) Setting::get('settings::modules:custom_domains:cloudflare:token', ''));
        }

        $token = $this->normalizeToken($token);

        if ($token === '') {
            throw new Exception('Cloudflare API token is not configured for custom domains.');
        }

        $retries = (int) config('modules.custom_domains.cloudflare.retries', 3);
        $sleep = (int) config('modules.custom_domains.cloudflare.retry_sleep_ms', 250);

        return Http::retry($retries, $sleep)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ]);
    }

    private function normalizeToken(string $token): string
    {
        $normalized = trim($token);

        if ($normalized === '') {
            return '';
        }

        $normalized = trim($normalized, "\"'");
        $normalized = preg_replace('/\s+/', '', $normalized) ?? '';

        if (str_starts_with(strtolower($normalized), 'bearer')) {
            $normalized = preg_replace('/^bearer/i', '', $normalized) ?? '';
            $normalized = trim($normalized);
        }

        return $normalized;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('modules.custom_domains.cloudflare.base_url', 'https://api.cloudflare.com/client/v4'), '/');
    }

    public function getZoneByName(string $domain): ?array
    {
        try {
            $response = $this->client()->get($this->baseUrl() . '/zones', [
                'name' => $domain,
                'status' => 'active',
                'match' => 'all',
            ])->throw();
        } catch (RequestException $exception) {
            throw new Exception($this->formatCloudflareError('Cloudflare zone lookup failed.', $exception));
        }

        $json = $response->json();

        if (!($json['success'] ?? false)) {
            return null;
        }

        return Arr::first($json['result'] ?? []);
    }

    public function createOrUpdateAOrCnameRecord(string $zoneId, string $name, string $target): array
    {
        $type = filter_var($target, FILTER_VALIDATE_IP) ? 'A' : 'CNAME';
        $proxied = (bool) config('modules.custom_domains.cloudflare.proxied', false);

        $existing = $this->findRecord($zoneId, $type, $name);
        $payload = [
            'type' => $type,
            'name' => $name,
            'content' => $target,
            'proxied' => $type === 'A' ? $proxied : false,
            'ttl' => 1,
        ];

        if ($existing) {
            return $this->updateRecord($zoneId, $existing['id'], $payload);
        }

        return $this->createRecord($zoneId, $payload);
    }

    public function createOrUpdateSrvRecord(
        string $zoneId,
        string $fqdn,
        string $service,
        string $proto,
        int $port,
        string $target,
    ): array {
        $recordName = '_' . $service . '._' . $proto . '.' . $fqdn;

        $payload = [
            'type' => 'SRV',
            'name' => $recordName,
            'data' => [
                'priority' => 1,
                'weight' => 1,
                'port' => $port,
                'target' => $target,
            ],
            'ttl' => 1,
        ];

        $existing = $this->findRecord($zoneId, 'SRV', $recordName);

        if ($existing) {
            return $this->updateRecord($zoneId, $existing['id'], $payload);
        }

        return $this->createRecord($zoneId, $payload);
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        try {
            $this->client()->delete($this->baseUrl() . '/zones/' . $zoneId . '/dns_records/' . $recordId)->throw();
        } catch (RequestException $exception) {
            throw new Exception($this->formatCloudflareError('Cloudflare DNS delete request failed.', $exception));
        }
    }

    private function findRecord(string $zoneId, string $type, string $name): ?array
    {
        try {
            $response = $this->client()->get($this->baseUrl() . '/zones/' . $zoneId . '/dns_records', [
                'type' => $type,
                'name' => $name,
                'per_page' => 1,
                'page' => 1,
                'match' => 'all',
            ])->throw();
        } catch (RequestException $exception) {
            throw new Exception($this->formatCloudflareError('Cloudflare DNS lookup request failed.', $exception));
        }

        $json = $response->json();

        if (!($json['success'] ?? false)) {
            return null;
        }

        return Arr::first($json['result'] ?? []);
    }

    private function createRecord(string $zoneId, array $payload): array
    {
        try {
            $response = $this->client()
                ->post($this->baseUrl() . '/zones/' . $zoneId . '/dns_records', $payload)
                ->throw();
        } catch (RequestException $exception) {
            throw new Exception($this->formatCloudflareError('Cloudflare DNS create request failed.', $exception));
        }

        $json = $response->json();

        if (!($json['success'] ?? false)) {
            throw new Exception('Cloudflare DNS create request failed.');
        }

        return $json['result'];
    }

    private function updateRecord(string $zoneId, string $recordId, array $payload): array
    {
        try {
            $response = $this->client()
                ->put($this->baseUrl() . '/zones/' . $zoneId . '/dns_records/' . $recordId, $payload)
                ->throw();
        } catch (RequestException $exception) {
            throw new Exception($this->formatCloudflareError('Cloudflare DNS update request failed.', $exception));
        }

        $json = $response->json();

        if (!($json['success'] ?? false)) {
            throw new Exception('Cloudflare DNS update request failed.');
        }

        return $json['result'];
    }

    private function formatCloudflareError(string $prefix, RequestException $exception): string
    {
        $body = trim((string) optional($exception->response)->body());

        return $body !== '' ? $prefix . ' Response: ' . $body : $prefix;
    }
}
