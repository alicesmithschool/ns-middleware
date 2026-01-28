<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_purchase_orders', function (Blueprint $table) {
            $table->timestamp('netsuite_last_modified')->nullable()->after('processed_at');
            $table->index('netsuite_last_modified');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_purchase_orders', function (Blueprint $table) {
            $table->dropColumn('netsuite_last_modified');
        });
    }
};
