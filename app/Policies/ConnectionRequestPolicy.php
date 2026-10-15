<?php
namespace App\Policies;
use App\Models\{User,ConnectionRequest};
class ConnectionRequestPolicy {
    public function viewAny(User $user): bool {return $user->active && in_array($user->role,['owner','lead'],true);}
    public function update(User $user,ConnectionRequest $lead): bool {return $this->viewAny($user);}
}
