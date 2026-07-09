<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visto su dati reali: il gestionale puo' riportare una QUANTITA' fuori
     * scala (2147483648 = 2^31, tipico segno di un overflow su 32 bit dal
     * lato loro), che sfora un integer con segno standard (max 2147483647).
     * quantity_raw deve poter contenere qualunque valore grezzo arrivi dal
     * CSV, negativo o assurdamente grande, senza mai far fallire l'insert:
     * e' un campo di sola registrazione/debug, il clamping per l'uso reale
     * resta sul campo "quantity".
     */
    public function up(): void
    {
        Schema::table('staging_variants', function (Blueprint $table) {
            $table->bigInteger('quantity_raw')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('staging_variants', function (Blueprint $table) {
            $table->integer('quantity_raw')->default(0)->change();
        });
    }
};
