<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baskets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_allocation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->foreignId('travel_option_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baskets');
    }
};
