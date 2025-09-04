<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop unintended unique constraint on user_id if it exists, then add a normal index
        DB::statement('ALTER TABLE idempotency_keys DROP CONSTRAINT IF EXISTS idempotency_keys_user_id_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS idempotency_keys_user_id_idx ON idempotency_keys(user_id)');
    }

    public function down(): void
    {
        // Best-effort rollback: drop the non-unique index; do NOT recreate the unique constraint
        DB::statement('DROP INDEX IF EXISTS idempotency_keys_user_id_idx');
    }
};
