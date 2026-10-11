<?php

namespace App\Policies;

use App\Domain\Knowledge\Article;
use App\Models\User;

final class KnowledgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active && in_array($user->role, ['owner', 'lead', 'specialist', 'representative'], true);
    }

    public function view(User $user, Article $article): bool
    {
        return $this->viewAny($user);
    }
}
