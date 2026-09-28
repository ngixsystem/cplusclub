<?php
namespace App\Policies;
use App\Domain\Access\ClubAccess;
use App\Models\{User, Visit};
class VisitPolicy {
    public function view(User $user, Visit $visit): bool { return $user->active && ClubAccess::allows($user, $visit->club_id); }
    public function update(User $user, Visit $visit): bool { return $this->view($user,$visit) && ($user->role !== 'representative') && (ClubAccess::allClubs($user) || $visit->specialist_id === $user->id); }
}
