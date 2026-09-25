<?php

namespace Everest\Services\Extensions;

use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Everest\Exceptions\DisplayException;

/**
 * Fetches bounded extension marketplace resources without following an
 * unchecked redirect or buffering an attacker-sized response in memory.
 */
class ExtensionRemoteResourceService
{
    public function __construct(private ExtensionRemoteUrlGuard $urlGuard)
    {
    }

    public function getContents(
        string $url,
        int $maxBytes,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        int $maxRedirects,
        string $label,
    ): string {
        $response = $this->request(
            $url,
            $maxBytes,
            $timeoutSeconds,
            $connectTimeoutSeconds,
            $maxRedirects,
            $label,
        );
        $this->assertDeclaredLengthWithinLimit($response, $maxBytes, $label);

        $contents = '';
        $stream = $response->toPsrResponse()->getBody();
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                continue;
            }

            if (strlen($contents) + strlen($chunk) > $maxBytes) {
                throw $this->oversized($label, $maxBytes);
            }

            $contents .= $chunk;
        }

        return $contents;
    }

    public function download(
        string $url,
        string $destination,
        int $maxBytes,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        int $maxRedirects,
        string $label,
    ): void {
        $response = $this->request(
            $url,
            $maxBytes,
            $timeoutSeconds,
            $connectTimeoutSeconds,
            $maxRedirects,
            $label,
        );
        $this->assertDeclaredLengthWithinLimit($response, $maxBytes, $label);

        $handle = @fopen($destination, 'wb');
        if ($handle === false) {
            throw new DisplayException(sprintf('Unable to create the temporary file for the %s.', $label));
        }

        $written = 0;
        try {
            $stream = $response->toPsrResponse()->getBody();
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    continue;
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw $this->oversized($label, $maxBytes);
                }

                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new DisplayException(sprintf('Unable to write the downloaded %s.', $label));
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($destination);

            throw $exception;
        }

        fclose($handle);
    }

    private function request(
        string $initialUrl,
        int $maxBytes,
        int $timeoutSeconds,
        int $connectTimeoutSeconds,
        int $maxRedirects,
        string $label,
    ): Response {
        $url = $initialUrl;

        for ($redirects = 0;; ++$redirects) {
            $target = $this->urlGuard->assertSafeHttpsUrl($url, ucfirst($label) . ' URL');
            $transferExceededLimit = false;

            // The progress callback is what stops an oversized response before
            // Guzzle finishes spooling it into php://temp. PHP's stream handler
            // does not honor a truthy progress return, so cURL is required even
            // when the destination is already an IP literal and needs no DNS
            // pin entry.
            if (!function_exists('curl_exec')) {
                throw new DisplayException(sprintf('The %s cannot be fetched securely because the PHP cURL extension is unavailable. Install or enable php-curl for the panel PHP runtime.', $label));
            }

            $options = [
                'allow_redirects' => false,
                // CURLOPT_RESOLVE is a cURL-only control. Guzzle deliberately
                // sends `stream: true` requests through its PHP stream handler,
                // even when ext-curl is installed, and that handler correctly
                // rejects ignored cURL options. Let the normal synchronous cURL
                // handler spool into php://temp and abort it at the byte limit.
                'decode_content' => false,
                'protocols' => ['https'],
                // A proxy would resolve/connect independently and defeat the
                // DNS result pinned below. Do not inherit ambient proxy vars for
                // marketplace resources.
                'proxy' => ['no' => ['*']],
                'progress' => static function (
                    int $downloadTotal,
                    int $downloadedBytes,
                    int $uploadTotal,
                    int $uploadedBytes,
                ) use (&$transferExceededLimit, $maxBytes): bool {
                    if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                        $transferExceededLimit = true;

                        return true;
                    }

                    return false;
                },
            ];

            // Validation and connection must use the same DNS result. Without
            // pinning, an attacker can answer the guard with a public address
            // and rebind the hostname to loopback/private space for Guzzle's
            // second lookup.
            if (!$target['literalIp']) {
                if (!defined('CURLOPT_RESOLVE')) {
                    throw new DisplayException(sprintf('The %s cannot be fetched securely because the PHP cURL extension is unavailable. Install or enable php-curl for the panel PHP runtime.', $label));
                }

                $options['curl'] = [
                    CURLOPT_RESOLVE => array_map(
                        fn (string $address): string => sprintf(
                            '%s:%d:%s',
                            $target['host'],
                            $target['port'],
                            str_contains($address, ':') ? '[' . $address . ']' : $address,
                        ),
                        $target['addresses'],
                    ),
                ];
            }

            try {
                $response = Http::timeout($timeoutSeconds)
                    ->connectTimeout($connectTimeoutSeconds)
                    ->withOptions($options)
                    ->get($url);
            } catch (\Throwable $exception) {
                if ($transferExceededLimit) {
                    throw $this->oversized($label, $maxBytes);
                }

                throw new DisplayException(sprintf('Unable to fetch %s from "%s".', $label, $url), $exception);
            }

            if (!$this->isRedirect($response->toPsrResponse())) {
                if (!$response->successful()) {
                    throw new DisplayException(sprintf('Unable to fetch %s from "%s".', $label, $url));
                }

                return $response;
            }

            if ($redirects >= $maxRedirects) {
                throw new DisplayException(sprintf('The %s exceeded the permitted redirect limit.', $label));
            }

            $location = trim($response->header('Location'));
            $response->toPsrResponse()->getBody()->close();
            if ($location === '') {
                throw new DisplayException(sprintf('The %s returned a redirect without a destination.', $label));
            }

            try {
                $url = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor($location));
            } catch (\Throwable $exception) {
                throw new DisplayException(sprintf('The %s returned an invalid redirect destination.', $label), $exception);
            }
        }
    }

    private function assertDeclaredLengthWithinLimit(Response $response, int $maxBytes, string $label): void
    {
        $length = trim($response->header('Content-Length'));
        if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
            throw $this->oversized($label, $maxBytes);
        }
    }

    private function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    private function oversized(string $label, int $maxBytes): DisplayException
    {
        return new DisplayException(sprintf('The %s is larger than the permitted %d bytes.', $label, $maxBytes));
    }
}
