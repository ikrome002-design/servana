<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_profiles — 1:1 with merchants (Plan §7.1, Scope §3.2/§5.1).
 *
 * Holds the editable business profile completed during first-time setup. A shell
 * row is created at registration (so the 1:1 always exists) and filled in step 2
 * of the wizard. `logo_path` is a private S3 key — the upload pipeline is owned
 * by Phase 23, so Phase 6 stores only the metadata column (no upload route yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->unique()->constrained('merchants')->cascadeOnDelete();
            $table->string('business_category', 80)->nullable();
            // Private S3 object key (Phase 23 upload pipeline). Metadata only now.
            $table->string('logo_path', 255)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            // Name shown on client invoices/receipts (Plan §17, Scope receipts).
            $table->string('receipt_display_name', 160)->nullable();
            $table->string('address')->nullable();
            $table->string('town', 80)->nullable();
            $table->char('country', 2)->default('KE');
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_profiles');
    }
};
