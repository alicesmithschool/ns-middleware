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
        Schema::create('netsuite_items', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id');
            $table->string('name');
            $table->string('item_number')->nullable();
            $table->string('item_type')->nullable(); // inventory, nonInventory, service, etc.
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 4)->nullable();
            $table->string('unit_of_measure')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->boolean('is_sandbox')->default(false);
            $table->timestamps();
            
            $table->unique(['netsuite_id', 'is_sandbox']);
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('item_number');
            $table->index('item_type');
            $table->index('is_sandbox');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_items');
    }
};
