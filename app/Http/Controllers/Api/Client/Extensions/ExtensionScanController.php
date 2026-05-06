<?php

declare(strict_types=1);

namespace Everest\Http\Controllers\Api\Client\Extensions;

use Everest\Http\Controllers\Api\Client\ClientApiController;
use Everest\Services\Extensions\ExtensionSecurityScanner;
use Everest\Services\Extensions\ScanResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class ExtensionScanController extends ClientApiController
{
    public function __construct(private ExtensionSecurityScanner $scanner)
    {
        parent::__construct();
    }

    /**
     * POST /api/client/extensions/scan
     *
     * Accepts a multipart/form-data upload of a .M12LabsExtension file, runs the
     * security scanner, and returns the result.  Returns HTTP 422 if blocked.
     */
    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'extension_file' => [
                'required',
                'file',
                'max:51200', // 50 MB
                'mimetypes:application/zip,application/octet-stream,application/x-zip-compressed',
            ],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('extension_file');

        $tempPath = $file->storeAs(
            'extension-scans/uploads',
            $file->hashName() . '.M12LabsExtension',
            'local'
        );

        $absoluteTempPath = storage_path('app/' . $tempPath);

        try {
            $result = $this->scanner->scan($absoluteTempPath);
        } finally {
            if (is_file($absoluteTempPath)) {
                File::delete($absoluteTempPath);
            }
        }

        $data = $result->toArray();

        if ($result->isBlocked()) {
            return new JsonResponse($data, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * GET /api/client/extensions/{slug}/scan-report
     *
     * Returns the stored scan-report.json for an installed extension.
     */
    public function report(string $slug): JsonResponse
    {
        // Sanitize slug to prevent path traversal.
        $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $slug);
        if ($safeSlug === '' || $safeSlug !== $slug) {
            return new JsonResponse(['error' => 'Invalid extension slug.'], Response::HTTP_BAD_REQUEST);
        }

        $reportPath = storage_path('app/extensions/installed/' . $safeSlug . '/scan-report.json');

        if (!is_file($reportPath)) {
            return new JsonResponse(['error' => 'Scan report not found.'], Response::HTTP_NOT_FOUND);
        }

        $raw = file_get_contents($reportPath);
        if ($raw === false) {
            return new JsonResponse(['error' => 'Could not read scan report.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Scan report is malformed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($data, Response::HTTP_OK);
    }
}
