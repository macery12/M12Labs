<?php

namespace Everest\Http\Controllers\Api\Application;

use Everest\Models\EmailLog;
use Everest\Models\DeferredEmail;
use Everest\Models\User;
use Everest\Services\Email\EmailManager;
use Everest\Facades\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailActivityController extends ApplicationApiController
{
    public function __construct(private EmailManager $emailManager)
    {
        parent::__construct();
    }

    /**
     * Get email activity logs with pagination and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);
        
        $query = EmailLog::query()->with('user:id,email,username');

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('template_key')) {
            $query->where('template_key', $request->input('template_key'));
        }

        if ($request->filled('recipient')) {
            $query->where('to', 'like', '%' . $request->input('recipient') . '%');
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->has('only_failures') && $request->input('only_failures') === 'true') {
            $query->where('success', false);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        // Default date range: last 7 days
        if (!$request->has('date_from') && !$request->has('date_to')) {
            $query->where('created_at', '>=', now()->subDays(7));
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        
        if (in_array($sortBy, ['created_at', 'status', 'template_key', 'to'])) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * Get details of a specific email log entry.
     */
    public function show(int $id): JsonResponse
    {
        $log = EmailLog::with('user:id,email,username')->findOrFail($id);

        // Get retry history from metadata if available
        $retryHistory = $log->metadata['retry_history'] ?? [];

        // Get related emails by correlation_id
        $relatedEmails = [];
        if ($log->correlation_id) {
            $relatedEmails = EmailLog::where('correlation_id', $log->correlation_id)
                ->where('id', '!=', $log->id)
                ->select('id', 'to', 'subject', 'template_key', 'status', 'created_at')
                ->get();
        }

        return response()->json([
            'log' => $log,
            'sanitized_variables' => $log->getSanitizedVariables(),
            'retry_history' => $retryHistory,
            'related_emails' => $relatedEmails,
        ]);
    }

    /**
     * Resend a failed email.
     */
    public function resend(int $id): JsonResponse
    {
        $log = EmailLog::findOrFail($id);

        if ($log->success) {
            return response()->json([
                'success' => false,
                'error' => 'Cannot resend a successful email',
            ], 400);
        }

        try {
            // Re-send using the original template variables
            $variables = $log->template_variables ?? [];
            
            // If we have rendered content, use custom email
            if ($log->rendered_html) {
                $result = $this->emailManager->sendCustom(
                    to: $log->to,
                    subject: $log->rendered_subject ?? $log->subject,
                    html: $log->rendered_html,
                    text: $log->rendered_text
                );
            } else {
                // Cannot resend without content
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot resend: original email content not available',
                ], 400);
            }

            Activity::event('admin:email:resend')
                ->property('original_log_id', $log->id)
                ->property('to', $log->to)
                ->property('template_key', $log->template_key)
                ->description("Resent failed email (original log #{$log->id})")
                ->log();

            return response()->json([
                'success' => true,
                'message' => 'Email queued for resending',
                'message_id' => $result->messageId ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get deferred email queue.
     */
    public function getDeferredQueue(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = DeferredEmail::query()
            ->whereNull('sent_at')
            ->with('user:id,email,username');

        // Filter by due status
        if ($request->input('status') === 'due') {
            $query->where('scheduled_at', '<=', now());
        } else if ($request->input('status') === 'pending') {
            $query->where('scheduled_at', '>', now());
        }

        $query->orderBy('scheduled_at');

        $deferred = $query->paginate($perPage);

        // Get stats
        $stats = [
            'total_queued' => DeferredEmail::whereNull('sent_at')->count(),
            'due_now' => DeferredEmail::whereNull('sent_at')->where('scheduled_at', '<=', now())->count(),
            'next_send_time' => DeferredEmail::whereNull('sent_at')
                ->where('scheduled_at', '>', now())
                ->min('scheduled_at'),
        ];

        return response()->json([
            'deferred' => $deferred,
            'stats' => $stats,
        ]);
    }

    /**
     * Send a deferred email immediately.
     */
    public function sendDeferredNow(int $id): JsonResponse
    {
        $deferred = DeferredEmail::findOrFail($id);

        if ($deferred->sent_at) {
            return response()->json([
                'success' => false,
                'error' => 'Email already sent',
            ], 400);
        }

        try {
            // Send the email using EmailManager
            // This would typically dispatch a job
            $deferred->incrementAttempts();
            $deferred->scheduled_at = now(); // Move to front of queue
            $deferred->save();

            Activity::event('admin:email:deferred:send-now')
                ->property('deferred_id', $deferred->id)
                ->property('recipient', $deferred->recipient)
                ->property('template_key', $deferred->template_key)
                ->description("Manually triggered deferred email #{$deferred->id}")
                ->log();

            return response()->json([
                'success' => true,
                'message' => 'Email scheduled for immediate sending',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel a deferred email.
     */
    public function cancelDeferred(int $id): JsonResponse
    {
        $deferred = DeferredEmail::findOrFail($id);

        if ($deferred->sent_at) {
            return response()->json([
                'success' => false,
                'error' => 'Email already sent, cannot cancel',
            ], 400);
        }

        $deferred->delete();

        Activity::event('admin:email:deferred:cancel')
            ->property('deferred_id', $id)
            ->property('recipient', $deferred->recipient)
            ->property('template_key', $deferred->template_key)
            ->description("Cancelled deferred email #{$id}")
            ->log();

        return response()->json([
            'success' => true,
            'message' => 'Deferred email cancelled',
        ]);
    }

    /**
     * Get aggregated statistics for email activity.
     */
    public function getStats(Request $request): JsonResponse
    {
        $days = min((int) $request->input('days', 7), 90);
        $since = now()->subDays($days);

        $stats = [
            'total_sent' => EmailLog::where('created_at', '>=', $since)->count(),
            'successful' => EmailLog::where('created_at', '>=', $since)->where('success', true)->count(),
            'failed' => EmailLog::where('created_at', '>=', $since)->where('success', false)->count(),
            'by_status' => EmailLog::where('created_at', '>=', $since)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_template' => EmailLog::where('created_at', '>=', $since)
                ->selectRaw('template_key, COUNT(*) as count')
                ->groupBy('template_key')
                ->orderByDesc('count')
                ->limit(10)
                ->pluck('count', 'template_key'),
            'deferred_count' => DeferredEmail::whereNull('sent_at')->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Get all unique template keys for filtering.
     */
    public function getTemplateKeys(): JsonResponse
    {
        $templateKeys = EmailLog::distinct()
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->sort()
            ->values();

        return response()->json(['template_keys' => $templateKeys]);
    }
}
