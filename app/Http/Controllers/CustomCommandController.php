<?php

namespace App\Http\Controllers;

use App\Models\CustomCommand;
use Illuminate\Http\Request;

class CustomCommandController extends Controller
{
    public function index()
    {
        $commands = CustomCommand::where('user_id', auth()->id())
            ->where('is_active', true)
            ->get();

        return response()->json(['commands' => $commands]);
    }

    public function store(Request $request)
    {
        \Log::info('Données reçues:', $request->all());

        $validated = $request->validate([
            'command' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'prompt' => 'required|string',
        ]);

        try {
            $command = CustomCommand::create([
                'user_id' => auth()->id(),
                'command' => $validated['command'],
                'name' => $validated['name'],
                'description' => $validated['description'],
                'prompt' => $validated['prompt'],
                'is_active' => true
            ]);

            return response()->json(['command' => $command]);
        } catch (\Exception $e) {
            \Log::error('Erreur création commande:', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
