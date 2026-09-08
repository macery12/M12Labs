<?php

namespace Everest\Exceptions\Service\Extension;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;
use Everest\Services\Extensions\Manifest\ExtensionCapabilityDiff;

/**
 * Raised when an install or update would grant privileges the administrator
 * has not approved.
 *
 * The diff is computed from the VERIFIED manifest rather than from registry
 * metadata, so approval is over what the package actually declares — which
 * means the archive must be downloaded and checked before this can be raised.
 * The operation aborts before any file is copied.
 *
 * The response carries the diff and its hash; the client re-submits the same
 * request with that hash to consent.
 */
class CapabilityApprovalRequiredException extends DisplayException
{
    public function __construct(
        public readonly string $extensionId,
        public readonly ExtensionCapabilityDiff $diff,
    ) {
        parent::__construct(sprintf(
            'This package requests capabilities that have not been approved: %s.',
            implode(', ', $diff->escalations)
        ));
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /**
     * The diff has to reach the client, so this renders its own body rather
     * than the parent's generic error shape: the admin UI needs the exact list
     * of new privileges to show, and the hash to consent with.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->expectsJson()) {
            return parent::render($request);
        }

        return new JsonResponse([
            'error' => $this->getMessage(),
            'extension_id' => $this->extensionId,
            'capability_diff' => $this->diff->jsonSerialize(),
        ], Response::HTTP_CONFLICT);
    }
}
