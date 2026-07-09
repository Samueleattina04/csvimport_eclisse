<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collega il run al relativo Bus::batch (tabella job_batches) cosi' la
        // dashboard puo' mostrare un progresso live (job completati / totali)
        // mentre lo stato e' "syncing", senza dover ricalcolare nulla.
        Schema::table('import_runs', function (Blueprint $table) {
            $table->string('batch_id')->nullable()->after('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('import_runs', function (Blueprint $table) {
            $table->dropColumn('batch_id');
        });
    }
};
