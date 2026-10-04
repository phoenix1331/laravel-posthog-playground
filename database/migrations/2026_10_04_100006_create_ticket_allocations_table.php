<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stand_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('sold')->default(0);
            $table->timestamps();

            $table->unique(['fixture_id', 'stand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_allocations');
    }
};
