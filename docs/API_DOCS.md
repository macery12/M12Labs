# API documentation

- Runtime docs are enabled by default outside production; set `API_DOCS_ENABLED=true` (or `false`) to override explicitly and optionally `API_DOCS_ADMIN_ONLY=false` to loosen access. Ensure Composer dependencies are installed so the `scramble` views are available.
- The generated OpenAPI spec is available at `/api/openapi.json` and rendered Swagger UI at `/api/docs`.
- Scramble builds schemas automatically from Laravel FormRequests, validation rules, controller signatures, route parameters, and API Resources/JsonResponses.
- Tags are derived from the `/api/{segment}/...` prefix (or controller namespace when no prefix is present). Remote daemon routes stay hidden unless `API_DOCS_INCLUDE_REMOTE=true`.
- The spec is cached (defaults to the `array` store). Append `?refresh=1` to `/api/openapi.json` to regenerate.
- If inference ever needs help, add proper return types on controller methods, keep Resources/FormRequests in sync with responses, or add PHPDoc comments describing response shapes on the relevant controller methods.
