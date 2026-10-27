<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\{Cache, Schedule, Hash, Validator};
use App\Models\User;

Artisan::command('cclub:admin {email} {--name=Владелец}', function () {
    $password = $this->secret('Пароль (не менее 6 символов)');
    $data = ['email' => $this->argument('email'), 'password' => $password];
    $validation = Validator::make($data, ['email' => 'required|email|unique:users,email', 'password' => 'required|string|min:6']);
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
Schedule::command('cclub:telemetry-maintain')->hourly()->withoutOverlapping();
Schedule::call(function(){
    \App\Models\Equipment::where('type','pc')->whereNotNull('next_inspection_date')->with('club')->chunkById(200,function($rows){foreach($rows as $e){$today=now($e->club->timezone)->toDateString();$soon=now($e->club->timezone)->addDays(7)->toDateString();if($e->next_inspection_date<=$soon){$phase=$e->next_inspection_date<$today?'overdue':'due';app(\App\Domain\Monitoring\EvaluateSample::class)->event($e->club_id,'inspection_due','inspection:'.$e->id.':'.$e->next_inspection_date.':'.$phase,['equipment_id'=>$e->id,'due'=>$e->next_inspection_date,'state'=>$phase]);}}});
})->name('inspection-reminders')->daily()->withoutOverlapping();
Schedule::call(fn()=>app(\App\Domain\Notifications\DispatchOutbox::class)->execute())->name('outbox')->everyMinute()->withoutOverlapping();
Schedule::call(function(){foreach(\Illuminate\Support\Facades\DB::table('game_subscriptions')->distinct()->pluck('app_id') as $id)\App\Jobs\CheckGameVersion::dispatch($id);})->name('published-builds')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(function(){
    foreach(\App\Models\Club::whereNotNull('icafe_license')->whereNotNull('icafe_token')->pluck('id') as $id)\App\Jobs\CollectIcafeBootTelemetry::dispatch($id);
})->name('icafe-boot-telemetry')->everyMinute()->withoutOverlapping();
Artisan::command('cclub:icafe-boot {club}', function () {
    $club = \App\Models\Club::findOrFail($this->argument('club'));
    $r = app(\App\Domain\Monitoring\IcafeBootTelemetry::class)->collect($club);
    $this->info(sprintf('%s: Boot %s, matched %d PCs, unmatched %d.', $club->name, $r['supported'] ? 'on' : 'off', $r['matched'], $r['unmatched']));
})->purpose('Pull CPU/GPU temperatures for a club from iCafeCloud Boot once');
Schedule::call(fn () => \App\Jobs\PollTelegram::dispatch())->name('telegram-poll')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app(\App\Domain\Telegram\LinkFollowups::class)->sweep())->name('link-followups')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    // Reports for the month that just ended; clubs with no activity at all are skipped.
    foreach (\App\Models\Club::where('status', 'active')->get() as $club) {
        $month = now($club->timezone ?: 'Asia/Tashkent')->subMonthNoOverflow()->format('Y-m');
        $r = app(\App\Domain\Reports\MonthlyReport::class)->build($club, $month);
        if (! $r['tickets']['created'] && ! $r['service']['visits'] && ! $r['monitoring']['link'] && ! $r['monitoring']['temperature']) continue;
        app(\App\Domain\Monitoring\EvaluateSample::class)->event($club->id, 'monthly_report', 'monthly-report:'.$club->id.':'.$month,
            ['month' => $month, 'message' => \App\Domain\Reports\MonthlyReport::telegram($r)."\n\nПодробный отчёт: ".rtrim(config('app.url'), '/').'/reports/monthly?club_id='.$club->id.'&month='.$month]);
    }
})->name('monthly-reports')->monthlyOn(1, '04:07')->withoutOverlapping();
Schedule::call(function () {
    foreach (\App\Models\Club::whereNotNull('icafe_license')->whereNotNull('icafe_token')->pluck('id') as $id) \App\Jobs\SyncBootHardware::dispatch($id);
})->name('boot-hardware')->hourlyAt(17)->withoutOverlapping();
