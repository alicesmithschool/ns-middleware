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
        Schema::create('journal_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_id')->unique(); // NetSuite PO internal ID
            $table->string('tran_id'); // PO transaction number (e.g., "PO12345")
            $table->date('transaction_date')->nullable();
            $table->string('department_code')->nullable();
            $table->string('subcode')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency_code', 3)->default('MYR');
            $table->text('memo')->nullable();
            $table->json('full_data')->nullable(); // Store complete journal entry data
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();
            
            // Indexes for faster queries
            $table->index('tran_id');
            $table->index('transaction_date');
            $table->index('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_purchase_orders');
    }
};
