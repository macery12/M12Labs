<?php

namespace Everest\Http\Controllers\Api\Application\Alerts;

use Ramsey\Uuid\Uuid;
use Everest\Models\Alert;
use Everest\Models\Setting;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Everest\Http\Controllers\Api\Application\ApplicationApiController;
use Everest\Http\Requests\Api\Application\Alerts\CreateAlertRequest;
use Everest\Http\Requests\Api\Application\Alerts\UpdateAlertRequest;
use Everest\Http\Requests\Api\Application\Alerts\DeleteAlertRequest;
use Everest\Http\Requests\Api\Application\Alerts\GetAlertsRequest;
use Everest\Http\Requests\Api\Application\Alerts\UpdateAlertSettingsRequest;

class AlertController extends ApplicationApiController
{
    /**
     * AlertController constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get all alerts.
     */
    public function index(GetAlertsRequest $request): JsonResponse
    {
        $alerts = Alert::orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get();

        return new JsonResponse($alerts);
    }

    /**
     * Get active alerts for display to users.
     */
    public function active(): JsonResponse
    {
        $alerts = Alert::active()->get();

        return new JsonResponse($alerts);
    }

    /**
     * Create a new alert.
     */
    public function store(CreateAlertRequest $request): JsonResponse
    {
        $alert = Alert::create($request->validated());

        Activity::event('admin:alert:create')
            ->property('alert_id', $alert->id)
            ->property('data', $alert->toArray())
            ->description('Created a new alert')
            ->log();

        return new JsonResponse($alert, 201);
    }

    /**
     * Update an existing alert.
     */
    public function updateAlert(UpdateAlertRequest $request, Alert $alert): JsonResponse
    {
        $alert->update($request->validated());

        Activity::event('admin:alert:update')
            ->property('alert_id', $alert->id)
            ->property('data', $alert->toArray())
            ->description('Updated alert')
            ->log();

        return new JsonResponse($alert);
    }

    /**
     * Delete an alert.
     */
    public function destroy(DeleteAlertRequest $request, Alert $alert): JsonResponse
    {
        Activity::event('admin:alert:delete')
            ->property('alert_id', $alert->id)
            ->description('Deleted alert')
            ->log();

        $alert->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * Update the general alert settings on the Panel (legacy support).
     *
     * @throws \Throwable
     */
    public function update(UpdateAlertSettingsRequest $request): JsonResponse
    {
        $uuid = Uuid::uuid4()->toString();

        Setting::set('settings::modules:alert:uuid', $uuid);

        foreach ($request->normalize() as $key => $value) {
            Setting::set('settings::modules:alert:' . $key, $value);
        }

        Activity::event('admin:alert:update')
            ->property('settings', $request->all())
            ->description('Alert system was updated with new data')
            ->log();

        return new JsonResponse($uuid);
    }
}
