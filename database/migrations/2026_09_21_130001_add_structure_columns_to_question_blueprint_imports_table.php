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
        Schema::table('question_blueprint_imports', function (Blueprint $table) {
            $table->longText('structured_document')->nullable()->after('extracted_text');
            $table->string('structure_schema_version', 64)->nullable()->after('structured_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table) {
            $table->dropColumn([
                'structured_document',
                'structure_schema_version',
            ]);
        });
    }
};
