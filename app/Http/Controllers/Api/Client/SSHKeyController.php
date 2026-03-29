<?php

namespace Everest\Http\Controllers\Api\Client;

use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Everest\Http\Requests\Api\Client\ClientApiRequest;
use Everest\Transformers\Api\Client\UserSSHKeyTransformer;
use Everest\Http\Requests\Api\Client\Account\StoreSSHKeyRequest;

class SSHKeyController extends ClientApiController
{
    /**
     * Returns all the SSH keys that have been configured for the logged-in
     * user account.
     */
    public function index(ClientApiRequest $request): array
    {
        return $this->transform($request->user()->sshKeys, UserSSHKeyTransformer::class);
    }

    /**
     * Stores a new SSH key for the authenticated user's account.
     */
    public function store(StoreSSHKeyRequest $request): array
    {
        $model = $request->user()->sshKeys()->create([
            'name' => $request->input('name'),
            'public_key' => $request->getPublicKey(),
            'fingerprint' => $request->getKeyFingerprint(),
        ]);

        Activity::event('user:ssh-key.create')
            ->subject($model)
            ->property('fingerprint', $request->getKeyFingerprint())
            ->log();

        return $this->transform($model, UserSSHKeyTransformer::class);
    }

    /**
     * Deletes an SSH key from the user's account.
     */
    public function delete(ClientApiRequest $request): Response
    {
        $this->validate($request, ['fingerprint' => ['required', 'string']]);

        $key = $request->user()->sshKeys()
            ->where('fingerprint', $request->input('fingerprint'))
            ->first();

        if (!is_null($key)) {
            $key->delete();

            Activity::event('user:ssh-key.delete')
                ->subject($key)
                ->property('fingerprint', $key->fingerprint)
                ->log();
        }

        return $this->returnNoContent();
    }
}
