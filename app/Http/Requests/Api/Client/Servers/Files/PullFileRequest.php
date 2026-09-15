<?php

namespace Everest\Http\Requests\Api\Client\Servers\Files;

use Illuminate\Validation\Validator;
use Everest\Http\Requests\Api\Client\Servers\Files\Concerns\SanitizesFilePaths;

class PullFileRequest extends OverwriteCapableFileRequest
{
    use SanitizesFilePaths;

    public function rules(): array
    {
        return [
            // http/https only — the daemon resolves the URL itself and refuses
            // private address space, but there is no reason to forward a
            // file:// or gopher:// scheme to it in the first place.
            'url' => ['required', 'string', 'url:http,https'],
            'directory' => 'sometimes|nullable|string',
            'filename' => 'sometimes|nullable|string',
            'use_header' => 'boolean',
            'foreground' => 'boolean',
        ];
    }

    /**
     * `directory` is where the download lands and `filename` overrides the name
     * derived from the URL, so the latter must stay a bare name — a pull may not
     * write outside the directory it was aimed at.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            try {
                $directory = $this->normalizeRoot($this->input('directory'));
            } catch (\InvalidArgumentException $ex) {
                $validator->errors()->add('directory', $ex->getMessage());

                return;
            }

            $merge = ['directory' => $directory];
            $filename = $this->input('filename');

            if (is_string($filename) && $filename !== '') {
                try {
                    $merge['filename'] = $this->sanitizeFileName($filename);
                } catch (\InvalidArgumentException $ex) {
                    $validator->errors()->add('filename', $ex->getMessage());

                    return;
                }
            }

            $this->merge($merge);
        });
    }
}
