<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TrainingLabSubmission extends Model
{
    protected $fillable = ['user_id', 'lab_id', 'comment', 'files', 'status', 'grade', 'review_comment', 'reviewer_id', 'reviewed_at'];

    protected function casts(): array
    {
        return ['files' => 'array', 'grade' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
