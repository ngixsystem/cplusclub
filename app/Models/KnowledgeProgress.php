<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class KnowledgeProgress extends Model
{
    protected $table = 'knowledge_progress';

    protected $fillable = ['user_id', 'article_id', 'checked', 'completed_at'];

    protected function casts(): array
    {
        return ['checked' => 'array', 'completed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
