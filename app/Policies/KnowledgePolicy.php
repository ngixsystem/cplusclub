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

    /** Only editorial chapters with a checklist can be marked as studied. */
    public function track(User $user, Article $article): bool
    {
        return $this->view($user, $article) && $article->data['kind'] === 'guide' && !empty($article->data['checklist']);
    }

    /** Team training overview: owner and technical lead. */
    public function viewTeam(User $user): bool
    {
        return $user->active && in_array($user->role, ['owner', 'lead'], true);
    }
}
