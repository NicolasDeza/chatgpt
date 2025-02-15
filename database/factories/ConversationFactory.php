<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /**
     * Définition du modèle
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'model' => 'meta-llama/llama-3.2-11b-vision-instruct:free',
            'is_temporary' => false,
        ];
    }
}
