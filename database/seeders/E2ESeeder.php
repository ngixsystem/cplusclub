<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
class E2ESeeder extends Seeder {
    public function run():void {
        if(!app()->environment('testing')||config('database.connections.pgsql.database')!=='cclub_test')throw new \RuntimeException('Test seeder requires isolated testing DB');
        $user=User::firstOrNew(['email'=>'e2e@example.test']);$user->name='E2E Owner';$user->password='Isolated-E2E-Password-2026';$user->role='owner';$user->save();
        \App\Models\Club::firstOrCreate(['name'=>'E2E dashboard'], ['address'=>'Test only','timezone'=>'Asia/Tashkent']);
        $club=\App\Models\Club::firstOrCreate(['name'=>'E2E approval'], ['address'=>'Test only']);
        $client=User::firstOrNew(['email'=>'approval@example.test']);$client->name='E2E Club Owner';$client->password='Isolated-E2E-Password-2026';$client->role='representative';$client->active=true;$client->save();
        $client->clubs()->syncWithoutDetaching([$club->id]);
    }
}
