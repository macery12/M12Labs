<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Facades\Activity;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Everest\Models\ExtensionPackage;
use Everest\Models\EmailNotificationSetting;
use Everest\Services\Email\ExtensionMailLimiter;
use Everest\Services\Email\Templating\EmailTemplateRenderer;
use Everest\Services\Extensions\ExtensionRuntimePlanService;
use Everest\Services\Email\Templating\EmailTemplateOverrides;
use Everest\Services\Email\Templating\EmailTemplateStorageException;
use Everest\Services\Extensions\Manifest\Definitions\EmailDefinition;
use Everest\Http\Requests\Api\Application\Email\RevertEmailTemplateRequest;
use Everest\Http\Requests\Api\Application\Email\PreviewEmailTemplateRequest;
use Everest\Services\Extensions\Manifest\Definitions\EmailVariableDefinition;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailTemplateSourceRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateExtensionEmailLimitRequest;
use Everest\Http\Requests\Api\Application\Email\GetEmailNotificationSettingsRequest;
use Everest\Http\Requests\Api\Application\Email\UpdateEmailNotificationSettingRequest;

/**
 * Admin → Email → Extension emails: what each installed extension may send,
 * with a switch and an editable template per type, and the extension's hourly
 * ceiling.
 *
 * Reads installed packages rather than the runtime plan, so a disabled
 * extension's types can still be reviewed and edited before it is switched
 * back on. Sending goes by the plan (see Sdk\Services\PackageMail).
 */
class EmailExtensionController extends ApplicationApiController
{
    /** Shown in the editor beside the package's own variables. */
    private const PROVIDED_VARIABLES = [
        ['name' => 'userName', 'description' => "Recipient's username", 'example' => 'Jane Smith', 'required' => true, 'provided' => true],
        ['name' => 'userEmail', 'description' => "Recipient's email address", 'example' => 'jane@example.com', 'required' => true, 'provided' => true],
    ];

    public function __construct(
        private ExtensionRuntimePlanService $plan,
        private EmailTemplateRenderer $renderer,
        private EmailTemplateOverrides $overrides,
        private ExtensionMailLimiter $limiter,
    ) {
        parent::__construct();
    }

    public function index(GetEmailNotificationSettingsRequest $request): JsonResponse
    {
        $toggles = EmailNotificationSetting::query()
            ->where('template_key', 'like', 'ext:%')
            ->pluck('enabled', 'template_key');
        $active = $this->plan->plan();
        $extensions = [];

        foreach (ExtensionPackage::query()->orderBy('name')->get() as $package) {
            $emails = $this->plan->hydrateCapabilities($package->capabilities)->emails ?? [];

            if ($emails === []) {
                continue;
            }

            $extensions[] = [
                'id' => $package->extension_id,
                'name' => $package->name,
                'icon' => $package->icon,
                'active' => isset($active[$package->extension_id]),
                'hourly_limit' => $this->limiter->limitFor($package->extension_id),
                'sent_this_hour' => $this->limiter->used($package->extension_id),
                'types' => array_map(fn (EmailDefinition $email): array => [
                    'type' => $email->type,
                    'key' => $email->key($package->extension_id),
                    'label_key' => $email->labelKey,
                    'description_key' => $email->descriptionKey,
                    'subject' => $email->subject,
                    'variables' => $this->variables($email),
                    'enabled' => (bool) ($toggles[$email->key($package->extension_id)] ?? true),
                    'is_customized' => $this->overrides->exists(EmailTemplateRenderer::extensionView($package->extension_id, $email->type)),
                ], $emails),
            ];
        }

        return response()->json([
            'extensions' => $extensions,
            'default_hourly_limit' => ExtensionMailLimiter::DEFAULT_PER_HOUR,
        ]);
    }

    public function updateLimit(UpdateExtensionEmailLimitRequest $request, string $extension): JsonResponse
    {
        $package = $this->package($extension);
        $limit = $request->integer('hourly_limit');

        $this->limiter->setLimit($package->extension_id, $limit);

        Activity::event('admin:email:extension-limit:update')
            ->property('extension', $package->extension_id)
            ->property('hourly_limit', $limit)
            ->description("Hourly email limit for extension '{$package->name}' set to {$limit}")
            ->log();

        return response()->json([
            'id' => $package->extension_id,
            'hourly_limit' => $this->limiter->limitFor($package->extension_id),
        ]);
    }

    /**
     * Switch one type on or off. Extension types have no seeded row, so the
     * first switch creates it.
     */
    public function toggle(UpdateEmailNotificationSettingRequest $request, string $extension, string $type): JsonResponse
    {
        [$package, $email] = $this->definition($extension, $type);
        $enabled = $request->boolean('enabled');
        $key = $email->key($package->extension_id);

        EmailNotificationSetting::query()->updateOrCreate(['template_key' => $key], [
            'enabled' => $enabled,
            'category' => 'extension',
            'name' => mb_substr($package->name . ': ' . $email->type, 0, 191),
        ]);

        Activity::event('admin:email:notifications:toggle')
            ->property('template_key', $key)
            ->property('enabled', $enabled)
            ->description("Extension email '{$key}' " . ($enabled ? 'enabled' : 'disabled'))
            ->log();

        return response()->json(['key' => $key, 'enabled' => $enabled]);
    }

    public function preview(PreviewEmailTemplateRequest $request, string $extension, string $type): Response
    {
        [$package, $email] = $this->definition($extension, $type);
        $view = EmailTemplateRenderer::extensionView($package->extension_id, $email->type);

        if ($this->renderer->packageSource($package->extension_id, $email->type) === null && !$this->overrides->exists($view)) {
            abort(404, 'Template file not found on disk.');
        }

        return response($this->renderer->render($view, $this->sampleData($email)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    public function source(PreviewEmailTemplateRequest $request, string $extension, string $type): JsonResponse
    {
        [$package, $email] = $this->definition($extension, $type);
        $custom = $this->overrides->read(EmailTemplateRenderer::extensionView($package->extension_id, $email->type));
        $content = $custom ?? $this->renderer->packageSource($package->extension_id, $email->type);

        if ($content === null) {
            abort(404, 'Template file not found on disk.');
        }

        return response()->json([
            'key' => $email->key($package->extension_id),
            'content' => $content,
            'is_customized' => $custom !== null,
        ]);
    }

    public function update(UpdateEmailTemplateSourceRequest $request, string $extension, string $type): JsonResponse
    {
        [$package, $email] = $this->definition($extension, $type);
        $view = EmailTemplateRenderer::extensionView($package->extension_id, $email->type);
        $content = (string) $request->input('content');

        // Rendered with sample data before it is saved, as for a built-in
        // template: a broken override would otherwise fail every send of it.
        $error = $this->renderer->validate($view, $content, $this->sampleData($email));

        if ($error !== null) {
            return response()->json([
                'errors' => [[
                    'code' => 'InvalidEmailTemplate',
                    'status' => '422',
                    'detail' => $error,
                ]],
            ], 422);
        }

        try {
            $this->overrides->save($view, $content);
        } catch (EmailTemplateStorageException $e) {
            abort($e->getCode(), $e->getMessage());
        }

        Activity::event('admin:email:template:update')
            ->property('template_key', $email->key($package->extension_id))
            ->description("Email template '{$email->key($package->extension_id)}' was customized")
            ->log();

        return response()->json(['key' => $email->key($package->extension_id), 'is_customized' => true]);
    }

    public function revert(RevertEmailTemplateRequest $request, string $extension, string $type): JsonResponse
    {
        [$package, $email] = $this->definition($extension, $type);

        try {
            $reverted = $this->overrides->revert(EmailTemplateRenderer::extensionView($package->extension_id, $email->type));
        } catch (EmailTemplateStorageException $e) {
            abort($e->getCode(), $e->getMessage());
        }

        if ($reverted) {
            Activity::event('admin:email:template:revert')
                ->property('template_key', $email->key($package->extension_id))
                ->description("Email template '{$email->key($package->extension_id)}' was reverted to the extension's own")
                ->log();
        }

        return response()->json(['key' => $email->key($package->extension_id), 'is_customized' => false]);
    }

    private function package(string $extension): ExtensionPackage
    {
        $package = ExtensionPackage::query()->where('extension_id', $extension)->first();

        if ($package === null) {
            abort(404, 'Extension not installed.');
        }

        return $package;
    }

    /**
     * @return array{0: ExtensionPackage, 1: EmailDefinition}
     */
    private function definition(string $extension, string $type): array
    {
        $package = $this->package($extension);
        $email = $this->plan->hydrateCapabilities($package->capabilities)?->email($type);

        if ($email === null) {
            abort(404, 'This extension does not declare that email type.');
        }

        return [$package, $email];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variables(EmailDefinition $email): array
    {
        return array_merge(self::PROVIDED_VARIABLES, array_map(fn (EmailVariableDefinition $variable): array => [
            'name' => $variable->name,
            'description' => $variable->description,
            'example' => $variable->example,
            'required' => $variable->required,
        ], $email->variables));
    }

    /**
     * @return array<string, string>
     */
    private function sampleData(EmailDefinition $email): array
    {
        $data = [];

        foreach ($this->variables($email) as $variable) {
            $data[$variable['name']] = (string) $variable['example'];
        }

        return $data;
    }
}
