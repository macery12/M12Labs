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
            ['id' => 'address', 'i' => 'address', 'x' => 0, 'y' => 0, 'w' => 3, 'h' => 3, 'minW' => 2, 'minH' => 1],
            ['id' => 'uptime', 'i' => 'uptime', 'x' => 3, 'y' => 0, 'w' => 3, 'h' => 3, 'minW' => 2, 'minH' => 1],
            ['id' => 'cpuSummary', 'i' => 'cpuSummary', 'x' => 6, 'y' => 0, 'w' => 2, 'h' => 3, 'minW' => 2, 'minH' => 1],
            ['id' => 'memorySummary', 'i' => 'memorySummary', 'x' => 8, 'y' => 0, 'w' => 2, 'h' => 3, 'minW' => 2, 'minH' => 1],
            ['id' => 'diskSummary', 'i' => 'diskSummary', 'x' => 10, 'y' => 0, 'w' => 2, 'h' => 3, 'minW' => 2, 'minH' => 1],
            ['id' => 'console', 'i' => 'console', 'x' => 0, 'y' => 3, 'w' => 9, 'h' => 18, 'minW' => 6, 'minH' => 6],
            ['id' => 'cpuGraph', 'i' => 'cpuGraph', 'x' => 9, 'y' => 3, 'w' => 3, 'h' => 6, 'minW' => 3, 'minH' => 2],
            ['id' => 'memoryGraph', 'i' => 'memoryGraph', 'x' => 9, 'y' => 9, 'w' => 3, 'h' => 6, 'minW' => 3, 'minH' => 2],
            ['id' => 'networkGraph', 'i' => 'networkGraph', 'x' => 9, 'y' => 15, 'w' => 3, 'h' => 6, 'minW' => 3, 'minH' => 2],
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
            'hidden' => Arr::get($payload, 'hidden') ?? [],
            'layout' => Arr::get($payload, 'layout', []),
        ];

        if (is_null($layout['hidden'])) {
            $layout['hidden'] = [];
        }

        return $this->mergeMissingModules($layout);
    }

    private function mergeMissingModules(array $layout): array
    {
        $normalized = collect($layout['layout'] ?? [])->map(function ($item) {
            $id = $item['id'] ?? $item['i'] ?? null;
            return array_merge($item, ['id' => $id, 'i' => $id]);
        });

        $existing = $normalized->keyBy('id');

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
