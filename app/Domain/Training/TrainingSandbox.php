<?php

namespace App\Domain\Training;

use App\Models\{Club, Equipment, User};
use Illuminate\Support\Str;

/**
 * The hidden training club and the simulated club client. Both are created on first use.
 * The club is excluded by the models' "real" global scopes; the client account cannot sign in.
 */
final class TrainingSandbox
{
    public const CLIENT_EMAIL = 'training-client@system.cplusclub.tech';

    public function club(): Club
    {
        $club = Club::withoutGlobalScope('real')->where('is_training', true)->first();
        if ($club) {
            return $club;
        }
        $club = new Club(['name' => 'Учебный клуб C+CLub', 'address' => 'Практикум по заявкам — клуб не существует',
            'timezone' => 'Asia/Tashkent', 'support_hours' => 'Круглосуточно', 'notes' => 'Служебный клуб для учебных заявок. Не удалять.']);
        $club->forceFill(['is_training' => true])->save();

        return $club;
    }

    public function equipment(array $spec): Equipment
    {
        $club = $this->club();

        return Equipment::withoutGlobalScope('real')->firstOrCreate(
            ['club_id' => $club->id, 'name' => $spec['name']],
            ['type' => $spec['type'] ?? 'pc', 'workstation_number' => $spec['workstation_number'] ?? null, 'zone' => $spec['zone'] ?? null,
                'next_inspection_date' => now()->addYear()->toDateString()],
        );
    }

    public function clientId(): int
    {
        $user = User::withTrashed()->where('email', self::CLIENT_EMAIL)->first();
        if ($user) {
            return $user->id;
        }
        $user = new User;
        $user->name = 'Учебный клиент (симуляция)';
        $user->email = self::CLIENT_EMAIL;
        $user->password = Str::random(64);
        $user->role = 'representative';
        $user->active = false;
        $user->save();

        return $user->id;
    }
}
