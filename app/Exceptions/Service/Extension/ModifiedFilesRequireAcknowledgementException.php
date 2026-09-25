<?php

namespace Everest\Exceptions\Service\Extension;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;

/**
 * Raised when an update or uninstall would discard local edits to a package's
 * installed files.
 *
 * Modelled on {@see CapabilityApprovalRequiredException}: a 409 carrying enough
 * detail for the client to describe what it is about to destroy, which the
 * client then re-submits with an explicit acknowledgement. A plain 400 left the
 * admin UI unable to tell this apart from any other failure, so an operator
 * whose files had drifted could neither update nor remove the package.
 *
 * This is not a security boundary. The files are already on disk and already
 * executing; the check exists so the panel never silently discards work an
 * operator meant to keep, and so unexpected drift is visible.
 */
class ModifiedFilesRequireAcknowledgementException extends DisplayException
{
    /**
     * @param array<int, string> $modifiedPaths
     */
    public function __construct(
        public readonly string $extensionId,
        public readonly string $verb,
        public readonly array $modifiedPaths,
    ) {
        $preview = implode(', ', array_slice($modifiedPaths, 0, 5));
        $suffix = count($modifiedPaths) > 5 ? sprintf(', and %d more', count($modifiedPaths) - 5) : '';

        parent::__construct(sprintf(
            'The extension cannot be %s because %d file(s) were modified after installation: %s%s. '
            . 'If the changes are not yours to keep — a code formatter run over the panel tree is the usual cause — '
            . 'repeat the operation acknowledging that they will be discarded.',
            $verb,
            count($modifiedPaths),
            $preview,
            $suffix
        ));
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_CONFLICT;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->expectsJson()) {
            return parent::render($request);
        }

        return new JsonResponse([
            'error' => $this->getMessage(),
            'extension_id' => $this->extensionId,
            'modified_files' => [
                'verb' => $this->verb,
                'paths' => $this->modifiedPaths,
            ],
        ], Response::HTTP_CONFLICT);
    }
}
