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
        // Add is_sandbox column to all NetSuite reference tables
        $tables = [
            'netsuite_departments',
            'netsuite_accounts',
            'netsuite_currencies',
            'netsuite_locations',
            'netsuite_vendors',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->boolean('is_sandbox')->default(true)->after('is_inactive');
                    $table->index('is_sandbox');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'netsuite_departments',
            'netsuite_accounts',
            'netsuite_currencies',
            'netsuite_locations',
            'netsuite_vendors',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropIndex(['is_sandbox']);
                    $table->dropColumn('is_sandbox');
                });
            }
        }
    }
};
