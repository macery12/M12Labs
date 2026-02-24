<?php

namespace Everest\Services\Servers;

use Illuminate\Support\Arr;

class ConsoleWorkspaceLayout
{
    public const LAYOUT_KEY = 'console';
    public const VERSION = 1;

    private const DEFAULT_LAYOUT = [
        'version' => self::VERSION,
        'hidden' => [],
        'layout' => [
            ['id' => 'address', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 2, 'minW' => 2, 'minH' => 1],
            ['id' => 'uptime', 'x' => 2, 'y' => 0, 'w' => 2, 'h' => 2, 'minW' => 2, 'minH' => 1],
            ['id' => 'cpuSummary', 'x' => 4, 'y' => 0, 'w' => 2, 'h' => 2, 'minW' => 2, 'minH' => 1],
            ['id' => 'memorySummary', 'x' => 6, 'y' => 0, 'w' => 2, 'h' => 2, 'minW' => 2, 'minH' => 1],
            ['id' => 'diskSummary', 'x' => 8, 'y' => 0, 'w' => 2, 'h' => 2, 'minW' => 2, 'minH' => 1],
            ['id' => 'console', 'x' => 0, 'y' => 2, 'w' => 9, 'h' => 12, 'minW' => 6, 'minH' => 3],
            ['id' => 'cpuGraph', 'x' => 9, 'y' => 2, 'w' => 3, 'h' => 4, 'minW' => 3, 'minH' => 2],
            ['id' => 'memoryGraph', 'x' => 9, 'y' => 6, 'w' => 3, 'h' => 4, 'minW' => 3, 'minH' => 2],
            ['id' => 'networkGraph', 'x' => 9, 'y' => 10, 'w' => 3, 'h' => 4, 'minW' => 3, 'minH' => 2],
        ],
    ];

    public function default(): array
    {
        return self::DEFAULT_LAYOUT;
    }

    public function normalize(array $payload): array
    {
        $layout = [
            'version' => Arr::get($payload, 'version', self::VERSION),
            'hidden' => Arr::get($payload, 'hidden', []),
            'layout' => Arr::get($payload, 'layout', []),
        ];

        return $this->mergeMissingModules($layout);
    }

    private function mergeMissingModules(array $layout): array
    {
        $existing = collect($layout['layout'] ?? [])->keyBy('id');

        $merged = collect(self::DEFAULT_LAYOUT['layout'])
            ->map(function ($item) use ($existing) {
                if ($existing->has($item['id'])) {
                    return array_merge($item, $existing->get($item['id']));
                }

                return $item;
            })
            ->values()
            ->all();

        $layout['layout'] = array_values($merged);

        return $layout;
    }
}
