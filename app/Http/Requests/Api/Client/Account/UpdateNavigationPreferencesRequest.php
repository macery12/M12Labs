<?php

namespace Everest\Http\Requests\Api\Client\Account;

use Everest\Models\UserNavigationPreference;
use Everest\Http\Requests\Api\Client\ClientApiRequest;

class UpdateNavigationPreferencesRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'pinned' => ['present', 'array', 'list', 'max:' . UserNavigationPreference::MAX_PINNED],
            'pinned.*' => ['string', 'distinct', 'max:191', 'regex:#^/[A-Za-z0-9/._~-]*$#'],
            // Keyed by `group:<category>` or `ext:<extension id>`. An empty
            // object arrives from PHP's JSON decoder as [], which `array`
            // accepts; the key shape is checked in after().
            'collapsed' => ['present', 'array', 'max:' . UserNavigationPreference::MAX_COLLAPSED],
            'collapsed.*' => ['boolean:strict'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $collapsed = $this->input('collapsed');
                if (!is_array($collapsed)) {
                    return;
                }

                foreach (array_keys($collapsed) as $key) {
                    if (!is_string($key) || preg_match('/^(group|ext):[a-z0-9_-]{1,64}$/', $key) !== 1) {
                        $validator->errors()->add('collapsed', 'Collapse state keys must look like "group:<name>" or "ext:<id>".');

                        return;
                    }
                }
            },
        ];
    }
}
