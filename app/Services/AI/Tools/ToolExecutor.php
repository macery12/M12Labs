<?php

namespace Everest\Services\AI\Tools;

use Illuminate\Support\Str;
use Everest\Services\AI\AiConfiguration;
use Everest\Services\Access\InternalRequest;
use Everest\Services\Access\InternalDispatch;
use Symfony\Component\HttpFoundation\Response;
use Everest\Exceptions\Service\Access\InternalDispatchException;

/**
 * Runs a tool by dispatching an internal sub-request through the panel's real
 * HTTP pipeline.
 *
 * The core security decision of the agent: every tool call traverses the exact
 * middleware a browser request does — `AuthenticateServerAccess`,
 * `ResourceBelongsToServer`, the endpoint's FormRequest `permission()` gate and
 * its validation — so there is no second authorization path to drift.
 *
 * The dispatch itself is core's ({@see InternalDispatch}), along with the six
 * constraints that make it safe to do at all. What is here is the half that is
 * genuinely about *the agent*: how long a tool call may take under this
 * panel's AI settings, and how a response becomes something a model can read
 * and act on. That second part is most of the file, and none of it is
 * general-purpose — it is prose written for a model, and it leaks nothing.
 */
class ToolExecutor
{
    public function __construct(private InternalDispatch $dispatch)
    {
    }

    public function execute(
        ToolInvocation $invocation,
        ?int $maxSeconds = null,
    ): ToolResult {
        try {
            return $this->toResult($this->dispatch->dispatch(
                new InternalRequest(
                    method: $invocation->method,
                    uri: $invocation->uri,
                    query: $invocation->query,
                    body: $invocation->body,
                    idempotencyKey: $invocation->idempotencyKey,
                ),
                deadlineSeconds: $maxSeconds,
                nodeTimeoutSeconds: $this->nodeTimeout($maxSeconds),
            ));
        } catch (InternalDispatchException $e) {
            return match ($e->reason) {
                InternalDispatchException::REASON_DEADLINE => ToolResult::error(
                    'time_limit',
                    'The tool call exceeded the remaining turn time.',
                ),
                InternalDispatchException::REASON_UNREADABLE => ToolResult::error(
                    'unsupported_response',
                    'This endpoint streams its response and cannot be called as a tool.',
                ),
                default => ToolResult::internalError('Tool calls may not run inside a database transaction.'),
            };
        } catch (\Throwable $e) {
            // Kernel::handle already renders most throwables; anything reaching
            // here is unexpected, so report it and give the model something
            // terse that leaks nothing.
            report($e);

            return ToolResult::internalError('The tool call could not be completed.');
        }
    }

    /**
     * How long one call out to a node may block.
     *
     * The point is that the fifteen-minute archive timeout cannot be inherited
     * by a model that will simply sit there; an overrun becomes a failed tool
     * call the model can route around. Bounded by whatever is left of the turn
     * as well, so the last tool call of a turn does not get a fresh full budget.
     */
    protected function nodeTimeout(?int $remainingSeconds): int
    {
        $ceiling = max(5, AiConfiguration::integer('agent.max_tool_seconds', 90));

        return $remainingSeconds === null ? $ceiling : max(1, min($ceiling, $remainingSeconds));
    }

    protected function toResult(Response $response): ToolResult
    {
        $status = $response->getStatusCode();
        $body = (string) $response->getContent();
        $decoded = json_decode($body, true);
        $isJson = json_last_error() === JSON_ERROR_NONE;

        if ($status >= 200 && $status < 300) {
            // Do not cap here. A JSON response must remain decoded until its
            // tool-specific shaper and privacy redactor have run; the runner
            // applies the final serialized-byte cap after both.
            return ToolResult::ok($isJson ? $decoded : $body);
        }

        return $this->toError($status, $isJson ? $decoded : null);
    }

    protected function toError(int $status, ?array $decoded): ToolResult
    {
        $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];

        // 422 carries one entry per field with the offending rule — genuinely
        // good retry signal, so it is surfaced rather than flattened.
        $fields = null;
        if ($status === 422 && $errors !== []) {
            $fields = [];
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }
                $field = $error['meta']['source_field'] ?? null;
                $fields[$field ?: 'request'] = (string) ($error['detail'] ?? 'Invalid value.');
            }
        }

        return ToolResult::error(
            code: $this->errorCode($status, $first),
            detail: $this->errorDetail($status, $first),
            status: $status,
            // 409 means "start the server first"; 422 means "fix your
            // arguments"; 429/5xx mean "back off". 403/404 mean stop.
            retryable: in_array($status, [409, 422, 429, 500, 502, 503, 504], true),
            fields: $fields,
        );
    }

    protected function errorCode(int $status, array $first): string
    {
        $code = $first['code'] ?? null;

        // The panel renders every exception with `class_basename($e)` as its
        // code, so passing one through puts PHP class names like
        // `DaemonConnectionException` into the model's context and onto the
        // user's screen. Only an already-stable machine code is trusted; a
        // class name is StudlyCase and fails this deliberately.
        if (is_string($code) && preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            return $code;
        }

        return match (true) {
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'rate_limited',
            // The node, not the panel, is what failed. Worth distinguishing:
            // the model should wait and retry rather than rephrase.
            in_array($status, [502, 503, 504], true) => 'node_unavailable',
            default => 'http_error',
        };
    }

    /**
     * Build the message the model sees.
     *
     * Only the detail string is ever read, never the envelope:
     * `convertExceptionToArray()` injects `source.file`, `source.line` and a
     * full `meta.trace` under APP_DEBUG, none of which may reach the model and
     * thence the user's screen over SSE.
     *
     * 5xx details are dropped in every mode. Debug-on they carry SQL, table
     * names and paths; debug-off, controllers that wrap failures leak the same
     * through the same door. None of it is actionable anyway — a 5xx means wait
     * and retry, and the exception is already in the log. Node-raised 5xx use
     * the table below for the same reason.
     */
    protected function errorDetail(int $status, array $first): string
    {
        $detail = $status < 500 ? ($first['detail'] ?? null) : null;

        if (is_string($detail) && ($cleaned = $this->unwrapDaemonMessage($detail)) !== '') {
            return Str::limit($cleaned, 500);
        }

        return match (true) {
            $status === 403 => 'You do not have permission to do that on this server.',
            $status === 404 => 'That does not exist. List the parent directory to see what is actually there rather than guessing another path.',
            $status === 409 => 'The server is not in a state that allows this right now.',
            $status === 429 => 'Too many requests. Wait a moment before trying again.',
            in_array($status, [502, 503, 504], true) => 'The machine running this server is not responding right now.',
            $status >= 500 => 'The panel could not complete that request. It has been logged. Wait a moment and try again, or tell the user it failed.',
            default => 'The request failed with status ' . $status . '.',
        };
    }

    /**
     * Unwrap the daemon's error envelope. `DaemonConnectionException` wraps the
     * node's prose and appends a request id, turning "no such directory" into
     * "An error occurred on the remote host: … (request id: <nil>)" — noise the
     * model reasons past, at a token cost on every later step of the turn.
     */
    protected function unwrapDaemonMessage(string $detail): string
    {
        if (preg_match('/^An error occurred on the remote host: (.+?)\.?\s*\(request id:.*$/is', $detail, $matches)) {
            $detail = $matches[1];
        }

        // The 5xx variant instead carries a trailing "(code: N) (request_id: X)".
        $detail = preg_replace('/\s*\((?:code|request[ _]id):[^)]*\)/i', '', $detail) ?? $detail;

        return trim($detail);
    }
}
