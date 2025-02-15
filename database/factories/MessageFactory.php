<?php

namespace Database\Factories;

use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    /**
     * Définition du modèle
     */
    public function definition(): array
    {
        return [
            'conversation_id' => null, // Sera rempli dans le seeder
            'role' => $this->faker->randomElement(['user', 'assistant']),
            'content' => $this->faker->paragraph(),
        ];
    }
}
