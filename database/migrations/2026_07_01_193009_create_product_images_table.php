<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dedup per URL: se l'URL non e' cambiato rispetto all'ultimo sync, non si ricarica su Shopify.
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->string('url');
            $table->unsignedBigInteger('shopify_media_id')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
