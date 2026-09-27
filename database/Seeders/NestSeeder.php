<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Everest\Services\Nests\NestCreationService;
use Everest\Contracts\Repository\NestRepositoryInterface;

class NestSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const NESTS = [
        'Minecraft' => 'Minecraft - the classic game from Mojang. With support for Vanilla MC, Paper, mod loaders, proxies, and more.',
        'Steam Games' => 'Dedicated servers for popular Steam games, including ARK, Factorio, Palworld, Project Zomboid, Satisfactory, and Valheim.',
        'Source Engine' => 'Includes support for most Source Dedicated Server games.',
        'Voice Servers' => 'Voice servers such as Mumble and Teamspeak 3.',
        'Rust' => 'Rust - A game where you must fight to survive.',
    ];

    /**
     * @var NestCreationService
     */
    private $creationService;

    /**
     * @var NestRepositoryInterface
     */
    private $repository;

    /**
     * NestSeeder constructor.
     */
    public function __construct(
        NestCreationService $creationService,
        NestRepositoryInterface $repository,
    ) {
        $this->creationService = $creationService;
        $this->repository = $repository;
    }

    /**
     * Run the seeder to add missing nests to the Panel.
     *
     * @throws \Everest\Exceptions\Model\DataValidationException
     */
    public function run()
    {
        $items = $this->repository->findWhere([
            'author' => 'support@pterodactyl.io',
        ])->keyBy('name')->toArray();

        $created = [];

        foreach (self::NESTS as $name => $description) {
            if (array_key_exists($name, $items)) {
                continue;
            }

            $this->creationService->handle([
                'name' => $name,
                'description' => $description,
            ], 'support@pterodactyl.io');

            $created[] = $name;
        }

        $existingCount = count(self::NESTS) - count($created);

        $this->command->info(sprintf(
            'Added %d missing %s; found %d existing %s.',
            count($created),
            Str::plural('nest', count($created)),
            $existingCount,
            Str::plural('nest', $existingCount),
        ));

        if ($created !== []) {
            $this->command->comment('Missing nests added:');
            foreach ($created as $name) {
                $this->command->line('  + ' . $name);
            }
        }
    }
}
