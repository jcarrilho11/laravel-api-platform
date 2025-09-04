<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->uuid('user_id');
            $table->string('request_hash');
            $table->json('response_body');
            $table->integer('status_code');
            $table->timestamp('created_at')->useCurrent();
            
            // Adds index for user_id for better query performance
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
