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
        Schema::create('budget_sync_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->comment('Unique transaction ID from sheet (e.g., ST2602-00006)');
            $table->string('department_code')->comment('Department Code (BI) from sheet, maps to Name in Kissflow');
            $table->string('source_sheet')->comment('Source sheet name: Others or PO');
            $table->string('subcode')->nullable()->comment('Subcode (GL) from sheet');
            $table->date('transaction_date')->nullable()->comment('Transaction date from sheet');
            $table->string('transaction_type')->nullable()->comment('Transaction Type from sheet');
            $table->string('external_reference')->nullable()->comment('External Reference from sheet');
            $table->text('description')->nullable()->comment('Description from sheet');
            $table->integer('financial_year')->comment('Financial Year from sheet');
            $table->integer('period_number')->comment('Period Number (month) from sheet');
            $table->string('period_name')->nullable()->comment('Period Name from sheet');
            $table->decimal('myr_amount', 15, 2)->comment('MYR Amount from sheet (negative=AP, positive=AR)');
            $table->decimal('currency_amount', 15, 2)->nullable()->comment('Currency Amount from sheet');
            $table->string('currency_code', 10)->nullable()->default('MYR')->comment('Currency Code from sheet');
            $table->decimal('exchange_rate', 15, 6)->nullable()->default(1)->comment('Exchange Rate from sheet');
            $table->string('finance_staff')->nullable()->comment('Finance Staff from sheet');
            $table->string('invoice_id')->nullable()->comment('Invoice ID from sheet');
            $table->string('kissflow_item_id')->nullable()->comment('Kissflow _id for the matched budget record');
            $table->timestamp('synced_at')->nullable()->comment('When this transaction was synced to Kissflow');
            $table->timestamps();

            // Unique constraint to prevent duplicate transactions
            $table->unique(['transaction_id', 'source_sheet'], 'unique_transaction_per_sheet');
            
            // Indexes for common queries
            $table->index('department_code');
            $table->index(['financial_year', 'period_number']);
            $table->index('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_sync_transactions');
    }
};
