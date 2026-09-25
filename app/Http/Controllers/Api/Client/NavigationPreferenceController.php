<?php

namespace Everest\Http\Controllers\Api\Client;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Everest\Models\UserNavigationPreference;
use Everest\Http\Requests\Api\Client\Account\UpdateNavigationPreferencesRequest;

/**
 * The authenticated user's sidebar state (pins, folded groups) for one area.
 *
 * Stored on the account rather than in the browser so it follows an admin
 * across devices. Not activity-logged: it is layout, not account security.
 */
class NavigationPreferenceController extends ClientApiController
{
    public function index(Request $request, string $area): JsonResponse
    {
        $preference = UserNavigationPreference::query()
            ->where('user_id', $request->user()->id)
            ->where('area', $area)
            ->first();

        return new JsonResponse($this->present($preference->pinned ?? [], $preference->collapsed ?? []));
    }

    /**
     * Replace the whole state. The client always sends everything it holds,
     * so the last write wins and there is nothing to merge.
     */
    public function update(UpdateNavigationPreferencesRequest $request, string $area): JsonResponse
    {
        /** @var array<int, string> $pinned */
        $pinned = array_values($request->input('pinned', []));
        /** @var array<string, bool> $collapsed */
        $collapsed = $request->input('collapsed', []);

        UserNavigationPreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'area' => $area],
            ['pinned' => $pinned, 'collapsed' => $collapsed],
        );

        return new JsonResponse($this->present($pinned, $collapsed));
    }

    /**
     * @param array<int, string> $pinned
     * @param array<string, bool> $collapsed
     *
     * @return array<string, mixed>
     */
    private function present(array $pinned, array $collapsed): array
    {
        // An empty map must serialise as {} rather than [], or the client
        // reads the collapse state as a list.
        return ['pinned' => $pinned, 'collapsed' => (object) $collapsed];
    }
}
