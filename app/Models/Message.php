<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'role', 'content'];

    /**
     * Un message appartient à une conversation
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Scope pour récupérer les messages d'une conversation dans l'ordre chronologique
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'asc');
    }
}
