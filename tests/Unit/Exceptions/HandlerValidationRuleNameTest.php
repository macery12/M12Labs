<?php

namespace Everest\Tests\Unit\Exceptions;

use Everest\Rules\Username;
use Everest\Tests\TestCase;
use Illuminate\Http\Request;
use Everest\Exceptions\Handler;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class HandlerValidationRuleNameTest extends TestCase
{
    /**
     * `meta.rule` used to snake-case a rule object's whole class name, so the
     * namespace leaked into the API with its backslashes intact.
     */
    public function testRuleNamesAreReportedWithoutTheirNamespace(): void
    {
        $validator = Validator::make(
            ['email' => '', 'password' => 'short', 'username' => '!!'],
            [
                'email' => 'required',
                'password' => [Password::min(12)],
                'username' => [new Username()],
            ],
        );

        $response = app(Handler::class)->invalidJson(Request::create('/'), new ValidationException($validator));

        $rules = collect($response->getData(true)['errors'])->pluck('meta.rule', 'meta.source_field')->all();

        $this->assertSame([
            'email' => 'required',
            'password' => 'password',
            'username' => 'p_username',
        ], $rules);
    }
}
