<?php

namespace App\Http\Controllers;

use App\Models\CustomInstruction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomInstructionController extends Controller
{
    public function index()
    {
        $instruction = CustomInstruction::where('user_id', auth()->id())
            ->where('is_active', true)
            ->first();

        return Inertia::render('CustomInstruction/Index', [
            'instruction' => $instruction
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'about_user' => 'nullable|string|max:1500',
            'preference' => 'nullable|string|max:1500',
        ]);

        // Désactiver les anciennes instructions
        CustomInstruction::where('user_id', auth()->id())
            ->update(['is_active' => false]);

        // Créer les nouvelles instructions
        $instruction = CustomInstruction::create([
            'user_id' => auth()->id(),
            'about_user' => $validated['about_user'],
            'preference' => $validated['preference'],
            'is_active' => true
        ]);

        return response()->json([
            'instruction' => $instruction
        ]);
    }

    public function update(Request $request, CustomInstruction $instruction)
    {
        $validated = $request->validate([
            'about_user' => 'nullable|string|max:1500',
            'preference' => 'nullable|string|max:1500',
            'is_active' => 'boolean'
        ]);

        $instruction->update($validated);

        return response()->json([
            'instruction' => $instruction
        ]);
    }
}
