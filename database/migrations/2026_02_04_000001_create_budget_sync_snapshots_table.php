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
        Schema::create('budget_sync_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('department_code')->comment('Department Code, maps to Name in Kissflow');
            $table->string('kissflow_item_id')->comment('Kissflow _id for the budget record');
            $table->integer('financial_year')->comment('Financial Year');
            $table->integer('period_number')->comment('Period Number (month) that was synced');
            $table->string('period_name')->nullable()->comment('Period Name');
            $table->decimal('previous_value', 15, 2)->nullable()->comment('Budget_Spent value before sync');
            $table->decimal('synced_amount', 15, 2)->comment('Amount that was added in this sync');
            $table->decimal('new_value', 15, 2)->comment('Budget_Spent value after sync');
            $table->integer('transaction_count')->default(0)->comment('Number of transactions included');
            $table->json('transaction_ids')->nullable()->comment('Array of transaction IDs included in this sync');
            $table->timestamp('synced_at')->comment('When this sync was performed');
            $table->timestamps();

            // Indexes for common queries
            $table->index('department_code');
            $table->index(['financial_year', 'period_number']);
            $table->index('synced_at');
            $table->index('kissflow_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_sync_snapshots');
    }
};
