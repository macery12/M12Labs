<?php

namespace Everest\Exceptions\Service\Extension;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Everest\Exceptions\DisplayException;

/**
 * Raised when an extension needs direct panel dependencies that are missing or
 * outside the version range declared by its verified manifest.
 *
 * Extensions never run a package manager. The structured response gives the
 * admin UI enough information to explain the refusal and offer commands the
 * operator may copy and run manually before retrying the same operation.
 */
class PackageRequirementsNotSatisfiedException extends DisplayException
{
    /**
     * @param array<int, array{
     *     type: 'frontend'|'backend',
     *     manager: 'npm'|'composer',
     *     package: string,
     *     required: string,
     *     installed: string|null,
     *     status: 'missing'|'incompatible'
     * }> $problems
     * @param array<string, string> $commands
     */
    public function __construct(
        public readonly string $extensionId,
        public readonly array $problems,
        public readonly array $commands,
    ) {
        $details = array_map(
            static fn (array $problem): string => $problem['installed'] === null
                ? sprintf('%s package "%s" requires %s but is not installed', $problem['manager'], $problem['package'], $problem['required'])
                : sprintf('%s package "%s" requires %s but this panel locks %s', $problem['manager'], $problem['package'], $problem['required'], $problem['installed']),
            $problems
        );

        parent::__construct(sprintf(
            'Extension "%s" cannot be installed because its package requirements are not satisfied: %s.',
            $extensionId,
            implode('; ', $details)
        ));
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->expectsJson()) {
            return parent::render($request);
        }

        return new JsonResponse([
            'error' => $this->getMessage(),
            'extension_id' => $this->extensionId,
            'package_requirements' => [
                'problems' => $this->problems,
                'commands' => $this->commands,
            ],
        ], $this->getStatusCode());
    }
}
