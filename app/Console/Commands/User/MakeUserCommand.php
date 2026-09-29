<?php

namespace Everest\Console\Commands\User;

use Everest\Models\User;
use Everest\Models\AdminRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Contracts\Auth\PasswordBroker;
use Everest\Services\Users\UserCreationService;

class MakeUserCommand extends Command
{
    protected $description = 'Creates a user on the system via the CLI.';

    protected $signature = 'p:user:make
                            {--email=}
                            {--username=}
                            {--name-first=}
                            {--name-last=}
                            {--password=}
                            {--admin= : Make this user a Root Admin with full access (true/false)}
                            {--no-password : Create the account without a password and print a one-time link to set one}';

    /**
     * Prompt attempts before giving up, so a closed or scripted stdin cannot
     * spin on the password question forever.
     */
    private const PASSWORD_ATTEMPTS = 3;

    /**
     * MakeUserCommand constructor.
     */
    public function __construct(private UserCreationService $creationService, private PasswordBroker $passwordBroker)
    {
        parent::__construct();
    }

    /**
     * Handle command request to create a new user.
     *
     * @throws \Exception
     * @throws \Everest\Exceptions\Model\DataValidationException
     */
    public function handle(): int
    {
        $owner = $this->ownerRequested();
        if ($owner === null) {
            $this->components->error(trans('command/messages.user.invalid_admin'));

            return self::FAILURE;
        }
        $ownerProfile = $owner
            ? AdminRole::query()->where('is_owner', true)->first()
            : null;
        if ($owner && !$ownerProfile) {
            $this->components->error(trans('command/messages.user.owner_missing'));

            return self::FAILURE;
        }

        $email = $this->option('email') ?? $this->ask(trans('command/messages.user.ask_email'));
        $username = $this->option('username') ?? $this->ask(trans('command/messages.user.ask_username'));

        // Pressing Enter at the prompt used to create the account with a random
        // password nobody knew, and the reset token made for it was never shown
        // or sent -- on a fresh install with no mail, a locked-out first admin.
        // No password is now only ever the explicit --no-password.
        $password = null;
        if (!$this->option('no-password') && ($password = $this->resolvePassword()) === null) {
            return self::FAILURE;
        }

        $data = compact('email', 'username', 'password');
        if ($ownerProfile) {
            $data['admin_role_id'] = $ownerProfile->id;
            $data['root_admin'] = true;
        }

        $user = $this->creationService->handle($data);
        $this->table(['Field', 'Value'], [
            ['UUID', $user->uuid],
            ['Email', $user->email],
            ['Username', $user->username],
            ['Root Admin', $user->isOwner() ? 'Yes' : 'No'],
        ]);

        if ($password === null) {
            $this->printPasswordLink($user);
        }

        return self::SUCCESS;
    }

    /**
     * The password from --password, or the prompt, held to the same rule as the
     * web registration and reset forms (without their breached-password lookup,
     * so an offline install still works). Null when none acceptable was given.
     */
    private function resolvePassword(): ?string
    {
        $option = $this->option('password');
        if ($option !== null) {
            return $this->acceptable($option) ? $option : null;
        }

        if (!$this->input->isInteractive()) {
            $this->components->error(trans('command/messages.user.password_required'));

            return null;
        }

        $this->warn(trans('command/messages.user.ask_password_help'));
        $this->line(trans('command/messages.user.ask_password_tip'));

        for ($attempt = 0; $attempt < self::PASSWORD_ATTEMPTS; ++$attempt) {
            $password = (string) $this->secret(trans('command/messages.user.ask_password'));

            if ($this->acceptable($password)) {
                return $password;
            }
        }

        return null;
    }

    private function acceptable(string $password): bool
    {
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        foreach ($validator->errors()->all() as $error) {
            $this->components->error($error);
        }

        return $validator->passes();
    }

    /**
     * A passwordless account is only usable through a reset link, and the one
     * the creation service makes is never delivered. Issue one here (which
     * replaces it) and print it, so no mail setup is needed to sign in.
     */
    private function printPasswordLink(User $user): void
    {
        $token = $this->passwordBroker->createToken($user);

        $this->newLine();
        $this->line(trans('command/messages.user.password_link', [
            'minutes' => config('auth.passwords.users.expire', 60),
        ]));
        $this->line(rtrim(config('app.url'), '/') . '/auth/password/reset/' . $token . '?email=' . urlencode($user->email));
    }

    /**
     * Resolve the optional non-interactive Owner flag without relying on
     * PHP's unsafe non-empty-string-to-true cast.
     */
    private function ownerRequested(): ?bool
    {
        $value = $this->option('admin');
        if ($value === null) {
            return $this->confirm(trans('command/messages.user.ask_admin'));
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
