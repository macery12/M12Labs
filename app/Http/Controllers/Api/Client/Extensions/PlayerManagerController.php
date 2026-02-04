<?php

namespace Everest\Http\Controllers\Api\Client\Extensions;

use Everest\Models\Server;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Everest\Models\ExtensionConfig;
use Everest\Repositories\Wings\DaemonFileRepository;
use Everest\Repositories\Wings\DaemonCommandRepository;
use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Services\Extensions\MinecraftPlayerManager\MinecraftPing;
use Everest\Services\Extensions\MinecraftPlayerManager\MinecraftQuery;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\GetStatusRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\PlayerRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\PlayerNamedRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\BanRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\BanIpRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\IpRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\KickRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\WhisperRequest;
use Everest\Http\Requests\Api\Client\Extensions\PlayerManager\SetWhitelistRequest;

class PlayerManagerController extends ClientApiController
{
    public function __construct(
        private DaemonFileRepository $fileRepository,
        private DaemonCommandRepository $commandRepository
    ) {
        parent::__construct();
    }

    /**
     * Sanitize player name to prevent command injection.
     * Minecraft usernames can only be 3-16 characters, alphanumeric and underscore.
     */
    private function sanitizePlayerName(string $name): string
    {
        // Remove any characters that aren't alphanumeric or underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        
        // Ensure length is between 3 and 16 characters
        if (strlen($sanitized) < 3 || strlen($sanitized) > 16) {
            throw new \InvalidArgumentException('Invalid player name format');
        }
        
        return $sanitized;
    }

    /**
     * Validate and sanitize IP address.
     */
    private function sanitizeIpAddress(string $ip): string
    {
        // Validate as IPv4 or IPv6
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid IP address format');
        }
        
        return $ip;
    }

    /**
     * Sanitize reason/message to prevent command injection.
     */
    private function sanitizeMessage(string $message): string
    {
        // Remove newlines and limit length
        $sanitized = str_replace(["\r", "\n", "\t"], ' ', $message);
        return substr(trim($sanitized), 0, 255);
    }

    /**
     * Check if the extension is enabled for this server.
     */
    private function checkExtensionEnabled(Server $server): void
    {
        $config = ExtensionConfig::getByExtensionId('minecraft_player_manager');
        
        if (!$config || !$config->isServerEligible($server)) {
            throw new \Exception('Minecraft Player Manager is not enabled for this server.');
        }
    }

    private function queryApi(Server $server): array
    {
        return Cache::remember("minecraftserver:query:{$server->id}", 10, function () use ($server) {
            if ($this->isQueryEnabled($server)) {
                $query = new MinecraftQuery();
                $query->Connect($server->allocation->alias ?? $server->allocation->ip, $server->allocation->port, 2, false);

                $data = $query->GetInfo();

                if (!$data) {
                    throw new \Exception('Failed to query server');
                }

                $players = [];
                $rawPlayers = $query->GetPlayers();
                if ($rawPlayers) {
                    foreach ($rawPlayers as $player) {
                        $userData = $this->lookupUserName($player, $server);

                        if ($userData) {
                            $uuid = $userData['uuid'];
                        }

                        if (!$uuid) {
                            continue;
                        }

                        $players[] = [
                            'id' => $uuid,
                            'name' => $player,
                        ];
                    }
                }

                return [
                    'players' => [
                        'online' => $data['Players'],
                        'max' => $data['MaxPlayers'],
                        'list' => $players,
                    ],
                ];
            } else {
                $query = new MinecraftPing($server->allocation->alias ?? $server->allocation->ip, $server->allocation->port, 2, false);
                $query->Connect();

                $data = $query->Query();

                if (!$data) {
                    throw new \Exception('Failed to query server');
                }

                return [
                    'players' => [
                        'online' => $data['players']['online'],
                        'max' => $data['players']['max'],
                        'list' => $data['players']['sample'] ?? [],
                    ],
                ];
            }
        });
    }

    private function userCache(Server $server): array
    {
        return Cache::remember("minecraftserver:username-cache:{$server->id}", 30, function () use ($server) {
            try {
                $cache = $this->fileRepository->setServer($server)->getContent('/usercache.json');
                return json_decode($cache, true) ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    private function formatUuid(string $uuid): string
    {
        $uuid = str_replace('-', '', $uuid);
        return substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);
    }

    private function lookupUser(string $uuid, Server $server): array|null
    {
        $name = config('app.name', 'Jexactyl');
        $uuid = str_replace('-', '', $uuid);
        $cache = $this->userCache($server);

        foreach ($cache as $player) {
            if ($player['uuid'] === $this->formatUuid($uuid)) {
                return [
                    'uuid' => $this->formatUuid($player['uuid']),
                    'name' => $player['name'],
                ];
            }
        }

        $data = Cache::remember("minecraftplayer:$uuid", 1000, function () use ($name, $uuid) {
            try {
                $req = Http::withUserAgent("Jexactyl Player Manager @ $name")
                    ->timeout(5)
                    ->retry(2, 100, throw: true)
                    ->get("https://sessionserver.mojang.com/session/minecraft/profile/$uuid");

                return json_decode($req->getBody()->getContents(), true);
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (is_null($data)) {
            return null;
        }

        return [
            'uuid' => $this->formatUuid($data['id']),
            'name' => $data['name'],
        ];
    }

    private function lookupUserName(string $name, Server $server): array|null
    {
        $app = config('app.name', 'Jexactyl');
        $offline = $this->isOfflineMode($server);
        $cache = $this->userCache($server);

        foreach ($cache as $player) {
            if ($player['name'] === $name) {
                return [
                    'uuid' => $this->formatUuid($player['uuid']),
                    'name' => $player['name'],
                ];
            }
        }

        if ($offline) {
            $uuid = $this->formatUuid(md5("OfflinePlayer:$name"));
            return [
                'uuid' => $uuid,
                'name' => $name,
            ];
        }

        $data = Cache::remember("minecraftplayername:$name", 1000, function () use ($app, $name) {
            try {
                $req = Http::withUserAgent("Jexactyl Player Manager @ $app")
                    ->timeout(5)
                    ->retry(2, 100, throw: true)
                    ->get("https://api.mojang.com/users/profiles/minecraft/$name");

                return json_decode($req->getBody()->getContents(), true);
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (is_null($data)) {
            return null;
        }

        return [
            'uuid' => $this->formatUuid($data['id']),
            'name' => $data['name'],
        ];
    }

    private function sortList(array $list): array
    {
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'] ?? $a['ip'], $b['name'] ?? $b['ip']);
        });

        return $list;
    }

    private function getServerProperties(Server $server): array
    {
        return Cache::remember("minecraftserver:properties:{$server->id}", 10, function () use ($server) {
            try {
                $properties = $this->fileRepository->setServer($server)->getContent('/server.properties');
                $data = explode("\n", $properties);

                $result = [];
                foreach ($data as $line) {
                    if (str_starts_with($line, '#')) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    $result[$parts[0]] = $parts[1] ?? '';
                }

                return $result;
            } catch (\Throwable $e) {
                return [];
            }
        });
    }

    private function isQueryEnabled(Server $server): bool
    {
        $properties = $this->getServerProperties($server);

        if (array_key_exists('enable-query', $properties) && $properties['enable-query'] === 'true') {
            return true;
        }

        return false;
    }

    private function isOfflineMode(Server $server): bool
    {
        $properties = $this->getServerProperties($server);

        if (array_key_exists('online-mode', $properties) && $properties['online-mode'] === 'false') {
            return true;
        }

        return false;
    }

    private function isBukkitBased(Server $server): bool
    {
        return Cache::remember("minecraftserver:bukkit:{$server->id}", 30, function () use ($server) {
            try {
                $bukkitYml = $this->fileRepository->setServer($server)->getContent('/bukkit.yml');
                return !!$bukkitYml;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    /**
     * Get player manager status for server.
     */
    public function index(GetStatusRequest $request, Server $server): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        $properties = $this->getServerProperties($server);

        $onlineMode = !$this->isOfflineMode($server);
        $opped = [];
        $whitelisted = [];
        $whitelistEnabled = array_key_exists('white-list', $properties) && $properties['white-list'] === 'true';
        $banned = [];
        $bannedIps = [];

        // Load ops.json
        try {
            $ops = $this->fileRepository->setServer($server)->getContent('/ops.json');
            $data = json_decode($ops, true);

            foreach ($data as $op) {
                $uuid = str_replace('-', '', $op['uuid']);

                $opped[] = [
                    'uuid' => $op['uuid'],
                    'name' => $op['name'],
                    'level' => $op['level'],
                    'bypassesPlayerLimit' => $op['bypassesPlayerLimit'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                    'render' => "https://render.skinmc.net/3d.php?user=$uuid&vr=-20&hr=30&hrh=0&vrll=-20&vrrl=10&vrla=10&vrra=-10&ratio=20",
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Load whitelist.json
        try {
            $whitelist = $this->fileRepository->setServer($server)->getContent('/whitelist.json');
            $data = json_decode($whitelist, true);

            foreach ($data as $whitelist) {
                $uuid = str_replace('-', '', $whitelist['uuid']);

                $whitelisted[] = [
                    'uuid' => $whitelist['uuid'],
                    'name' => $whitelist['name'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                    'render' => "https://render.skinmc.net/3d.php?user=$uuid&vr=-20&hr=30&hrh=0&vrll=-20&vrrl=10&vrla=10&vrra=-10&ratio=20",
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Load banned-players.json
        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-players.json');
            $data = json_decode($bans, true);

            foreach ($data as $ban) {
                $uuid = str_replace('-', '', $ban['uuid']);

                $banned[] = [
                    'uuid' => $ban['uuid'],
                    'name' => $ban['name'],
                    'reason' => $ban['reason'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                    'render' => "https://render.skinmc.net/3d.php?user=$uuid&vr=-20&hr=30&hrh=0&vrll=-20&vrrl=10&vrla=10&vrra=-10&ratio=20",
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Load banned-ips.json
        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-ips.json');
            $data = json_decode($bans, true);

            foreach ($data as $ban) {
                $bannedIps[] = [
                    'ip' => $ban['ip'],
                    'reason' => $ban['reason'],
                ];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Try to query online players
        try {
            $data = $this->queryApi($server);

            $players = [];
            foreach ($data['players']['list'] ?? [] as $player) {
                $uuid = str_replace('-', '', $player['id']);

                if (preg_match('/^0+$/', $uuid) || str_starts_with($uuid, '0000000000000000')) {
                    continue;
                }

                $players[] = [
                    'uuid' => $player['id'],
                    'name' => $player['name'],
                    'avatar' => "https://minotar.net/helm/$uuid/256.png",
                    'render' => "https://render.skinmc.net/3d.php?user=$uuid&vr=-20&hr=30&hrh=0&vrll=-20&vrrl=10&vrla=10&vrra=-10&ratio=20",
                ];
            }

            return new JsonResponse([
                'server' => [
                    'online' => true,
                    'players' => [
                        'online' => $data['players']['online'],
                        'max' => $data['players']['max'],
                        'list' => $this->sortList($players),
                    ],
                    'version' => '',
                    'motd' => '',
                ],
                'operators' => $this->sortList($opped),
                'whitelist' => $this->sortList($whitelisted),
                'bannedPlayers' => $this->sortList($banned),
                'bannedIps' => $this->sortList($bannedIps),
                'whitelistEnabled' => $whitelistEnabled,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'server' => [
                    'online' => false,
                    'players' => [
                        'online' => 0,
                        'max' => 0,
                        'list' => [],
                    ],
                    'version' => '',
                    'motd' => '',
                ],
                'operators' => $this->sortList($opped),
                'whitelist' => $this->sortList($whitelisted),
                'bannedPlayers' => $this->sortList($banned),
                'bannedIps' => $this->sortList($bannedIps),
                'whitelistEnabled' => $whitelistEnabled,
            ]);
        }
    }

    public function op(PlayerNamedRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        try {
            $ops = $this->fileRepository->setServer($server)->getContent('/ops.json');
            $data = json_decode($ops, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        foreach ($data as $op) {
            if ($op['name'] === $name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already an operator',
                ], 400);
            }
        }

        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $playerData['uuid'],
            'name' => $playerData['name'],
            'level' => 4,
            'bypassesPlayerLimit' => true,
        ];

        $this->fileRepository->setServer($server)->putContent('/ops.json', json_encode($data, JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:op {$playerData['name']}" : "op {$playerData['name']}";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.op')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name']])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function deop(PlayerRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        try {
            $ops = $this->fileRepository->setServer($server)->getContent('/ops.json');
            $data = json_decode($ops, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        // Look up player by name from route parameter
        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($op) use ($playerData) {
            return $op['uuid'] !== $playerData['uuid'];
        });

        $this->fileRepository->setServer($server)->putContent('/ops.json', json_encode(array_values($data), JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:deop {$playerData['name']}" : "deop {$playerData['name']}";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.deop')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name']])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function setWhitelist(SetWhitelistRequest $request, Server $server): array
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $properties = $this->fileRepository->setServer($server)->getContent('/server.properties');
            $data = explode("\n", $properties);
        } catch (\Throwable $e) {
            $data = [];
        }

        $whitelist = $request->input('enabled');

        $data = array_map(function ($line) use ($whitelist) {
            if (str_starts_with($line, 'white-list=')) {
                return 'white-list=' . ($whitelist ? 'true' : 'false');
            }
            return $line;
        }, $data);

        if (!in_array('white-list=false', $data) && !in_array('white-list=true', $data)) {
            $data[] = 'white-list=' . ($whitelist ? 'true' : 'false');
        }

        Cache::forget("minecraftserver:properties:{$server->id}");
        $this->fileRepository->setServer($server)->putContent('/server.properties', implode("\n", $data));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? 'minecraft:whitelist ' : 'whitelist ';
            $this->commandRepository->setServer($server)->send($cmd . ($whitelist ? 'on' : 'off'));
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:whitelist.set')
            ->property(['enabled' => $whitelist])
            ->log();

        return ['success' => true];
    }

    public function addWhitelist(PlayerNamedRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        try {
            $whitelist = $this->fileRepository->setServer($server)->getContent('/whitelist.json');
            $data = json_decode($whitelist, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        foreach ($data as $w) {
            if ($w['name'] === $name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already whitelisted',
                ], 400);
            }
        }

        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $playerData['uuid'],
            'name' => $playerData['name'],
        ];

        $this->fileRepository->setServer($server)->putContent('/whitelist.json', json_encode($data, JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:whitelist add {$playerData['name']}" : "whitelist add {$playerData['name']}";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:whitelist.add')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name']])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function removeWhitelist(PlayerRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        try {
            $whitelist = $this->fileRepository->setServer($server)->getContent('/whitelist.json');
            $data = json_decode($whitelist, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($w) use ($playerData) {
            return $w['uuid'] !== $playerData['uuid'];
        });

        $this->fileRepository->setServer($server)->putContent('/whitelist.json', json_encode(array_values($data), JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:whitelist remove {$playerData['name']}" : "whitelist remove {$playerData['name']}";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:whitelist.remove')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name']])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function ban(BanRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        $reason = $this->sanitizeMessage($request->input('reason', 'Banned by panel'));
        
        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-players.json');
            $data = json_decode($bans, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        foreach ($data as $ban) {
            if ($ban['name'] === $name) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Player is already banned',
                ], 400);
            }
        }

        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data[] = [
            'uuid' => $playerData['uuid'],
            'name' => $playerData['name'],
            'source' => 'Panel',
            'created' => date('Y-m-d H:i:s O'),
            'expires' => 'forever',
            'reason' => $reason,
        ];

        $this->fileRepository->setServer($server)->putContent('/banned-players.json', json_encode($data, JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:ban {$playerData['name']} $reason" : "ban {$playerData['name']} $reason";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.ban')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name'], 'reason' => $reason])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function unban(PlayerRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-players.json');
            $data = json_decode($bans, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        $playerData = $this->lookupUserName($name, $server);

        if (is_null($playerData)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to lookup player',
            ], 400);
        }

        $data = array_filter($data, function ($ban) use ($playerData) {
            return $ban['uuid'] !== $playerData['uuid'];
        });

        $this->fileRepository->setServer($server)->putContent('/banned-players.json', json_encode(array_values($data), JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:pardon {$playerData['name']}" : "pardon {$playerData['name']}";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.unban')
            ->property(['uuid' => $playerData['uuid'], 'name' => $playerData['name']])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function banIp(BanIpRequest $request, Server $server, string $ip): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $ip = $this->sanitizeIpAddress($ip);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        $reason = $this->sanitizeMessage($request->input('reason', 'Banned by panel'));

        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-ips.json');
            $data = json_decode($bans, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        foreach ($data as $ban) {
            if ($ban['ip'] === $ip) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'IP is already banned',
                ], 400);
            }
        }

        $data[] = [
            'ip' => $ip,
            'source' => 'Panel',
            'created' => date('Y-m-d H:i:s O'),
            'expires' => 'forever',
            'reason' => $reason,
        ];

        $this->fileRepository->setServer($server)->putContent('/banned-ips.json', json_encode($data, JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:ban-ip $ip $reason" : "ban-ip $ip $reason";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.ban-ip')
            ->property(['ip' => $ip, 'reason' => $reason])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function unbanIp(IpRequest $request, Server $server, string $ip): JsonResponse
    {
        $this->checkExtensionEnabled($server);

        try {
            $ip = $this->sanitizeIpAddress($ip);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        try {
            $bans = $this->fileRepository->setServer($server)->getContent('/banned-ips.json');
            $data = json_decode($bans, true);
        } catch (\Throwable $e) {
            $data = [];
        }

        $data = array_filter($data, function ($ban) use ($ip) {
            return $ban['ip'] !== $ip;
        });

        $this->fileRepository->setServer($server)->putContent('/banned-ips.json', json_encode(array_values($data), JSON_PRETTY_PRINT));
        usleep(500000);

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:pardon-ip $ip" : "pardon-ip $ip";
            $this->commandRepository->setServer($server)->send($cmd);
        } catch (\Throwable $e) {
            // ignore
        }

        Activity::event('server:player.unban-ip')
            ->property(['ip' => $ip])
            ->log();

        return new JsonResponse(['success' => true]);
    }

    public function kick(KickRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        $reason = $this->sanitizeMessage($request->input('reason', 'Kicked by panel'));

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:kick $name $reason" : "kick $name $reason";
            $this->commandRepository->setServer($server)->send($cmd);

            Activity::event('server:player.kick')
                ->property(['name' => $name, 'reason' => $reason])
                ->log();

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function whisper(WhisperRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
        
        $message = $this->sanitizeMessage($request->input('message'));

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:tell $name $message" : "tell $name $message";
            $this->commandRepository->setServer($server)->send($cmd);

            Activity::event('server:player.whisper')
                ->property(['name' => $name, 'message' => $message])
                ->log();

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }

    public function kill(PlayerRequest $request, Server $server, string $player): JsonResponse
    {
        $this->checkExtensionEnabled($server);
        
        try {
            $name = $this->sanitizePlayerName($player);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }

        try {
            $cmd = $this->isBukkitBased($server) ? "minecraft:kill $name" : "kill $name";
            $this->commandRepository->setServer($server)->send($cmd);

            Activity::event('server:player.kill')
                ->property(['name' => $name])
                ->log();

            return new JsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Server is offline',
            ], 400);
        }
    }
}
