<?php
namespace App\Console\Commands;
use App\Models\Club;
use Illuminate\Console\Command;

class ImportIcafeComputers extends Command {
    protected $signature='cclub:icafe-import-pcs {club}';
    protected $description='Import PCs from the connected iCafeCloud license without deleting existing equipment';
    public function handle(\App\Domain\Monitoring\ImportIcafeComputers $import):int {
        $club=Club::findOrFail($this->argument('club'));
        try{$this->info(json_encode($import->execute($club)));return self::SUCCESS;}
        catch(\Throwable){$this->error('Import failed; check connection and duplicate equipment. No partial import was saved.');return self::FAILURE;}
    }
}
