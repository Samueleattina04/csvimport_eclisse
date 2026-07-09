<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Il gestionale reale duplica l'intera descrizione del prodotto anche nel
     * campo meta_description (visto su dati veri: supera abbondantemente i
     * 255 caratteri di un VARCHAR), quindi va trattato come testo libero
     * esattamente come body_html, non come un breve meta-tag SEO.
     */
    public function up(): void
    {
        Schema::table('staging_products', function (Blueprint $table) {
            $table->text('meta_description')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->text('meta_description')->nullable()->change();
        });

        Schema::table('import_snapshot_products', function (Blueprint $table) {
            $table->text('meta_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('staging_products', function (Blueprint $table) {
            $table->string('meta_description')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_description')->nullable()->change();
        });

        Schema::table('import_snapshot_products', function (Blueprint $table) {
            $table->string('meta_description')->nullable()->change();
        });
    }
};
