<?php

namespace App\Policies;

use App\Domain\Access\ClubAccess;
use App\Models\{Club, User};

class ClubPolicy
{
    public function view(User $user, Club $club): bool { return $user->active && ClubAccess::allows($user, $club->id); }
    public function create(User $user): bool { return $user->active && ClubAccess::allClubs($user); }
    public function update(User $user, Club $club): bool { return $this->create($user); }
}
