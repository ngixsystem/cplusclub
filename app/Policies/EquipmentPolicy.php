<?php

namespace App\Policies;

use App\Domain\Access\ClubAccess;
use App\Models\{Equipment, User};

class EquipmentPolicy
{
    public function view(User $user, Equipment $equipment): bool { return $user->active && ClubAccess::allows($user, $equipment->club_id); }
    public function update(User $user, Equipment $equipment): bool { return $this->view($user, $equipment) && $user->role !== 'representative'; }
}
