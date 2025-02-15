<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Conversation;

class UserController extends Controller
{
    public function updateModel(Request $request)
    {
        $user = auth()->user();
        // Sauvegarde du modèle dans la table users
        $user->update(['selected_model' => $request->model]);

        // Si une conversation est sélectionnée, on met à jour son modèle
        if ($request->filled('conversation_id')) {
            $conversation = Conversation::find($request->conversation_id);
            if ($conversation && $conversation->user_id === $user->id) {
                $conversation->update(['model' => $request->model]);
            }
        }
        return response()->json(['user' => $user]);
    }
}
