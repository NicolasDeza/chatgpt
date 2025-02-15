<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Conversation;
use App\Models\CustomInstruction;
use App\Services\ChatService;
use App\Events\ChatMessageStreamed;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MessageController extends Controller
{
    protected $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index($conversationId)
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $conversationId)
    {
        $conversation = Conversation::findOrFail($conversationId);

        // Récupérer les instructions personnalisées
        $customInstruction = CustomInstruction::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        // Sauvegarder le message utilisateur
        Message::create([
            'conversation_id' => $conversationId,
            'role' => 'user',
            'content' => $request->message
        ]);

        // Préparer le fil des messages avec les instructions système
        $messages = [];

        // Ajouter les instructions personnalisées si elles existent
        if ($customInstruction) {
            $systemMessage = "Instructions de l'utilisateur:\n";
            if ($customInstruction->about_user) {
                $systemMessage .= "À propos de l'utilisateur: " . $customInstruction->about_user . "\n";
            }
            if ($customInstruction->preference) {
                $systemMessage .= "Préférences de réponse: " . $customInstruction->preference;
            }

            $messages[] = [
                'role' => 'system',
                'content' => $systemMessage
            ];
        }

        // Ajouter l'historique des messages
        $messages = array_merge(
            $messages,
            $conversation->messages()
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
                ->toArray()
        );

        // Obtenir la réponse de l'IA
        $aiResponse = $this->chatService->sendMessage(
            messages: $messages,
            model: $request->model ?? $conversation->model
        );

        // Sauvegarder la réponse
        Message::create([
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'content' => $aiResponse
        ]);

        // Génération automatique du titre dès la première réponse
        if ($conversation->messages()->count() <= 2) {
            $titlePrompt = "Génère un titre court et concis (maximum 5 mots) pour une conversation qui commence par ce message, sans guillemets ni ponctuation : " . $request->message;
            $title = $this->chatService->sendMessage(
                messages: [['role' => 'user', 'content' => $titlePrompt]],
                model: $request->model ?? $conversation->model
            );
            $title = trim(str_replace(['"', "'", '.', '!', '?'], '', $title));
            $conversation->update([
                'title' => $title,
                'last_activity' => now()
            ]);
        } else {
            $conversation->update(['last_activity' => now()]);
        }

        // Recharger la conversation avec ses relations pour refléter le nouveau titre
        $conversation = $conversation->fresh();

        // Mettre à jour last_activity avec la date actuelle
        $conversation->update([
            'last_activity' => now(),
        ]);

        // Récupérer toutes les conversations triées par last_activity
        $conversations = Conversation::where('user_id', auth()->id())
            ->orderBy('last_activity', 'desc')
            ->get();

        return response()->json([
            'messages' => $conversation->messages()->orderBy('created_at', 'asc')->get(),
            'conversation' => $conversation,
            'conversations' => $conversations
        ]);
    }

    // Ajout de la méthode streamMessage pour le streaming
    public function streamMessage(Conversation $conversation, Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'model'   => 'nullable|string',
        ]);

        try {
            // 1. Sauvegarder le message de l'utilisateur
            $conversation->messages()->create([
                'content' => $request->input('message'),
                'role'    => 'user',
            ]);

            // 2. Nom du canal
            $channelName = "chat.{$conversation->id}";

            // 3. Récupérer historique
            $messages = $conversation->messages()
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($msg) => [
                    'role'    => $msg->role,
                    'content' => $msg->content,
                ])
                ->toArray();

            // 4. Obtenir un flux depuis le ChatService
            $stream = (new ChatService())->streamConversation(
                messages: $messages,
                model: $conversation->model ?? $request->user()->last_used_model ?? \App\Services\ChatService::DEFAULT_MODEL,
            );

            // 5. Créer le message "assistant" dans la BD (vide pour l'instant)
            $assistantMessage = $conversation->messages()->create([
                'content' => '',
                'role'    => 'assistant',
            ]);

            // 6. Variables pour accumuler la réponse
            $fullResponse = '';
            $buffer = '';
            $lastBroadcastTime = microtime(true) * 1000; // ms

            // 7. Itération sur le flux
            foreach ($stream as $response) {
                $chunk = $response->choices[0]->delta->content ?? '';

                if ($chunk) {
                    $fullResponse .= $chunk;
                    $buffer .= $chunk;

                    // Broadcast seulement toutes les ~100ms
                    $currentTime = microtime(true) * 1000;
                    if ($currentTime - $lastBroadcastTime >= 100) {
                        broadcast(new ChatMessageStreamed(
                            channel: $channelName,
                            content: $buffer,
                            isComplete: false
                        ));

                        $buffer = '';
                        $lastBroadcastTime = $currentTime;
                    }
                }
            }

            // 8. Diffuser le buffer restant
            if (!empty($buffer)) {
                broadcast(new ChatMessageStreamed(
                    channel: $channelName,
                    content: $buffer,
                    isComplete: false
                ));
            }

            // 9. Mettre à jour la BD avec le texte complet
            $assistantMessage->update([
                'content' => $fullResponse
            ]);

            // 10. Émettre un dernier événement pour signaler la complétion
            broadcast(new ChatMessageStreamed(
                channel: $channelName,
                content: $fullResponse,
                isComplete: true
            ));

            return response()->json("ok");
        } catch (\Exception $e) {
            // Diffuser l’erreur
            if (isset($conversation)) {
                broadcast(new ChatMessageStreamed(
                    channel: "chat.{$conversation->id}",
                    content: "Erreur: " . $e->getMessage(),
                    isComplete: true,
                    error: true
                ));
            }

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Message $message)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Message $message)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Message $message)
    {
        //
    }
}
