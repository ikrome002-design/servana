<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * branch_operating_hours — weekly schedule, one row per weekday per branch
 * (Plan §7.2, Scope §3.3 Branch Operating Calendar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_operating_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->smallInteger('weekday'); // 0=Sunday … 6=Saturday
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_operating_hours');
    }
};
