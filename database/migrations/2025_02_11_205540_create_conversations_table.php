<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('title')->nullable(); // Titre auto dès le premier message
            $table->string('model'); // Modèle LLM utilisé
            $table->boolean('is_temporary')->default(true); // Si la conv n'est pas sauvegardée
            $table->json('context')->nullable(); // Personnalisation utilisateur (ton, style, etc.)
            $table->timestamp('last_activity')->nullable(); // Pour trier par dernières convs actives
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
