<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Illuminate\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class WriteFileWithDiffRequest extends OverwriteCapableFileRequest
{
    use SanitizesFilePaths;

    /**
     * Cap on the raw request in addition to the decoded field caps.
     *
     * Callers that send `original_hash` put only one copy of the file in the
     * body, so this leaves room for a MAX_CONTENT_BYTES file even in the worst
     * case where JSON escaping doubles it. Callers that still send the whole
     * `original_content` are carrying two copies and will hit this cap first on
     * a large file — which is the reason to prefer the hash.
     */
    public const MAX_REQUEST_BYTES = 10 * 1024 * 1024;

    /**
     * Match the Panel's default editor ceiling while preventing callers from
     * submitting unbounded strings directly to this endpoint.
     */
    public const MAX_CONTENT_BYTES = 4 * 1024 * 1024;

    /**
     * Bound line-oriented work before the diff service ever splits the strings.
     */
    public const MAX_CONTENT_LINES = 20000;

    public const MAX_FILE_PATH_LENGTH = 4096;

    /**
     * Authorize the server action before inspecting an attacker-controlled body,
     * then enforce a hard cap on the raw JSON representation.
     *
     * @throws ValidationException
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $raw = $this->getContent();

        if (strlen($raw) > self::MAX_REQUEST_BYTES) {
            throw ValidationException::withMessages(['content' => sprintf('The request body may not exceed %d bytes.', self::MAX_REQUEST_BYTES)]);
        }

        $this->restoreUntransformedContent($raw);
    }

    /**
     * File contents are bytes, not user-entered form values, so the global
     * TrimStrings and ConvertEmptyStringsToNull middleware must not touch them.
     * Left transformed, they silently corrupt every save: trimming drops the
     * trailing newline and leading indentation from `content`, and the same
     * trim applied to `original_content` breaks the compare-and-swap against
     * the live file (a 409 on any file that ends in a newline), while an empty
     * file arrives as null and fails the `string` rule.
     *
     * Re-read both fields from the untouched JSON body so validation and the
     * controller see exactly what the client sent.
     */
    private function restoreUntransformedContent(string $raw): void
    {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return;
        }

        foreach (['content', 'original_content'] as $attribute) {
            if (array_key_exists($attribute, $decoded) && is_string($decoded[$attribute])) {
                $this->merge([$attribute => $decoded[$attribute]]);
            }
        }
    }

    /**
     * Validation rules for writing a file with diff tracking.
     *
     * The compare-and-swap token may arrive either as a sha256 of the previous
     * contents (`original_hash`, preferred — one copy of the file in the body
     * instead of two) or as the previous contents themselves
     * (`original_content`, which is all a caller without SubtleCrypto can
     * produce). Exactly one is required.
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'string', 'max:' . self::MAX_FILE_PATH_LENGTH],
            'content' => ['bail', 'present', 'string'],
            // Presence of exactly one of these is enforced in withValidator()
            // rather than with required_without, which treats an empty or
            // whitespace-only string as absent — and "" is the legitimate
            // original for an existing empty file.
            'original_hash' => ['bail', 'sometimes', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'original_content' => ['sometimes', 'string'],
        ];
    }

    /**
     * The compare-and-swap token the controller should check the live file
     * against, as a sha256 hex digest. Never trusted as the source for the
     * audit diff — that is always read from the daemon.
     */
    public function originalHash(): string
    {
        $hash = $this->input('original_hash');

        if (is_string($hash) && $hash !== '') {
            return strtolower($hash);
        }

        return hash('sha256', (string) $this->input('original_content'));
    }

    public function withValidator(Validator $validator): void
    {
        $this->sanitizeAbsolutePathField($validator, 'file');

        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            // Key presence, not truthiness: "" is a valid original.
            $hasHash = array_key_exists('original_hash', $data);
            $hasContent = array_key_exists('original_content', $data);

            if ($hasHash && $hasContent) {
                $validator->errors()->add(
                    'original_hash',
                    'Send either the original hash or the original content, not both.'
                );

                return;
            }

            if (!$hasHash && !$hasContent) {
                $validator->errors()->add(
                    'original_hash',
                    'The original hash or the original content is required to confirm what you are replacing.'
                );

                return;
            }

            foreach (['content', 'original_content'] as $attribute) {
                $value = $data[$attribute] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                if (strlen($value) > self::MAX_CONTENT_BYTES) {
                    $validator->errors()->add(
                        $attribute,
                        sprintf('The %s field may not exceed %d bytes.', $attribute, self::MAX_CONTENT_BYTES)
                    );

                    continue;
                }

                $lines = $value === '' ? 0 : substr_count($value, "\n") + 1;
                if ($lines > self::MAX_CONTENT_LINES) {
                    $validator->errors()->add(
                        $attribute,
                        sprintf('The %s field may not exceed %d lines.', $attribute, self::MAX_CONTENT_LINES)
                    );
                }
            }
        });
    }
}
