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
        Schema::create('netsuite_msic_codes', function (Blueprint $table) {
            $table->id();
            $table->string('netsuite_id')->comment('Internal ID from NetSuite');
            $table->string('msic_code', 10)->comment('MSIC code (e.g., 00000, 01111)');
            $table->text('description')->comment('Full description');
            $table->string('ref_name')->comment('NetSuite refName (e.g., "00000 : NOT APPLICABLE")');
            $table->boolean('is_sandbox')->default(true)->comment('Whether this is from sandbox or production');
            $table->timestamps();

            // Indexes
            $table->unique(['netsuite_id', 'is_sandbox']);
            $table->index('msic_code');
            $table->index('is_sandbox');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('netsuite_msic_codes');
    }
};
