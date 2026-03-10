<?php

namespace Everest\Services\Users;

use Everest\Events\Email\PasswordChanged;
use Everest\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Everest\Traits\Services\HasUserLevels;

class UserUpdateService
{
    use HasUserLevels;

    /**
     * UserUpdateService constructor.
     */
    public function __construct(private Hasher $hasher)
    {
    }

    /**
     * Update the user model instance and return the updated model.
     *
     * @throws \Throwable
     */
    public function handle(User $user, array $data): User
    {
        $passwordChanged = !empty(array_get($data, 'password'));

        if ($passwordChanged) {
            $data['password'] = $this->hasher->make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->forceFill($data)->saveOrFail();

        $user = $user->refresh();

        if ($passwordChanged) {
            event(new PasswordChanged(
                user: $user,
                correlationId: Str::uuid()->toString()
            ));
        }

        return $user;
    }
}
