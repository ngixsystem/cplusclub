<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\{Cache, Schedule, Hash, Validator};
use App\Models\User;

Artisan::command('cclub:admin {email} {--name=Владелец}', function () {
    $password = $this->secret('Пароль (не менее 14 символов)');
    $data = ['email' => $this->argument('email'), 'password' => $password];
    $validation = Validator::make($data, ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:14']);
    if ($validation->fails()) { $this->error($validation->errors()->first()); return 1; }
    $user = new User();
    $user->name = $this->option('name'); $user->email = $data['email'];
    $user->password = Hash::make($password); $user->role = 'owner'; $user->save();
    $this->info('Владелец создан. Публичная регистрация отсутствует.');
});
Artisan::command('cclub:scheduler-health', function () {
    return Cache::get('scheduler-heartbeat', 0) > time() - 150 ? 0 : 1;
});
Schedule::call(fn () => Cache::put('scheduler-heartbeat', time(), 180))->name('scheduler-heartbeat')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
