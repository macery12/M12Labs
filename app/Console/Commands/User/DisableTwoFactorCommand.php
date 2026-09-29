<?php

namespace Everest\Console\Commands\User;

use Illuminate\Console\Command;
use Everest\Contracts\Repository\UserRepositoryInterface;
use Everest\Exceptions\Repository\RecordNotFoundException;

class DisableTwoFactorCommand extends Command
{
    protected $description = 'Disable two-factor authentication for a specific user in the Panel.';

    protected $signature = 'p:user:disable2fa {--email= : The email of the user to disable 2-Factor for.}';

    /**
     * DisableTwoFactorCommand constructor.
     */
    public function __construct(private UserRepositoryInterface $repository)
    {
        parent::__construct();
    }

    /**
     * Handle command execution process.
     *
     * @throws \Everest\Exceptions\Model\DataValidationException
     */
    public function handle(): int
    {
        if ($this->input->isInteractive()) {
            $this->output->warning(trans('command/messages.user.2fa_help_text'));
        }

        $email = $this->option('email') ?? $this->ask(trans('command/messages.user.ask_email'));
        try {
            $user = $this->repository->setColumns(['id', 'email'])->findFirstWhere([['email', '=', $email]]);
        } catch (RecordNotFoundException) {
            $this->components->error(trans('command/messages.user.no_users_found'));

            return self::FAILURE;
        }

        $this->repository->withoutFreshModel()->update($user->id, [
            'use_totp' => false,
            'totp_secret' => null,
        ]);
        $this->info(trans('command/messages.user.2fa_disabled', ['email' => $user->email]));

        return self::SUCCESS;
    }
}
