<?php
namespace App\Policies;
use App\Models\{User, Inspection};
class InspectionPolicy {
    public function view(User $user, Inspection $inspection): bool { return (new VisitPolicy)->view($user,$inspection->visit); }
    public function update(User $user, Inspection $inspection): bool { return (new VisitPolicy)->update($user,$inspection->visit); }
}
