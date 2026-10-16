<?php

namespace App\Domain\ClubMap;

use InvalidArgumentException;

/**
 * Validates and normalises a club-map layout produced by scripts/maps/extract_plan.py.
 * Units are metres; x/y are plan coordinates (y grows "down" the drawing).
 */
final class MapLayout
{
    private const ZONE_TYPES = ['standard', 'platinum', 'vip', 'gold', 'room', 'mafia', 'hookah', 'stream', 'service', 'exit', 'closed'];
    private const FURNITURE = ['sofa', 'tv', 'table', 'chair', 'speaker', 'round_table', 'bench'];

    /** @param array<int,int> $renumber plan number => iCafeCloud number, e.g. [171 => 71] */
    public static function normalise(array $layout, array $renumber = []): array
    {
        $point = function ($p, string $where): array {
            if (! is_array($p) || count($p) !== 2 || ! is_numeric($p[0]) || ! is_numeric($p[1]) || abs($p[0]) > 500 || abs($p[1]) > 500) {
                throw new InvalidArgumentException("Invalid point in {$where}.");
            }
            return [round((float) $p[0], 3), round((float) $p[1], 3)];
        };
        if (($layout['version'] ?? null) !== 1) throw new InvalidArgumentException('Unsupported layout version.');
        foreach (['floor', 'zones', 'walls', 'computers', 'furniture'] as $key) {
            if (! isset($layout[$key]) || ! is_array($layout[$key])) throw new InvalidArgumentException("Missing {$key}.");
        }
        if (count($layout['computers']) > 1000 || count($layout['walls']) > 3000 || count($layout['zones']) > 200) {
            throw new InvalidArgumentException('Layout is too large.');
        }

        $zones = [];
        foreach ($layout['zones'] as $z) {
            if (! in_array($z['type'] ?? null, self::ZONE_TYPES, true) || ! preg_match('/^[a-z0-9-]{1,80}$/', (string) ($z['id'] ?? ''))) {
                throw new InvalidArgumentException('Invalid zone.');
            }
            $zones[$z['id']] = ['id' => $z['id'], 'type' => $z['type'], 'label' => mb_substr((string) ($z['label'] ?? ''), 0, 80),
                'poly' => array_map(fn ($p) => $point($p, 'zone'), $z['poly'] ?? []), 'center' => $point($z['center'] ?? null, 'zone')];
        }

        $numbers = [];
        $computers = [];
        foreach ($layout['computers'] as $c) {
            $n = $c['number'] ?? null;
            if (! is_int($n) || $n < 1 || $n > 9999) throw new InvalidArgumentException('Invalid computer number.');
            $n = $renumber[$n] ?? $n;
            if (isset($numbers[$n])) throw new InvalidArgumentException("Duplicate computer number {$n}.");
            $numbers[$n] = true;
            $zone = $c['zone'] ?? null;
            if ($zone !== null && ! isset($zones[$zone])) throw new InvalidArgumentException("Unknown zone for computer {$n}.");
            $facing = $point($c['facing'] ?? [0, -1], 'computer');
            $computers[] = ['number' => $n, 'zone' => $zone, 'tier' => mb_substr((string) ($c['tier'] ?? ''), 0, 20),
                'pos' => $point($c['pos'] ?? null, 'computer'), 'facing' => $facing];
        }
        usort($computers, fn ($a, $b) => $a['number'] <=> $b['number']);

        // Drawings often carry copy-pasted range labels ("Platinum 91–100" over PCs 51–60):
        // a range in a zone label always reflects the computers actually placed in it.
        foreach ($zones as $id => $z) {
            $in = array_column(array_filter($computers, fn ($c) => $c['zone'] === $id), 'number');
            if ($in && preg_match('/\d+\s*[–-]\s*\d+/u', $z['label'])) {
                $range = min($in) === max($in) ? (string) min($in) : min($in).'–'.max($in);
                $zones[$id]['label'] = preg_replace('/\d+\s*[–-]\s*\d+/u', $range, $z['label'], 1);
            }
        }

        return [
            'version' => 1,
            'name' => mb_substr((string) ($layout['name'] ?? 'Карта клуба'), 0, 120),
            'wall_height' => min(4, max(2, (float) ($layout['wall_height'] ?? 2.7))),
            'size' => $point($layout['size'] ?? [40, 40], 'size'),
            'floor' => array_map(fn ($p) => $point($p, 'floor'), $layout['floor']),
            'zones' => array_values($zones),
            'walls' => array_map(fn ($w) => ['a' => $point($w['a'] ?? null, 'wall'), 'b' => $point($w['b'] ?? null, 'wall'),
                'kind' => in_array($w['kind'] ?? '', ['outer', 'inner', 'partition'], true) ? $w['kind'] : 'inner'], $layout['walls']),
            'computers' => $computers,
            'furniture' => array_values(array_filter(array_map(fn ($f) => in_array($f['kind'] ?? '', self::FURNITURE, true)
                ? ['kind' => $f['kind'], 'pos' => $point($f['pos'] ?? null, 'furniture'), 'size' => $point($f['size'] ?? null, 'furniture')]
                : null, $layout['furniture']))),
        ];
    }
}
