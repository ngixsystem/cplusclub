<?php
namespace App\Policies;
use App\Models\{User, Attachment, Ticket, Equipment, Inspection};
use Illuminate\Support\Facades\Gate;
class AttachmentPolicy {
    public function view(User $user, Attachment $attachment): bool {
        $parent = $attachment->ticket_id ? Ticket::find($attachment->ticket_id) : ($attachment->equipment_id ? Equipment::find($attachment->equipment_id) : Inspection::find($attachment->inspection_id));
        return $parent && $parent->club_id === $attachment->club_id && Gate::forUser($user)->allows('view', $parent);
    }
}
