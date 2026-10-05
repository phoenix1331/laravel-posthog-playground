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
            $table->string('exit_reason')->nullable()->after('abandoned_at');
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->dropColumn('exit_reason');
        });
    }
};
