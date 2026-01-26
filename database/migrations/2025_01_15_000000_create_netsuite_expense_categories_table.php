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
        Schema::create('netsuite_expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->boolean('is_sandbox')->default(true);
            $table->timestamps();
            
            $table->unique(['netsuite_id', 'is_sandbox']);
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('is_sandbox');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_expense_categories');
    }
};


