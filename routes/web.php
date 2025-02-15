<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AskController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\CustomInstructionController; // Ajout de cet import
use App\Http\Controllers\CustomCommandController; // Ajout de cet import en haut du fichier
use Inertia\Inertia;

// ===========================
// 🚀 Page d'accueil (Welcome)
// ===========================
Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// ===========================
// 🔐 Routes Authentifiées
// ===========================
Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {

    // 🎛️ Dashboard
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    // ===========================
    // 🤖 Routes "Ask" (Exercice 1)
    // ===========================
    Route::get('/ask', [ConversationController::class, 'index'])->name('ask.index');
    Route::post('/ask', [AskController::class, 'ask'])->name('ask.post');

    // ===========================
    // 💬 Routes Conversations
    // ===========================
    Route::get('/chat', [ConversationController::class, 'index'])->name('chat.index'); // Liste des conversations
    Route::post('/chat', [ConversationController::class, 'store'])->name('chat.store'); // Créer une conversation
    Route::post('/conversations/{conversation}/update-title', [ConversationController::class, 'updateTitle'])->name('chat.updateTitle');
    Route::get('/chat/{conversation}', [ConversationController::class, 'show'])->name('chat.show'); // Voir une conversation

    // ===========================
    // 📝 Routes Messages
    // ===========================
    Route::post('/chat/{conversation}/messages', [MessageController::class, 'store'])->name('messages.store'); // Envoyer un message
    Route::get('/chat/{conversation}/messages', [MessageController::class, 'index'])->name('messages.index'); // Lister les messages d'une conversation

    // ===========================
    // 👤 Routes User
    // ===========================
    Route::post('/user/update-model', [\App\Http\Controllers\UserController::class, 'updateModel'])->name('user.updateModel');

    // ===========================
    // 📋 Routes Custom Instructions
    // ===========================
    Route::get('/custom-instructions', [CustomInstructionController::class, 'index'])->name('custom-instructions.index');
    Route::post('/custom-instructions', [CustomInstructionController::class, 'store'])->name('custom-instructions.store');
    Route::put('/custom-instructions/{instruction}', [CustomInstructionController::class, 'update'])->name('custom-instructions.update');

    // ===========================
    // 📋 Routes Custom Commands
    // ===========================
    Route::get('/custom-commands', [CustomCommandController::class, 'index'])->name('custom-commands.index');
    Route::post('/custom-commands', [CustomCommandController::class, 'store'])->name('custom-commands.store');

});
