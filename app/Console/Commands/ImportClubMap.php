<?php

namespace App\Console\Commands;

use App\Domain\ClubMap\MapLayout;
use App\Models\Club;
use Illuminate\Console\Command;

final class ImportClubMap extends Command
{
    protected $signature = 'cclub:club-map {club : Club ID} {file? : Layout JSON from scripts/maps/extract_plan.py}
        {--renumber=* : Map a plan number to the iCafeCloud number, e.g. 171=71}
        {--remove : Remove the map from the club}';
    protected $description = 'Import (or remove) the private 3D map layout of a club';

    public function handle(): int
    {
        $club = Club::findOrFail($this->argument('club'));
        if ($this->option('remove')) {
            $club->map_layout = null; $club->save();
            $this->info("Map removed from club #{$club->id}.");
            return self::SUCCESS;
        }
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_file($file) || filesize($file) > 2_000_000) { $this->error('Layout file is missing or too large.'); return self::FAILURE; }
        $renumber = [];
        foreach ((array) $this->option('renumber') as $pair) {
            if (! preg_match('/^(\d+)=(\d+)$/', (string) $pair, $m)) { $this->error("Invalid --renumber value: {$pair}"); return self::FAILURE; }
            $renumber[(int) $m[1]] = (int) $m[2];
        }
        try {
            $layout = MapLayout::normalise(json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR), $renumber);
        } catch (\Throwable $e) {
            $this->error('Layout rejected: '.$e->getMessage());
            return self::FAILURE;
        }
        $club->map_layout = $layout; $club->save();
        $this->info(sprintf('Map "%s" imported into club #%d: %d zones, %d computers.', $layout['name'], $club->id, count($layout['zones']), count($layout['computers'])));
        return self::SUCCESS;
    }
}
