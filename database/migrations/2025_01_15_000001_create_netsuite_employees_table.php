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
        Schema::create('netsuite_employees', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id');
            $table->string('name');
            $table->string('entity_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('employee_type')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->boolean('is_sandbox')->default(true);
            $table->timestamps();
            
            $table->unique(['netsuite_id', 'is_sandbox']);
            $table->index('netsuite_id');
            $table->index('name');
            $table->index('entity_id');
            $table->index('is_sandbox');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_employees');
    }
};


