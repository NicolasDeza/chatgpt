<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{
    private $baseUrl;
    private $apiKey;
    private $client;
    public const DEFAULT_MODEL = 'meta-llama/llama-3.2-11b-vision-instruct:free';

    public function __construct()
    {
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->apiKey = config('services.openrouter.api_key');
        $this->client = $this->createOpenAIClient();
    }

    /**
     * @return array<array-key, array{
     *     id: string,
     *     name: string,
     *     context_length: int,
     *     max_completion_tokens: int,
     *     pricing: array{prompt: int, completion: int}
     * }>
     */
    public function getModels(): array
    {
        return cache()->remember('openai.models', now()->addHour(), function () {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($this->baseUrl . '/models');

            return collect($response->json()['data'])
                ->filter(function ($model) {
                    return str_ends_with($model['id'], ':free');
                })
                ->sortBy('name')
                ->map(function ($model) {
                    return [
                        'id' => $model['id'],
                        'name' => $model['name'],
                        'context_length' => $model['context_length'],
                        'max_completion_tokens' => $model['top_provider']['max_completion_tokens'],
                        'pricing' => $model['pricing'],
                    ];
                })
                ->values()
                ->all()
            ;
        });
    }

    /**
     * @param array{role: 'user'|'assistant'|'system'|'function', content: string} $messages
     * @param string|null $model
     * @param float $temperature
     *
     * @return string
     */
    public function sendMessage(array $messages, string $model = null, float $temperature = 0.7): string
    {
        $maxRetries = 5; // Augmenté de 3 à 5
        $attempt = 0;
        $baseWaitTime = 5; // 5 secondes de base

        while ($attempt < $maxRetries) {
            try {
                // Ajouter un délai progressif entre les tentatives
                if ($attempt > 0) {
                    $waitTime = $baseWaitTime * $attempt;
                    sleep($waitTime);
                }

                Log::info('Tentative d\'envoi du message', [
                    'model' => $model ?? self::DEFAULT_MODEL,
                    'attempt' => $attempt + 1,
                    'waitTime' => $waitTime ?? 0
                ]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'HTTP-Referer' => 'https://mini-chatgpt.test',
                    'X-Title' => 'Mini-ChatGPT'
                 ])->timeout(30)->post($this->baseUrl . '/chat/completions', [
                    'model' => $model ?? self::DEFAULT_MODEL,
                    'messages' => $messages,
                    'temperature' => $temperature
                ]);

                $data = $response->json();

                if ($response->status() === 429) {
                    $resetTime = $response->header('X-RateLimit-Reset');
                    $waitTime = $resetTime ? (int) ($resetTime - time()) : 30;

                    Log::warning('Rate limit atteint', [
                        'reset_time' => $resetTime,
                        'wait_time' => $waitTime
                    ]);

                    if ($attempt < $maxRetries - 1) {
                        $attempt++;
                        sleep(min($waitTime, 30)); // Attendre max 30 secondes
                        continue;
                    }
                }

                if (!$response->successful()) {
                    throw new \Exception('Erreur API: ' . $response->body());
                }

                // Vérification plus détaillée de la réponse
                if (isset($data['error'])) {
                    throw new \Exception('Erreur API: ' . ($data['error']['message'] ?? 'Erreur inconnue'));
                }

                if (!isset($data['choices'][0]['message']['content'])) {
                    throw new \Exception('Format de réponse API invalide');
                }

                return $data['choices'][0]['message']['content'];

            } catch (\Exception $e) {
                Log::error('Erreur API Chat', [
                    'error' => $e->getMessage(),
                    'messages' => $messages,
                    'attempt' => $attempt + 1
                ]);

                if ($attempt >= $maxRetries - 1) {
                    throw new \Exception('Erreur lors de la communication avec l\'API : ' . $e->getMessage());
                }

                $attempt++;
            }
        }

        throw new \Exception('Nombre maximum de tentatives atteint');
    }

    private function createOpenAIClient(): \OpenAI\Client
    {
        return \OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withBaseUri($this->baseUrl)
            ->make()
        ;
    }

    /**
     * @return array{role: 'system', content: string}
     */
    private function getChatSystemPrompt(): array
    {
        $user = auth()->user();
        $now = now()->locale('fr')->format('l d F Y H:i');

        // Log des informations de l'utilisateur
        logger()->info('Information utilisateur:', [
            'user_id' => $user->id,
            'user_name' => $user->name
        ]);

        // Récupérer et logger les instructions personnalisées
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        logger()->info('Instructions personnalisées:', [
            'has_instructions' => !is_null($customInstruction),
            'instruction_data' => $customInstruction
        ]);

        // Récupérer les instructions personnalisées actives
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $systemPrompt = "Tu es un assistant de chat. La date et l'heure actuelle est le {$now}.\n";
        $systemPrompt .= "Tu es actuellement utilisé par {$user->name}.\n";

        if ($customInstruction) {
            if ($customInstruction->about_user) {
                $systemPrompt .= "\nÀ propos de l'utilisateur:\n" . $customInstruction->about_user;
            }
            if ($customInstruction->preference) {
                $systemPrompt .= "\nPréférences de réponse:\n" . $customInstruction->preference;
            }
        }

        return [
            'role' => 'system',
            'content' => $systemPrompt,
        ];
    }
}
