<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_away_end')->default(false);
            $table->string('price_band');
            $table->unsignedInteger('price_pence');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stands');
    }
};
