<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class KnowledgeProgress extends Model
{
    protected $table = 'knowledge_progress';

    protected $fillable = ['user_id', 'article_id', 'checked', 'completed_at', 'quiz_best_percent', 'quiz_passed_at'];

    protected function casts(): array
    {
        return ['checked' => 'array', 'completed_at' => 'datetime', 'quiz_passed_at' => 'datetime', 'quiz_best_percent' => 'integer'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
