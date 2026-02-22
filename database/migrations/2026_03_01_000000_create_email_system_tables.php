<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated and defensive email migration.
 * Replaces prior experimental email migrations by creating the final
 * email delivery, attempt, quota, notification, and deferred email tables
 * while safely handling existing/legacy structures. Also adds defensive
 * email verification columns to the users table when missing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Clean up legacy/archived tables from earlier experiments.
        Schema::dropIfExists('email_logs');

        $this->ensureEmailDeliveriesTable();
        $this->ensureEmailDeliveryAttemptsTable();
        $this->ensureDeferredEmailsTable();
        $this->ensureEmailNotificationSettingsTable();
        $this->ensureEmailQuotasTable();
        $this->ensureUserEmailVerificationColumns();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_delivery_attempts');
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('deferred_emails');
        Schema::dropIfExists('email_notification_settings');
        Schema::dropIfExists('email_quotas');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email_verification_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_verification_token');
            });
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('email_verified_at');
            });
        }
    }

    private function ensureEmailDeliveriesTable(): void
    {
        if (!Schema::hasTable('email_deliveries')) {
            Schema::create('email_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->uuid('correlation_id')->unique();
                $table->string('template_key')->nullable();
                $table->string('recipient');
                $table->string('recipient_email')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('subject');
                $table->string('status')->default('queued'); // queued, sent, failed, deferred, blocked, skipped (validated in application layer)
                $table->string('provider')->nullable()->default('resend');
                $table->string('provider_message_id')->nullable();
                $table->json('metadata')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('last_message_id')->nullable();
                $table->unsignedInteger('last_status_code')->nullable();
                $table->text('last_error')->nullable();
                $table->json('tags')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
                $table->index(['user_id', 'created_at']);
                $table->index(['template_key', 'created_at']);
                $table->index(['status', 'created_at']);
                $table->index('recipient');
                $table->index('recipient_email');

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });

            return;
        }

        Schema::table('email_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('email_deliveries', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('email_deliveries', 'correlation_id')) {
                $table->uuid('correlation_id')->nullable()->after('tenant_id');
            }

            if (!Schema::hasColumn('email_deliveries', 'template_key')) {
                $table->string('template_key')->nullable()->after('correlation_id');
            }

            if (!Schema::hasColumn('email_deliveries', 'recipient')) {
                $table->string('recipient')->after('template_key');
            }

            if (!Schema::hasColumn('email_deliveries', 'recipient_email')) {
                $table->string('recipient_email')->nullable()->after('recipient');
            }

            if (!Schema::hasColumn('email_deliveries', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('recipient_email');
            }

            if (!Schema::hasColumn('email_deliveries', 'subject')) {
                $table->string('subject')->after('user_id');
            }

            if (!Schema::hasColumn('email_deliveries', 'status')) {
                $table->string('status')->default('queued')->after('subject');
            }

            if (!Schema::hasColumn('email_deliveries', 'provider')) {
                $table->string('provider')->nullable()->default('resend')->after('status');
            }

            if (!Schema::hasColumn('email_deliveries', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('provider');
            }

            if (!Schema::hasColumn('email_deliveries', 'metadata')) {
                $table->json('metadata')->nullable()->after('provider_message_id');
            }

            if (!Schema::hasColumn('email_deliveries', 'attempts')) {
                $table->unsignedInteger('attempts')->default(0)->after('metadata');
            }

            if (!Schema::hasColumn('email_deliveries', 'last_attempt_at')) {
                $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            }

            if (!Schema::hasColumn('email_deliveries', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('last_attempt_at');
            }

            if (!Schema::hasColumn('email_deliveries', 'last_message_id')) {
                $table->string('last_message_id')->nullable()->after('sent_at');
            }

            if (!Schema::hasColumn('email_deliveries', 'last_status_code')) {
                $table->unsignedInteger('last_status_code')->nullable()->after('last_message_id');
            }

            if (!Schema::hasColumn('email_deliveries', 'last_error')) {
                $table->text('last_error')->nullable()->after('last_status_code');
            }

            if (!Schema::hasColumn('email_deliveries', 'tags')) {
                $table->json('tags')->nullable()->after('last_error');
            }
        });

        // Ensure common indexes exist on existing tables.
        if (!$this->indexExists('email_deliveries', 'email_deliveries_tenant_id_index') && Schema::hasColumn('email_deliveries', 'tenant_id')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index('tenant_id');
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_user_id_created_at_index') && Schema::hasColumn('email_deliveries', 'user_id')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index(['user_id', 'created_at']);
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_template_key_created_at_index') && Schema::hasColumn('email_deliveries', 'template_key')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index(['template_key', 'created_at']);
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_status_created_at_index') && Schema::hasColumn('email_deliveries', 'status')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index(['status', 'created_at']);
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_recipient_index') && Schema::hasColumn('email_deliveries', 'recipient')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index('recipient');
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_recipient_email_index') && Schema::hasColumn('email_deliveries', 'recipient_email')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->index('recipient_email');
            });
        }

        if (!$this->indexExists('email_deliveries', 'email_deliveries_correlation_id_unique') && Schema::hasColumn('email_deliveries', 'correlation_id')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->unique('correlation_id');
            });
        }

        // Simple backfill for newly added optional columns.
        if (Schema::hasColumn('email_deliveries', 'recipient') && Schema::hasColumn('email_deliveries', 'recipient_email')) {
            DB::statement('UPDATE email_deliveries SET recipient_email = recipient WHERE recipient_email IS NULL');
        }

        if (Schema::hasColumn('email_deliveries', 'last_message_id') && Schema::hasColumn('email_deliveries', 'provider_message_id')) {
            DB::statement('UPDATE email_deliveries SET provider_message_id = last_message_id WHERE provider_message_id IS NULL AND last_message_id IS NOT NULL');
        }
    }

    private function ensureEmailDeliveryAttemptsTable(): void
    {
        if (!Schema::hasTable('email_delivery_attempts')) {
            Schema::create('email_delivery_attempts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delivery_id');
                $table->unsignedInteger('attempt_number');
                $table->string('provider')->nullable();
                $table->string('status'); // attempt lifecycle only (sending, sent, failed) distinct from delivery status values
                // Maintain both provider-facing and legacy HTTP status fields for compatibility
                $table->unsignedInteger('response_code')->nullable();
                $table->unsignedInteger('status_code')->nullable();
                $table->string('provider_message_id')->nullable();
                // error_message stores provider/user-friendly messages; error retains legacy tracker detail
                $table->text('error_message')->nullable();
                $table->text('error')->nullable();
                // raw_response captures structured payload; response_payload retains legacy text blob
                $table->json('raw_response')->nullable();
                $table->text('response_payload')->nullable();
                $table->json('request_payload')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->boolean('success')->default(false);
                $table->string('exception_class')->nullable();
                $table->longText('stacktrace')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['delivery_id', 'attempt_number']);
                $table->index('provider_message_id');
                $table->foreign('delivery_id')
                    ->references('id')
                    ->on('email_deliveries')
                    ->onDelete('cascade');
            });

            return;
        }

        Schema::table('email_delivery_attempts', function (Blueprint $table) {
            if (!Schema::hasColumn('email_delivery_attempts', 'provider')) {
                $table->string('provider')->nullable()->after('attempt_number');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'status')) {
                $table->string('status')->after('provider');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'response_code')) {
                $table->unsignedInteger('response_code')->nullable()->after('status');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'status_code')) {
                $table->unsignedInteger('status_code')->nullable()->after('response_code');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'provider_message_id')) {
                $table->string('provider_message_id')->nullable()->after('status_code');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'error_message')) {
                $table->text('error_message')->nullable()->after('provider_message_id');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'error')) {
                $table->text('error')->nullable()->after('error_message');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'raw_response')) {
                $table->json('raw_response')->nullable()->after('error');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'response_payload')) {
                $table->text('response_payload')->nullable()->after('raw_response');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'request_payload')) {
                $table->json('request_payload')->nullable()->after('response_payload');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('request_payload');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('finished_at');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'success')) {
                $table->boolean('success')->default(false)->after('duration_ms');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'exception_class')) {
                $table->string('exception_class')->nullable()->after('success');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'stacktrace')) {
                $table->longText('stacktrace')->nullable()->after('exception_class');
            }

            if (!Schema::hasColumn('email_delivery_attempts', 'created_at')) {
                $table->timestamp('created_at')->useCurrent()->after('stacktrace');
            }
        });

        if (!$this->indexExists('email_delivery_attempts', 'email_delivery_attempts_delivery_id_attempt_number_unique')) {
            Schema::table('email_delivery_attempts', function (Blueprint $table) {
                $table->unique(['delivery_id', 'attempt_number'], 'email_delivery_attempts_delivery_id_attempt_number_unique');
            });
        }

        if (!$this->indexExists('email_delivery_attempts', 'email_delivery_attempts_provider_message_id_index') && Schema::hasColumn('email_delivery_attempts', 'provider_message_id')) {
            Schema::table('email_delivery_attempts', function (Blueprint $table) {
                $table->index('provider_message_id');
            });
        }
    }

    private function ensureDeferredEmailsTable(): void
    {
        if (!Schema::hasTable('deferred_emails')) {
            Schema::create('deferred_emails', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('template_key');
                $table->string('recipient');
                $table->json('data');
                $table->string('correlation_id')->nullable();
                $table->string('reason');
                $table->timestamp('scheduled_at');
                $table->timestamp('sent_at')->nullable();
                $table->integer('attempts')->default(0);
                $table->timestamps();

                $table->index('user_id');
                $table->index('scheduled_at');
                $table->index(['user_id', 'scheduled_at']);
                $table->index('sent_at');
            });

            return;
        }

        Schema::table('deferred_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('deferred_emails', 'correlation_id')) {
                $table->string('correlation_id')->nullable()->after('data');
            }

            if (!Schema::hasColumn('deferred_emails', 'reason')) {
                $table->string('reason')->after('correlation_id');
            }

            if (!Schema::hasColumn('deferred_emails', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('scheduled_at');
            }

            if (!Schema::hasColumn('deferred_emails', 'attempts')) {
                $table->integer('attempts')->default(0)->after('sent_at');
            }
        });
    }

    private function ensureEmailNotificationSettingsTable(): void
    {
        if (!Schema::hasTable('email_notification_settings')) {
            Schema::create('email_notification_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('template_key')->unique();
                $table->boolean('enabled')->default(true);
                $table->string('category')->default('general')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('rate_limit_exempt')->default(false);
                $table->timestamps();

                $table->index('tenant_id');
                $table->index(['category', 'enabled']);
            });
        } else {
            Schema::table('email_notification_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('email_notification_settings', 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                }

                if (!Schema::hasColumn('email_notification_settings', 'template_key')) {
                    $table->string('template_key')->after('tenant_id');
                }

                if (!Schema::hasColumn('email_notification_settings', 'enabled')) {
                    $table->boolean('enabled')->default(true)->after('template_key');
                }

                if (!Schema::hasColumn('email_notification_settings', 'category')) {
                    $table->string('category')->default('general')->after('enabled');
                }

                if (!Schema::hasColumn('email_notification_settings', 'name')) {
                    $table->string('name')->after('category');
                }

                if (!Schema::hasColumn('email_notification_settings', 'description')) {
                    $table->text('description')->nullable()->after('name');
                }

                if (!Schema::hasColumn('email_notification_settings', 'rate_limit_exempt')) {
                    $table->boolean('rate_limit_exempt')->default(false)->after('description');
                }
            });

            if (!$this->indexExists('email_notification_settings', 'email_notification_settings_tenant_id_index') && Schema::hasColumn('email_notification_settings', 'tenant_id')) {
                Schema::table('email_notification_settings', function (Blueprint $table) {
                    $table->index('tenant_id');
                });
            }

            if (!$this->indexExists('email_notification_settings', 'email_notification_settings_category_enabled_index') && Schema::hasColumn('email_notification_settings', 'category')) {
                Schema::table('email_notification_settings', function (Blueprint $table) {
                    $table->index(['category', 'enabled']);
                });
            }

            if (!$this->indexExists('email_notification_settings', 'email_notification_settings_template_key_unique') && Schema::hasColumn('email_notification_settings', 'template_key')) {
                $hasDuplicates = DB::table('email_notification_settings')
                    ->select('template_key', DB::raw('COUNT(*) as total'))
                    ->groupBy('template_key')
                    ->havingRaw('COUNT(*) > 1')
                    ->exists();

                if (!$hasDuplicates) {
                    Schema::table('email_notification_settings', function (Blueprint $table) {
                        $table->unique('template_key');
                    });
                }
            }
        }

        $this->seedNotificationDefaults();
    }

    private function ensureEmailQuotasTable(): void
    {
        $today = now()->toDateString();

        if (!Schema::hasTable('email_quotas')) {
            Schema::create('email_quotas', function (Blueprint $table) use ($today) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                // One quota row per user; tenant_id is available for future scoping
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('plan')->default('free');
                $table->integer('monthly_limit')->default(3000);
                $table->integer('daily_limit')->nullable()->default(100);
                // Legacy counters kept alongside consolidated naming for compatibility
                $table->integer('monthly_sent')->default(0);
                $table->integer('daily_sent')->default(0);
                $table->integer('day_sent_count')->default(0);
                $table->integer('month_sent_count')->default(0);
                $table->integer('monthly_overage')->default(0);
                $table->integer('overage_count')->default(0); // consolidated alias for monthly_overage
                $table->date('month_reset_at')->default($today);
                $table->date('day_reset_at')->default($today);
                $table->string('period_month', 7)->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index(['user_id', 'plan']);
                $table->index('month_reset_at');
                $table->index('day_reset_at');
                $table->index('tenant_id');
                $table->index('period_month');
            });

            return;
        }

        Schema::table('email_quotas', function (Blueprint $table) {
            if (!Schema::hasColumn('email_quotas', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('email_quotas', 'period_month')) {
                $table->string('period_month', 7)->nullable()->after('day_reset_at');
            }

            if (!Schema::hasColumn('email_quotas', 'overage_count')) {
                $table->integer('overage_count')->default(0)->after('monthly_overage');
            }

            if (!Schema::hasColumn('email_quotas', 'day_sent_count')) {
                $table->integer('day_sent_count')->default(0)->after('daily_sent');
            }

            if (!Schema::hasColumn('email_quotas', 'month_sent_count')) {
                $table->integer('month_sent_count')->default(0)->after('day_sent_count');
            }
        });

        if (!$this->indexExists('email_quotas', 'email_quotas_tenant_id_index') && Schema::hasColumn('email_quotas', 'tenant_id')) {
            Schema::table('email_quotas', function (Blueprint $table) {
                $table->index('tenant_id');
            });
        }

        if (!$this->indexExists('email_quotas', 'email_quotas_period_month_index') && Schema::hasColumn('email_quotas', 'period_month')) {
            Schema::table('email_quotas', function (Blueprint $table) {
                $table->index('period_month');
            });
        }

        if (Schema::hasColumn('email_quotas', 'monthly_sent') && Schema::hasColumn('email_quotas', 'month_sent_count')) {
            DB::statement('UPDATE email_quotas SET month_sent_count = monthly_sent WHERE month_sent_count = 0 AND monthly_sent > 0');
        }

        if (Schema::hasColumn('email_quotas', 'daily_sent') && Schema::hasColumn('email_quotas', 'day_sent_count')) {
            DB::statement('UPDATE email_quotas SET day_sent_count = daily_sent WHERE day_sent_count = 0 AND daily_sent > 0');
        }

        if (Schema::hasColumn('email_quotas', 'monthly_overage') && Schema::hasColumn('email_quotas', 'overage_count')) {
            DB::statement('UPDATE email_quotas SET overage_count = monthly_overage WHERE overage_count = 0 AND monthly_overage > 0');
        }
    }

    private function ensureUserEmailVerificationColumns(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'email_verification_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email_verification_token', 100)->nullable()->after('email_verified_at');
            });
        }
    }

    private function seedNotificationDefaults(): void
    {
        if (!Schema::hasTable('email_notification_settings')) {
            return;
        }

        $existingKeys = DB::table('email_notification_settings')
            ->pluck('template_key')
            ->all();

        $defaults = [
            // Auth/Security emails
            [
                'template_key' => 'auth.account_created',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Created',
                'description' => 'Welcome email sent when a new account is created',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.email_verification',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Email Verification',
                'description' => 'Email verification link sent to new users',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.password_reset',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Password Reset Request',
                'description' => 'Password reset link sent when requested',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.password_changed',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Password Successfully Changed',
                'description' => 'Confirmation email after password change',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.new_login',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'New Login Detected',
                'description' => 'Alert for new login from unrecognized device/location',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.account_locked',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Locked/Suspended',
                'description' => 'Notification when account is locked or suspended',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.account_unsuspended',
                'enabled' => true,
                'category' => 'auth',
                'name' => 'Account Unsuspended',
                'description' => 'Notification when account is unsuspended',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.2fa_enabled',
                'enabled' => true,
                'category' => 'auth',
                'name' => '2FA Enabled',
                'description' => 'Confirmation when two-factor authentication is enabled',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'auth.2fa_disabled',
                'enabled' => true,
                'category' => 'auth',
                'name' => '2FA Disabled',
                'description' => 'Alert when two-factor authentication is disabled',
                'rate_limit_exempt' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Server emails
            [
                'template_key' => 'server.created',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Created',
                'description' => 'Notification when a new server is created',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.suspended',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Suspended',
                'description' => 'Notification when a server is suspended',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.unsuspended',
                'enabled' => true,
                'category' => 'server',
                'name' => 'Server Unsuspended',
                'description' => 'Notification when a server is unsuspended',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'server.expiring_soon',
                'enabled' => false,
                'category' => 'server',
                'name' => 'Server Expiring Soon',
                'description' => 'Alert when server is approaching expiration',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            // Billing emails
            [
                'template_key' => 'billing.payment_received',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Payment Received',
                'description' => 'Confirmation when payment is successfully processed',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'billing.payment_failed',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Payment Failed',
                'description' => 'Alert when a payment attempt fails',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'template_key' => 'billing.server_renewal_notice',
                'enabled' => true,
                'category' => 'billing',
                'name' => 'Server Renewal Notice',
                'description' => 'Reminder before server expires with renewal link and suspension time',
                'rate_limit_exempt' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $toInsert = array_filter($defaults, function (array $setting) use ($existingKeys) {
            return !in_array($setting['template_key'], $existingKeys, true);
        });

        if (!empty($toInsert)) {
            DB::table('email_notification_settings')->insert($toInsert);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $schemaManager = $connection->getDoctrineSchemaManager();
            $tablePrefix = $connection->getTablePrefix();
            $indexes = $schemaManager->listTableIndexes($tablePrefix . $table);

            return array_key_exists($indexName, $indexes);
        } catch (\Throwable $e) {
            // If we cannot inspect indexes, assume they exist to avoid duplicate creation errors.
            return true;
        }
    }
};
