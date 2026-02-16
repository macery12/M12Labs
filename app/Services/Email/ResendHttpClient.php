<?php

namespace Everest\Services\Email;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Everest\Exceptions\Service\Email\ResendException;
use Everest\Exceptions\Service\Email\ResendValidationException;
use Everest\Exceptions\Service\Email\ResendAuthenticationException;
use Everest\Exceptions\Service\Email\ResendRateLimitException;
use Everest\Exceptions\Service\Email\ResendServerException;
use Illuminate\Support\Facades\Log;

class ResendHttpClient
{
    private const API_URL = 'https://api.resend.com/emails';
    private const MAX_RETRIES = 3;
    private const INITIAL_RETRY_DELAY = 1000; // milliseconds

    private Client $client;
    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'timeout' => 30,
        ]);
    }

    /**
     * Send an email via the Resend API.
     * Returns array with response data and metadata.
     *
     * @throws ResendException
     */
    public function sendEmail(array $payload): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $response = $this->client->post(self::API_URL, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]);

                $statusCode = $response->getStatusCode();
                $body = json_decode($response->getBody()->getContents(), true);

                if ($statusCode === 200 || $statusCode === 201) {
                    return [
                        'body' => $body,
                        'status_code' => $statusCode,
                    ];
                }

                throw new ResendException('Unexpected status code: ' . $statusCode, $statusCode);
            } catch (RequestException $e) {
                $lastException = $e;
                $statusCode = $e->getResponse()?->getStatusCode();
                $responseBody = $e->getResponse()?->getBody()->getContents();
                $errorData = $responseBody ? json_decode($responseBody, true) : null;
                $errorMessage = $errorData['message'] ?? $e->getMessage();

                // Handle specific error codes
                if ($statusCode === 400) {
                    // Check if it's a domain-related error
                    if (stripos($errorMessage, 'domain') !== false || stripos($errorMessage, 'from') !== false) {
                        throw new ResendValidationException(
                            $errorMessage . ' - Make sure the domain in your "From Email" is verified in your Resend account at https://resend.com/domains',
                            $statusCode
                        );
                    }
                    throw new ResendValidationException($errorMessage, $statusCode);
                }

                if ($statusCode === 401) {
                    throw new ResendAuthenticationException($errorMessage, $statusCode);
                }

                if ($statusCode === 429) {
                    throw new ResendRateLimitException($errorMessage, $statusCode);
                }

                // Handle 5xx errors with exponential backoff
                if ($statusCode >= 500 && $statusCode < 600) {
                    $attempt++;
                    
                    if ($attempt < self::MAX_RETRIES) {
                        $delay = self::INITIAL_RETRY_DELAY * pow(2, $attempt - 1);
                        usleep($delay * 1000); // Convert to microseconds
                        continue;
                    }

                    throw new ResendServerException($errorMessage, $statusCode);
                }

                // Unknown error
                throw new ResendException($errorMessage, $statusCode ?? 0);
            } catch (GuzzleException $e) {
                Log::error('Resend HTTP client error', [
                    'error' => $e->getMessage(),
                ]);

                throw new ResendException('HTTP client error: ' . $e->getMessage(), 0);
            }
        }

        throw new ResendServerException('Maximum retry attempts exceeded', 500);
    }
}
