<?php

namespace Everest\Jobs\Mods;

use Carbon\CarbonImmutable;
use Everest\Jobs\Job;
use Everest\Models\AddonPackage;
use Everest\Models\Server;
use Everest\Models\ServerAddonFile;
use Everest\Repositories\Wings\DaemonFileRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class ScanAddonFileJob extends Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public string $queue = 'standard';

    public function __construct(
        public int $serverId,
        public string $path,
        public string $type
    ) {
    }

    public function handle(DaemonFileRepository $fileRepository): void
    {
        $server = Server::find($this->serverId);
        if (!$server) {
            return;
        }

        $fileRepository->setServer($server);

        $tempPath = null;

        try {
            $tempPath = $this->downloadToTemp($fileRepository, $server, $this->path);

            if (!$tempPath || !file_exists($tempPath)) {
                return;
            }

            $hash = hash_file('sha256', $tempPath);

            $zip = new ZipArchive();
            $metadata = [];
            if ($zip->open($tempPath) === true) {
                $metadata = $this->extractMetadata($zip);
                $zip->close();
            }

            $package = null;
            $zipForIcon = new ZipArchive();
            if ($zipForIcon->open($tempPath) === true) {
                $package = $this->persistPackage($metadata, $zipForIcon);
                $zipForIcon->close();
            } else {
                $package = $this->persistPackage($metadata, null);
            }
            $this->persistServerFile($hash, $package, $metadata['version'] ?? null);
        } catch (\Throwable $exception) {
            Log::warning('Addon scan failed', [
                'server_id' => $this->serverId,
                'path' => $this->path,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function downloadToTemp(DaemonFileRepository $fileRepository, Server $server, string $path): ?string
    {
        $response = $fileRepository->getHttpClient()->get(
            sprintf('/api/servers/%s/files/download', $server->uuid),
            ['query' => ['file' => $path]]
        );

        $url = Arr::get(json_decode($response->getBody()->__toString(), true), 'attributes.url');
        if (!$url) {
            return null;
        }

        $tempDir = storage_path('app/tmp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = tempnam($tempDir, 'addon_');
        $response = Http::timeout(120)->sink($tempPath)->get($url);

        if (!$response->successful() || !file_exists($tempPath) || filesize($tempPath) === 0) {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return null;
        }

        return $tempPath;
    }

    private function persistPackage(array $metadata, ?ZipArchive $zip): ?AddonPackage
    {
        if (empty($metadata['identity_key']) || empty($metadata['loader']) || empty($metadata['name'])) {
            return null;
        }

        $existing = AddonPackage::query()->where('identity_key', $metadata['identity_key'])
            ->where('loader', $metadata['loader'])
            ->first();

        $iconPath = $this->storeIcon($metadata, $zip) ?? $existing?->icon_path;

        return AddonPackage::query()->updateOrCreate(
            [
                'identity_key' => $metadata['identity_key'],
                'loader' => $metadata['loader'],
            ],
            [
                'provider' => $metadata['provider'] ?? 'local',
                'name' => $metadata['name'],
                'description' => $metadata['description'] ?? null,
                'authors' => $metadata['authors'] ?? [],
                'homepage_url' => $metadata['homepage_url'] ?? null,
                'source_url' => $metadata['source_url'] ?? null,
                'issues_url' => $metadata['issues_url'] ?? null,
                'icon_path' => $iconPath,
            ]
        );
    }

    private function persistServerFile(string $hash, ?AddonPackage $package, ?string $version): void
    {
        $record = ServerAddonFile::query()->where('server_id', $this->serverId)->where('path', $this->path)->first();

        if (!$record) {
            return;
        }

        $record->fill([
            'jar_hash' => $hash,
            'package_id' => $package?->id,
            'package_version' => $version ?? $record->package_version,
            'last_scanned_at' => CarbonImmutable::now(),
        ]);

        $record->save();
    }

    private function extractMetadata(ZipArchive $zip): array
    {
        $metadata = $this->extractForgeMetadata($zip);
        if (!empty($metadata)) {
            return $metadata;
        }

        $metadata = $this->extractFabricMetadata($zip);
        if (!empty($metadata)) {
            return $metadata;
        }

        $metadata = $this->extractQuiltMetadata($zip);
        if (!empty($metadata)) {
            return $metadata;
        }

        $metadata = $this->extractBukkitMetadata($zip);
        if (!empty($metadata)) {
            return $metadata;
        }

        return [];
    }

    private function extractForgeMetadata(ZipArchive $zip): array
    {
        $content = $this->getFirstAvailableFile($zip, ['META-INF/mods.toml', 'mods.toml', 'META-INF/neoforge.mods.toml', 'neoforge.mods.toml']);
        if (!$content) {
            return [];
        }

        $identity = $this->extractTomlValue($content, 'modId');
        $name = $this->extractTomlValue($content, 'displayName') ?? $identity;

        return [
            'identity_key' => $identity ? Str::slug($identity) : null,
            'loader' => str_contains($content, 'neoforge') ? 'neoforge' : 'forge',
            'name' => $name ?? 'Unknown Mod',
            'version' => $this->extractTomlValue($content, 'version'),
            'description' => $this->extractTomlValue($content, 'description'),
            'authors' => $this->extractTomlArray($content, 'authors'),
            'icon' => $this->extractTomlValue($content, 'logoFile'),
        ];
    }

    private function extractFabricMetadata(ZipArchive $zip): array
    {
        $content = $this->getFirstAvailableFile($zip, ['fabric.mod.json']);
        if (!$content) {
            return [];
        }

        $data = json_decode($content, true);
        if (!$data) {
            return [];
        }

        return [
            'identity_key' => Arr::get($data, 'id') ? Str::slug(Arr::get($data, 'id')) : null,
            'loader' => 'fabric',
            'name' => Arr::get($data, 'name', Arr::get($data, 'id', 'Unknown Mod')),
            'version' => Arr::get($data, 'version'),
            'description' => Arr::get($data, 'description'),
            'authors' => $this->normalizeAuthors(Arr::get($data, 'authors', [])),
            'icon' => $this->normalizeIconField(Arr::get($data, 'icon')),
        ];
    }

    private function extractQuiltMetadata(ZipArchive $zip): array
    {
        $content = $this->getFirstAvailableFile($zip, ['quilt.mod.json']);
        if (!$content) {
            return [];
        }

        $data = json_decode($content, true);
        if (!$data) {
            return [];
        }

        $meta = Arr::get($data, 'quilt_loader.metadata', []);
        $id = Arr::get($meta, 'id') ?? Arr::get($data, 'quilt_loader.id');

        return [
            'identity_key' => $id ? Str::slug($id) : null,
            'loader' => 'quilt',
            'name' => Arr::get($meta, 'name', $id ?? 'Unknown Mod'),
            'version' => Arr::get($meta, 'version') ?? Arr::get($meta, 'version_id'),
            'description' => Arr::get($meta, 'description'),
            'authors' => $this->normalizeAuthors(Arr::get($meta, 'contributors', [])),
            'icon' => $this->normalizeIconField(Arr::get($meta, 'icon')),
        ];
    }

    private function extractBukkitMetadata(ZipArchive $zip): array
    {
        $content = $this->getFirstAvailableFile($zip, ['plugin.yml', 'paper-plugin.yml']);
        if (!$content) {
            return [];
        }

        try {
            $data = Yaml::parse($content);
        } catch (\Throwable $exception) {
            return [];
        }

        $name = Arr::get($data, 'name');
        $authors = Arr::get($data, 'authors', []);
        if (!$authors && Arr::get($data, 'author')) {
            $authors = [Arr::get($data, 'author')];
        }

        return [
            'identity_key' => $name ? Str::slug($name) : null,
            'loader' => str_contains(strtolower($content), 'paper') ? 'paper' : 'bukkit',
            'name' => $name ?? 'Unknown Plugin',
            'version' => Arr::get($data, 'version'),
            'description' => Arr::get($data, 'description'),
            'authors' => $this->normalizeAuthors($authors),
            'icon' => Arr::get($data, 'icon'),
        ];
    }

    private function getFirstAvailableFile(ZipArchive $zip, array $paths): ?string
    {
        foreach ($paths as $path) {
            $clean = ltrim($path, '/');
            $index = $zip->locateName($clean, ZipArchive::FL_NOCASE);
            if ($index !== false) {
                return $zip->getFromIndex($index);
            }
        }

        return null;
    }

    private function extractTomlValue(string $content, string $key): ?string
    {
        if (preg_match('/' . preg_quote($key, '/') . '\s*=\s*"(.*?)"/i', $content, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/' . preg_quote($key, '/') . '\s*=\s*([A-Za-z0-9_.-]+)/i', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractTomlArray(string $content, string $key): array
    {
        if (!preg_match('/' . preg_quote($key, '/') . '\s*=\\s*\\[(.*?)\\]/is', $content, $matches)) {
            return [];
        }

        $raw = explode(',', $matches[1]);

        return array_values(array_filter(array_map(function ($item) {
            $item = trim($item);
            $item = trim($item, '"\' ');

            return $item;
        }, $raw)));
    }

    private function normalizeAuthors(mixed $authors): array
    {
        if (!is_array($authors)) {
            return $authors ? [$authors] : [];
        }

        return array_values(array_filter(array_map(function ($author) {
            if (is_string($author)) {
                return $author;
            }

            if (is_array($author) && isset($author['name'])) {
                return $author['name'];
            }

            return null;
        }, $authors)));
    }

    private function normalizeIconField(mixed $icon): ?string
    {
        if (is_string($icon)) {
            return $icon;
        }

        if (is_array($icon)) {
            return Arr::first(array_filter($icon, fn ($value) => is_string($value)));
        }

        return null;
    }

    private function storeIcon(array $metadata, ?ZipArchive $zip): ?string
    {
        if (empty($metadata['icon']) || !$zip) {
            return null;
        }

        $iconData = $this->getFirstAvailableFile($zip, [$metadata['icon']]);
        if (!$iconData) {
            return null;
        }

        $resized = $this->resizeIcon($iconData);
        if ($resized) {
            $fileName = 'addon-icons/' . Str::slug($metadata['identity_key'] ?? Str::random(10)) . '.png';
            Storage::disk('public')->put($fileName, $resized);

            return $fileName;
        }

        return null;
    }

    private function resizeIcon(string $contents): ?string
    {
        $image = imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $size = 48;
        $new = imagecreatetruecolor($size, $size);
        imagesavealpha($new, true);
        $transparent = imagecolorallocatealpha($new, 0, 0, 0, 127);
        imagefill($new, 0, 0, $transparent);

        $width = imagesx($image);
        $height = imagesy($image);

        imagecopyresampled($new, $image, 0, 0, 0, 0, $size, $size, $width, $height);

        ob_start();
        imagepng($new);
        $data = ob_get_clean();

        imagedestroy($image);
        imagedestroy($new);

        return $data ?: null;
    }
}
