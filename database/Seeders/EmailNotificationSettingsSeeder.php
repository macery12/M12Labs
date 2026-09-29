<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Everest\Services\Email\EmailType;
use Everest\Services\Email\EmailCatalogue;

/**
 * Default per-template email notification toggles, one per built-in type in
 * EmailCatalogue.
 * Idempotent: only inserts template keys that don't exist yet, so admin
 * changes to enabled survive re-seeding.
 */
class EmailNotificationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $existingKeys = DB::table('email_notification_settings')->pluck('template_key')->all();

        $defaults = array_map(fn (EmailType $type) => [
            'template_key' => $type->key,
            'enabled' => true,
            'category' => $type->category,
            'name' => $type->name,
            'description' => $type->description,
        ], array_values(EmailCatalogue::all()));

        $now = now();
        $insert = [];
        foreach ($defaults as $row) {
            if (!in_array($row['template_key'], $existingKeys, true)) {
                $row['created_at'] = $now;
                $row['updated_at'] = $now;
                $insert[] = $row;
            }
        }

        if (!empty($insert)) {
            DB::table('email_notification_settings')->insert($insert);
        }

        $this->command->info(sprintf(
            'Added %d missing email notification settings; found %d existing settings.',
            count($insert),
            count($defaults) - count($insert),
        ));

        if ($insert !== []) {
            $this->command->comment('Missing email notification settings added:');
            foreach ($insert as $row) {
                $this->command->line('  + ' . $row['template_key']);
            }
        }
    }
}
