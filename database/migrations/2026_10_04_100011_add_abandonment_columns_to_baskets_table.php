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
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->timestamp('abandoned_at')->nullable()->after('checkout_started_at');

            $table->index(['abandoned_at', 'checkout_started_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table) {
            $table->dropIndex(['abandoned_at', 'checkout_started_at', 'updated_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('abandoned_at');
        });
    }
};
