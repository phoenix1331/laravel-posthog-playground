<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->timestamp('checkout_started_at')->nullable()->after('travel_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->dropColumn('checkout_started_at');
        });
    }
};
