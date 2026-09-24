<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table): void {
            $table->unsignedBigInteger('created_blueprint_id')->nullable()->after('profile_version_id');

            $table->foreign('created_blueprint_id', 'qbp_import_created_blueprint_fk')
                ->references('blueprint_id')
                ->on('question_blueprints')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table): void {
            $table->dropForeign('qbp_import_created_blueprint_fk');
            $table->dropColumn('created_blueprint_id');
        });
    }
};
