<?php

namespace Database\Seeders;

use App\Models\{Club, Equipment, Ticket, User};
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the isolated staging stack only (design review and manual QA).
 * Every club is prefixed «Демо ·» so it can never be mistaken for real data.
 * Idempotent: runs once, then leaves the data as it is.
 */
class StagingSeeder extends Seeder
{
    private const PREFIX = 'Демо · ';

    public function run(): void
    {
        if (! app()->environment('staging')) {
            throw new \RuntimeException('StagingSeeder runs only with APP_ENV=staging.');
        }
        $password = (string) env('STAGING_DEMO_PASSWORD', '');
        if (strlen($password) < 12) {
            throw new \RuntimeException('Set STAGING_DEMO_PASSWORD (12+ characters) in the staging .env.');
        }
        if (Club::where('name', 'like', self::PREFIX.'%')->exists()) {
            $this->command?->info('Staging demo data already present, skipping.');
            return;
        }

        DB::transaction(function () use ($password) {
            mt_srand(20261016);
            $users = [];
            foreach ([
                'owner' => ['Анвар (демо)', 'demo-owner@staging.cplusclub.tech'],
                'lead' => ['Технический руководитель (демо)', 'demo-lead@staging.cplusclub.tech'],
                'specialist' => ['Выездной специалист (демо)', 'demo-specialist@staging.cplusclub.tech'],
                'representative' => ['Представитель клуба (демо)', 'demo-club@staging.cplusclub.tech'],
            ] as $role => [$name, $email]) {
                $user = User::firstOrNew(['email' => $email]);
                $user->name = $name; $user->password = $password; $user->role = $role; $user->active = true;
                $user->save();
                $users[$role] = $user;
            }

            $clubs = [];
            foreach ([['Cyber Arena Чиланзар', 'Ташкент, Чиланзар, 9-й квартал', 32],
                      ['GameZone Юнусабад', 'Ташкент, Юнусабад, 4-й квартал', 24],
                      ['Respawn Самарканд', 'Самарканд, ул. Регистан, 12', 18]] as [$name, $address, $pcs]) {
                $club = Club::create(['name' => self::PREFIX.$name, 'address' => $address, 'timezone' => 'Asia/Tashkent',
                    'contacts' => '+998 90 000 00 00 (демо)', 'support_hours' => 'Ежедневно 10:00–22:00', 'status' => 'active',
                    'specialist_id' => $users['specialist']->id, 'notes' => 'Демонстрационный клуб стейджинга.']);
                $club->members()->syncWithoutDetaching([$users['specialist']->id]);
                $clubs[] = [$club, $pcs];
            }
            $users['representative']->clubs()->syncWithoutDetaching([$clubs[0][0]->id]);

            $now = CarbonImmutable::now('UTC');
            $categories = ['Диагностика', 'Чистка ПК', 'Ремонт', 'Сеть', 'Обновление образа'];
            $problems = ['Не загружается образ по PXE', 'Перегрев GPU под нагрузкой', 'Шумит вентилятор CPU', 'Не работает гарнитура',
                'Пропадает сеть на ряду', 'FACEIT не запускается', 'Синий экран при запуске CS2', 'Плановая чистка ПК',
                'Не видит второй монитор', 'Медленная загрузка Windows'];

            foreach ($clubs as $c => [$club, $pcs]) {
                $equipment = [];
                for ($i = 1; $i <= $pcs; $i++) {
                    $last = $now->subDays(mt_rand(5, 75))->toDateString();
                    $equipment[] = Equipment::create(['club_id' => $club->id, 'name' => sprintf('PC-%03d', $i),
                        'zone' => $i <= intdiv($pcs, 3) ? 'VIP' : 'Общий зал', 'workstation_number' => (string) $i, 'type' => 'pc',
                        'cpu' => 'Intel Core i5-12400F', 'gpu' => $i % 4 === 0 ? 'RTX 4060 Ti' : 'RTX 3060', 'ram_mb' => 16384,
                        'disks' => 'Бездисковый (CCBoot)', 'status' => $i % 17 === 0 ? 'maintenance' : 'active',
                        'last_inspection_date' => $last,
                        'next_inspection_date' => CarbonImmutable::parse($last)->addMonthsNoOverflow(2)->toDateString()]);
                }
                foreach ([['Сервер CCBoot', 'server'], ['Коммутатор ядра', 'switch'], ['Маршрутизатор MikroTik', 'router'], ['ИБП серверной', 'ups']] as [$name, $type]) {
                    Equipment::create(['club_id' => $club->id, 'name' => $name, 'type' => $type, 'zone' => 'Серверная',
                        'status' => 'active', 'next_inspection_date' => $now->addDays(mt_rand(-10, 40))->toDateString()]);
                }

                // Tickets spread over the last seven months, most of them closed.
                for ($t = 0; $t < 26 - $c * 6; $t++) {
                    $created = $now->subDays(mt_rand(0, 205))->subMinutes(mt_rand(0, 900));
                    $age = $now->diffInDays($created);
                    $status = $age > 20 ? 'closed' : ['new', 'accepted', 'working', 'parts', 'resolved', 'closed'][mt_rand(0, 5)];
                    $pc = $equipment[array_rand($equipment)];
                    $category = $categories[array_rand($categories)];
                    $priority = mt_rand(0, 14) === 0 ? 'critical' : ['low', 'normal', 'normal', 'high'][mt_rand(0, 3)];
                    $done = in_array($status, ['resolved', 'closed'], true);
                    $ticket = new Ticket(['club_id' => $club->id, 'equipment_id' => $pc->id, 'category' => $category,
                        'description' => $problems[array_rand($problems)].' — '.$pc->name, 'priority' => $priority,
                        'initiator_id' => $users['lead']->id,
                        'assignee_id' => $status === 'new' ? null : $users['specialist']->id]);
                    $ticket->status = $status;
                    $ticket->created_at = $created; $ticket->updated_at = $created;
                    if ($status !== 'new') $ticket->first_responded_at = $created->addMinutes(mt_rand(10, 180));
                    if ($done) {
                        $ticket->work_result = 'Выполнена диагностика, устранена причина, ПК проверен в игре.';
                        $ticket->verification_result = 'Загрузка образа и запуск CS2 проверены.';
                        $ticket->cause = 'Износ / загрязнение компонента';
                        $ticket->solution = 'Чистка и замена термоинтерфейса';
                        $ticket->resolved_at = $created->addHours(mt_rand(2, 48));
                    }
                    if ($status === 'closed') $ticket->closed_at = $ticket->resolved_at->addHours(mt_rand(1, 24));
                    $ticket->save();
                    DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'actor_id' => $users['lead']->id,
                        'from_status' => 'new', 'to_status' => $status, 'comment' => 'Демо-история', 'created_at' => $created->addHour()]);
                    if ($done) {
                        DB::table('work_logs')->insert(['ticket_id' => $ticket->id, 'user_id' => $users['specialist']->id,
                            'minutes' => mt_rand(20, 180), 'cost_minor' => mt_rand(8, 60) * 100, 'currency' => 'USD',
                            'actions' => 'Диагностика и ремонт', 'consumables' => mt_rand(0, 1) ? 'Термопаста' : null,
                            'created_at' => $ticket->resolved_at]);
                    }
                }

                // Recent telemetry for a part of the hall, including a few hot GPUs.
                foreach (array_slice($equipment, 0, intdiv($pcs * 2, 3)) as $i => $pc) {
                    $gpu = $i % 9 === 0 ? 84 + mt_rand(0, 6) : 48 + mt_rand(0, 25);
                    DB::table('telemetry_samples')->insert(['equipment_id' => $pc->id, 'observed_at' => $now->subSeconds(mt_rand(5, 60)),
                        'received_at' => $now, 'cpu_temp' => 42 + mt_rand(0, 30), 'gpu_temp' => $gpu, 'cpu_load' => mt_rand(5, 95),
                        'gpu_load' => mt_rand(5, 99), 'ram_used_percent' => mt_rand(30, 85), 'disk_free_percent' => mt_rand(20, 70),
                        'sensor_status' => json_encode(array_fill_keys(['cpu_temp', 'gpu_temp', 'cpu_load', 'gpu_load', 'ram_used_percent', 'disk_free_percent'], 'ok'))]);
                    DB::table('equipment')->where('id', $pc->id)->update(['last_seen_at' => $now]);
                    if ($gpu > 83) {
                        DB::table('alerts')->insert(['club_id' => $club->id, 'equipment_id' => $pc->id, 'metric' => 'gpu_temp',
                            'state' => 'active', 'episode' => 1, 'opened_at' => $now->subMinutes(mt_rand(2, 50)), 'last_sample_at' => $now]);
                    }
                }

                DB::table('game_subscriptions')->insertOrIgnore([['club_id' => $club->id, 'app_id' => 730], ['club_id' => $club->id, 'app_id' => 570]]);
            }

            foreach ([[730, '20231457'], [570, '20229981']] as [$app, $build]) {
                DB::table('published_versions')->updateOrInsert(['app_id' => $app], ['build_id' => $build, 'source_status' => 'ok',
                    'checked_at' => $now->subMinutes(4), 'published_at' => $now->subDays(2)]);
            }
        });

        $this->command?->info('Staging demo data created. Logins: demo-*@staging.cplusclub.tech, password = STAGING_DEMO_PASSWORD.');
    }
}
