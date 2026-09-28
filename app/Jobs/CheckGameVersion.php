<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class CheckGameVersion implements ShouldQueue {
    use Queueable;
    public int $tries=3;public int $timeout=30;
    public function __construct(public int $appId){$this->onQueue('integrations');}
    public function handle(\App\Domain\Updates\CheckPublished $check):void {$check->execute($this->appId,new \App\Domain\Updates\SteamCmdProvider());}
}
