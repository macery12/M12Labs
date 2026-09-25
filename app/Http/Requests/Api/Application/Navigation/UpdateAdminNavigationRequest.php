<?php

namespace Everest\Http\Requests\Api\Application\Navigation;

use Everest\Models\AdminRole;
use Illuminate\Validation\Validator;
use Everest\Http\Requests\Api\Application\ApplicationApiRequest;

class UpdateAdminNavigationRequest extends ApplicationApiRequest
{
    /**
     * An entry id: a core route path (`infrastructure`, `access/users`) or an
     * extension entry (`ext:<id>`). The server cannot know which exist (the
     * route registry is frontend code), so it only checks the shape; ids that
     * match nothing are ignored where the layout is applied.
     */
    private const ITEM = '#^(ext:[a-z0-9_]{1,64}|[a-z0-9][a-z0-9/_-]{0,127})$#';

    public function permission(): string
    {
        return AdminRole::SETTINGS_UPDATE;
    }

    public function rules(): array
    {
        return [
            // null restores the built-in layout.
            'layout' => ['present', 'nullable', 'array'],
            'layout.groups' => ['required_with:layout', 'array', 'list', 'max:20'],
            'layout.groups.*.key' => ['required', 'string', 'distinct', 'regex:/^[a-z][a-z0-9-]{0,31}$/'],
            'layout.groups.*.label' => ['nullable', 'string', 'max:40'],
            'layout.groups.*.collapsed' => ['required', 'boolean:strict'],
            'layout.groups.*.items' => ['present', 'array', 'list', 'max:100'],
            'layout.groups.*.items.*' => ['string', 'regex:' . self::ITEM],
            'layout.hidden' => ['sometimes', 'array', 'list', 'max:200'],
            'layout.hidden.*' => ['string', 'distinct', 'regex:' . self::ITEM],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $groups = $this->input('layout.groups');
                if (!is_array($groups)) {
                    return;
                }

                $seen = [];
                foreach ($groups as $index => $group) {
                    if (!is_array($group)) {
                        continue;
                    }

                    // Built-in groups fall back to their translated name; an
                    // operator-made group has nothing to fall back to.
                    $key = (string) ($group['key'] ?? '');
                    if (str_starts_with($key, 'custom-') && trim((string) ($group['label'] ?? '')) === '') {
                        $validator->errors()->add("layout.groups.$index.label", 'A group you added needs a name.');
                    }

                    foreach ((array) ($group['items'] ?? []) as $item) {
                        if (is_string($item) && isset($seen[$item])) {
                            $validator->errors()->add("layout.groups.$index.items", sprintf('"%s" is placed in more than one group.', $item));

                            return;
                        }
                        $seen[$item] = true;
                    }
                }
            },
        ];
    }
}
