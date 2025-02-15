<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;

class ChatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // On prend le premier utilisateur existant (ou on en crée un)
        $user = User::first() ?? User::factory()->create();

        // Créons 5 conversations de test
        Conversation::factory(5)->create([
            'user_id' => $user->id, // Associe à l'utilisateur
        ])->each(function ($conversation) {
            // Génération de 3 à 6 messages aléatoires pour chaque conversation
            Message::factory(rand(3, 6))->create([
                'conversation_id' => $conversation->id,
            ]);

            // Mise à jour du titre de la conversation avec le premier message
            $firstMessage = $conversation->messages()->first();
            if ($firstMessage) {
                $conversation->update(['title' => substr($firstMessage->content, 0, 30) . '...']);
            }
        });
    }
}
