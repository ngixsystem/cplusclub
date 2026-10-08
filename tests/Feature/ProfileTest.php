<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Hash,Storage};
use Tests\TestCase;
class ProfileTest extends TestCase {
    use RefreshDatabase;
    public function test_password_requires_current_password_confirmation_and_minimum_length(): void {
        $user=User::factory()->create(['password'=>'old-password']);$this->actingAs($user);
        $this->postJson('/profile/password',['current_password'=>'wrong','password'=>'new123','password_confirmation'=>'new123'])->assertUnprocessable();
        $this->postJson('/profile/password',['current_password'=>'old-password','password'=>'new123','password_confirmation'=>'other'])->assertUnprocessable();
        $this->postJson('/profile/password',['current_password'=>'old-password','password'=>'short','password_confirmation'=>'short'])->assertUnprocessable();
        $this->assertTrue(Hash::check('old-password',$user->fresh()->password));
        $this->post('/profile/password',['current_password'=>'old-password','password'=>'new123','password_confirmation'=>'new123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('new123',$user->fresh()->password));$this->assertGuest();
    }
    public function test_avatar_is_private_replaceable_and_scoped_to_current_user(): void {
        Storage::fake('local');$user=User::factory()->create();$other=User::factory()->create();
        $file=fn()=>UploadedFile::fake()->createWithContent('avatar.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4XcAAAAASUVORK5CYII='));
        $this->actingAs($user)->post('/profile/avatar',['avatar'=>$file(),'user_id'=>$other->id])->assertRedirect();
        $path=$user->fresh()->avatar_path;Storage::disk('local')->assertExists($path);$this->assertNull($other->fresh()->avatar_path);
        $this->get('/profile/avatar')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->actingAs($other)->get('/profile/avatar')->assertNotFound();
        $this->actingAs($user)->post('/profile/avatar',['avatar'=>$file()])->assertRedirect();Storage::disk('local')->assertMissing($path);
        $this->postJson('/profile/avatar',['avatar'=>UploadedFile::fake()->create('script.svg',1,'image/svg+xml')])->assertUnprocessable();
        $this->delete('/profile/avatar')->assertRedirect();$this->assertNull($user->fresh()->avatar_path);
    }
    public function test_guests_and_disabled_accounts_cannot_use_settings(): void {
        $this->get('/profile')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['active'=>false]))->get('/profile')->assertForbidden();
        $this->post('/profile/password',[])->assertForbidden();
        $this->post('/profile/avatar',[])->assertForbidden();
    }
}
