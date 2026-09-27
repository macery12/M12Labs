<?php

namespace Database\Seeders;

use Everest\Models\Egg;
use Everest\Models\Nest;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Everest\Services\Eggs\Sharing\EggImporterService;
use Everest\Services\Eggs\Sharing\EggUpdateImporterService;

class EggSeeder extends Seeder
{
    /**
     * Previous identities for bundled eggs whose canonical catalog entry was renamed
     * or transferred to a different maintainer. Include the current name so that an
     * egg upgraded from a legacy identity continues to match on later seed runs: the
     * update importer intentionally preserves the original author.
     *
     * @var array<string, array{author: string, names: list<string>}>
     */
    private const LEGACY_IDENTITIES = [
        'minecraft/egg-forge-enhanced.json' => [
            'author' => 'support@pterodactyl.io',
            'names' => ['Forge Minecraft', 'Forge Enhanced'],
        ],
        'minecraft/egg-sponge-vanilla.json' => [
            'author' => 'support@pterodactyl.io',
            'names' => ['Sponge (SpongeVanilla)', 'SpongeVanilla'],
        ],
        'source-engine/egg-counter--strike2.json' => [
            'author' => 'support@pterodactyl.io',
            'names' => ['Counter-Strike: Global Offensive', 'Counter-Strike 2'],
        ],
        'source-engine/egg-insurgency--sandstorm.json' => [
            'author' => 'support@pterodactyl.io',
            'names' => ['Insurgency', 'Insurgency: Sandstorm'],
        ],
        'source-engine/egg-team-fortress-2.json' => [
            'author' => 'support@pterodactyl.io',
            'names' => ['Team Fortress 2'],
        ],
    ];

    /**
     * Previous nest locations for bundled eggs that have been reorganized.
     *
     * @var array<string, list<string>>
     */
    private const LEGACY_NESTS = [
        'steam-games/egg-ark--survival-evolved.json' => ['ARK', 'Source Engine'],
        'steam-games/egg-ark-survival-ascended.json' => ['ARK'],
        'steam-games/egg-palworld.json' => ['Palworld'],
        'steam-games/egg-project-zomboid.json' => ['Project Zomboid'],
        'steam-games/egg-satisfactory.json' => ['Satisfactory'],
        'steam-games/egg-valheim.json' => ['Valheim'],
        'steam-games/egg-factorio.json' => ['Factorio'],
    ];

    /**
     * @var list<array{Egg, string, string}>
     */
    private array $existing = [];

    /**
     * @var string[]
     */
    public static array $import = [
        'Minecraft',
        'Steam Games',
        'Source Engine',
        'Voice Servers',
        'Rust',
    ];

    /**
     * EggSeeder constructor.
     */
    public function __construct(
        private EggImporterService $importerService,
        private EggUpdateImporterService $updateImporterService,
    ) {
    }

    /**
     * Run the egg seeder.
     *
     * @throws \JsonException
     */
    public function run(bool $deferOverwrite = false)
    {
        $this->existing = [];
        $created = [];

        foreach (static::$import as $nest) {
            /* @noinspection PhpParamsInspection */
            [$createdInNest, $existingInNest] = $this->parseEggFiles(
                Nest::query()->where('author', 'support@pterodactyl.io')->where('name', $nest)->firstOrFail()
            );

            array_push($created, ...$createdInNest);
            array_push($this->existing, ...$existingInNest);
        }

        $duplicateCount = count($this->existing);

        $this->command->info(sprintf(
            'Added %d missing %s; found %d existing %s.',
            count($created),
            Str::plural('egg', count($created)),
            $duplicateCount,
            Str::plural('egg', $duplicateCount),
        ));

        if ($created !== []) {
            $this->command->comment('Missing eggs added:');
            foreach ($created as $name) {
                $this->command->line('  + ' . $name);
            }
        }

        if ($deferOverwrite || $duplicateCount === 0) {
            return;
        }

        $this->applyOverwrite($this->confirmOverwrite());
    }

    public function hasExistingRecords(): bool
    {
        return $this->existing !== [];
    }

    public function confirmOverwrite(): bool
    {
        $duplicateCount = count($this->existing);

        if ($duplicateCount === 0) {
            return false;
        }

        return $this->command->confirm(
            sprintf(
                'Would you like to overwrite the %d existing %s with the shipped definitions? This will replace any custom changes.',
                $duplicateCount,
                Str::plural('egg', $duplicateCount),
            ),
            false,
        );
    }

    public function applyOverwrite(bool $overwrite): void
    {
        $duplicateCount = count($this->existing);

        if ($duplicateCount === 0) {
            return;
        }

        if (!$overwrite) {
            $this->command->comment(sprintf(
                'Preserved %d existing %s.',
                $duplicateCount,
                Str::plural('egg', $duplicateCount),
            ));

            return;
        }

        foreach ($this->existing as [$egg, $path, $name]) {
            $file = new UploadedFile($path, basename($path), 'application/json');
            $this->updateImporterService->handle($egg, $file);
            $this->command->info('Updated ' . $name);
        }

        $this->command->info(sprintf(
            'Overwrote %d existing %s.',
            $duplicateCount,
            Str::plural('egg', $duplicateCount),
        ));
    }

    /**
     * Loop through the list of egg files and import them.
     *
     * @return array{list<string>, list<array{Egg, string, string}>}
     *
     * @throws \JsonException
     */
    protected function parseEggFiles(Nest $nest): array
    {
        $directory = Str::kebab($nest->name);
        $files = new \DirectoryIterator(database_path('Seeders/eggs/' . $directory));
        $created = [];
        $existing = [];

        /** @var \DirectoryIterator $file */
        foreach ($files as $file) {
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }

            $path = $file->getPathname();
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $egg = $nest->eggs()
                ->where('author', $decoded['author'])
                ->where('name', $decoded['name'])
                ->first();

            $relativePath = $directory . '/' . $file->getFilename();
            $legacyIdentity = self::LEGACY_IDENTITIES[$relativePath] ?? null;

            if (!$egg instanceof Egg && $legacyIdentity !== null) {
                $egg = $nest->eggs()
                    ->where('author', $legacyIdentity['author'])
                    ->whereIn('name', $legacyIdentity['names'])
                    ->first();
            }

            $legacyNests = self::LEGACY_NESTS[$relativePath] ?? null;

            if (!$egg instanceof Egg && $legacyNests !== null) {
                $legacyNestIds = Nest::query()
                    ->where('author', 'support@pterodactyl.io')
                    ->whereIn('name', $legacyNests)
                    ->pluck('id');

                $egg = Egg::query()
                    ->whereIn('nest_id', $legacyNestIds)
                    ->where('author', $decoded['author'])
                    ->where('name', $decoded['name'])
                    ->first();

                if ($egg instanceof Egg) {
                    $this->moveEggToNest($egg, $nest);
                }
            }

            if ($egg instanceof Egg) {
                $existing[] = [$egg, $path, $decoded['name']];
            } else {
                $upload = new UploadedFile($path, $file->getFilename(), 'application/json');
                $this->importerService->handleFile($nest->id, $upload);
                $created[] = $nest->name . ' / ' . $decoded['name'];
            }
        }

        return [$created, $existing];
    }

    private function moveEggToNest(Egg $egg, Nest $nest): void
    {
        DB::transaction(function () use ($egg, $nest): void {
            $egg->forceFill(['nest_id' => $nest->id])->save();

            foreach (['servers', 'server_presets', 'categories'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)
                        ->where('egg_id', $egg->id)
                        ->update(['nest_id' => $nest->id]);
                }
            }
        });
    }
}
