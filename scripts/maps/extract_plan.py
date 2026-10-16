#!/usr/bin/env python3
"""Convert a vector floor plan PDF into a C+CLub club-map layout (JSON, metres).

The club's plan itself is private: keep the PDF and the produced JSON out of Git
and import the JSON into the club with `php artisan cclub:club-map <club> <file>`.

Conventions recognised in the drawing (colour-coded legend of the plan):
  * zone rectangles/polygons: thin-stroked filled shapes, coloured by zone type;
  * computers: small white boxes with a coloured stroke and a number inside;
  * outer wall: thick dark polyline; inner walls: grey strokes; doors: dashed orange;
  * furniture: brown = sofa, near-black = TV, orange diamond = speaker,
    pink = chair, dark red = table, beige circles = round tables.
Sizes are approximate: one PDF point is SCALE metres.

Usage: python3 scripts/maps/extract_plan.py plan.pdf layout.json [--scale 0.035]
"""
import argparse, json, math, re

import pymupdf

ZONE_TYPES = {
    (0.95, 0.94, 0.91): 'service', (0.88, 0.96, 0.93): 'platinum', (0.93, 0.93, 1.0): 'vip',
    (0.98, 0.93, 0.85): 'gold', (0.9, 0.95, 0.98): 'room', (0.99, 0.92, 0.92): 'mafia',
    (0.98, 0.93, 0.91): 'hookah', (0.98, 0.92, 0.94): 'stream', (0.92, 0.95, 0.87): 'exit',
}
PC_STROKES = {(0.06, 0.43, 0.34): 'platinum', (0.37, 0.37, 0.35): 'standard', (0.33, 0.29, 0.72): 'vip', (0.52, 0.31, 0.04): 'gold'}


def rgb(c):
    return tuple(round(v, 2) for v in c) if c else None


def points(drawing):
    out = []
    for item in drawing['items']:
        if item[0] == 'l': out += [item[1], item[2]]
        elif item[0] == 're': r = item[1]; out += [r.tl, r.tr, r.br, r.bl]
        elif item[0] == 'qu': q = item[1]; out += [q.ul, q.ur, q.lr, q.ll]
        elif item[0] == 'c': out += [item[1], item[4]]
    dedup = []
    for p in out:
        if not dedup or abs(dedup[-1].x - p.x) + abs(dedup[-1].y - p.y) > 0.5: dedup.append(p)
    if len(dedup) > 2 and abs(dedup[0].x - dedup[-1].x) + abs(dedup[0].y - dedup[-1].y) < 0.5: dedup.pop()
    return dedup


def segments(drawing):
    segs = []
    for item in drawing['items']:
        if item[0] == 'l': segs.append((item[1], item[2]))
        elif item[0] == 're':
            r = item[1]; c = [r.tl, r.tr, r.br, r.bl]; segs += [(c[i], c[(i + 1) % 4]) for i in range(4)]
    return segs


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('pdf'); ap.add_argument('out'); ap.add_argument('--scale', type=float, default=0.035)
    ap.add_argument('--name', default='Club map'); ap.add_argument('--legend-y', type=float, default=1440)
    a = ap.parse_args()
    page = pymupdf.open(a.pdf)[0]
    words = page.get_text('words')
    drawings = [d for d in page.get_drawings() if d['rect'].y0 < a.legend_y]
    def label_in(rect):
        found = [w for w in words if rect.x0 - 1 <= w[0] and w[2] <= rect.x1 + 1 and rect.y0 - 1 <= w[1] and w[3] <= rect.y1 + 1
                 and not re.fullmatch(r'\d+', w[4])]
        return ' '.join(w[4] for w in sorted(found, key=lambda w: (round(w[1] / 8), w[0])))

    zones, pcs, walls, doors, gaps, furniture = [], [], [], [], [], []
    for d in drawings:
        fill, stroke, width = rgb(d.get('fill')), rgb(d.get('color')), round(d.get('width') or 0, 1)
        kind = d['type']
        if kind == 'fs' and fill == (1.0, 1.0, 1.0) and width == 1.1 and stroke in PC_STROKES:
            P = points(d); cx = sum(p.x for p in P) / len(P); cy = sum(p.y for p in P) / len(P)
            num = [w[4] for w in words if w[0] - 2 <= cx <= w[2] + 2 and w[1] - 2 <= cy <= w[3] + 2 and re.fullmatch(r'\d+', w[4])]
            if not num: continue
            e = (P[1].x - P[0].x, P[1].y - P[0].y)
            angle = math.degrees(math.atan2(e[1], e[0])) % 90
            pcs.append({'n': int(num[0]), 'x': cx, 'y': cy, 'diag': 20 < angle < 70, 'tier': PC_STROKES[stroke]})
        elif kind == 'fs' and fill == (0.95, 0.94, 0.91) and d['rect'].width > 100 and d['rect'].height < 30:
            furniture.append(('bench', d['rect']))
        elif kind == 'fs' and fill == (0.95, 0.94, 0.91) and d['rect'].width < 30 and any(i[0] == 'c' for i in d['items']):
            furniture.append(('round_table', d['rect']))
        elif kind == 'fs' and width in (0.9, 0.8) and fill in ZONE_TYPES and d['rect'].width > 40:
            P = points(d)
            zones.append({'type': ZONE_TYPES[fill], 'poly': [(p.x, p.y) for p in P], 'label': label_in(d['rect']), 'rect': d['rect']})
        elif kind == 's' and stroke == (0.17, 0.17, 0.16):
            walls += [(s, 'outer') for s in segments(d)]
        elif kind == 's' and stroke == (0.37, 0.37, 0.35) and width == 1.9:
            walls += [(s, 'inner') for s in segments(d)]
        elif kind == 's' and stroke == (0.85, 0.35, 0.19):
            doors += segments(d)
        elif kind == 's' and stroke == (1.0, 1.0, 1.0) and width >= 5:
            gaps += segments(d)
        elif kind == 'f' and fill == (0.54, 0.35, 0.23):
            furniture.append(('sofa', d['rect']))
        elif kind == 'f' and fill == (0.12, 0.14, 0.19):
            furniture.append(('tv', d['rect']))
        elif kind == 'f' and fill == (0.64, 0.18, 0.18):
            furniture.append(('table', d['rect']))
        elif kind == 'fs' and fill == (0.97, 0.76, 0.76):
            furniture.append(('chair', d['rect']))
        elif kind == 'fs' and fill == (0.94, 0.62, 0.15):
            furniture.append(('speaker', d['rect']))
        elif kind == 'f' and fill == (0.17, 0.17, 0.16) and d['rect'].height < 20:
            furniture.append(('tv', d['rect']))
        elif kind == 'f' and fill == (0.17, 0.17, 0.16):
            P = points(d); zones.append({'type': 'closed', 'poly': [(p.x, p.y) for p in P], 'label': 'Yopiq xona', 'rect': d['rect']})
        # zone outlines also act as partitions
        if kind == 'fs' and width in (0.9, 0.8) and fill in ZONE_TYPES and d['rect'].width > 40 and d['rect'].height >= 30:
            walls += [(s, 'partition') for s in segments(d)]

    # Bounding box of the building from the outer wall.
    xs = [p.x for (s, k) in walls if k == 'outer' for p in s]; ys = [p.y for (s, k) in walls if k == 'outer' for p in s]
    ox, oy, S = min(xs), min(ys), a.scale
    M = lambda x, y: [round((x - ox) * S, 3), round((y - oy) * S, 3)]

    def cut(seg, holes):
        """Split a wall segment where door/gap strokes overlap it (door openings)."""
        (p, q) = seg; L = math.hypot(q.x - p.x, q.y - p.y)
        if L < 1e-6: return []
        ux, uy = (q.x - p.x) / L, (q.y - p.y) / L
        spans = []
        for (a1, b1) in holes:
            # perpendicular distance of hole endpoints to the wall line
            d1 = abs((a1.x - p.x) * uy - (a1.y - p.y) * ux); d2 = abs((b1.x - p.x) * uy - (b1.y - p.y) * ux)
            if d1 > 4 or d2 > 4: continue
            t1 = (a1.x - p.x) * ux + (a1.y - p.y) * uy; t2 = (b1.x - p.x) * ux + (b1.y - p.y) * uy
            lo, hi = max(0, min(t1, t2) - 3), min(L, max(t1, t2) + 3)
            if hi > lo: spans.append((lo, hi))
        spans.sort(); pieces, t = [], 0
        for lo, hi in spans:
            if lo > t: pieces.append((t, lo))
            t = max(t, hi)
        if t < L: pieces.append((t, L))
        return [((p.x + ux * s0, p.y + uy * s0), (p.x + ux * s1, p.y + uy * s1)) for s0, s1 in pieces if s1 - s0 > 2]

    holes = doors + gaps
    wall_out, seen = [], set()
    for seg, kind in walls:
        for (a1, b1) in cut(seg, holes):
            key = tuple(round(v / 3) for v in (*a1, *b1)); rkey = tuple(round(v / 3) for v in (*b1, *a1))
            if key in seen or rkey in seen: continue
            seen.add(key)
            wall_out.append({'a': M(*a1), 'b': M(*b1), 'kind': kind})

    # Unfilled standard hall: label "Standart" + numbers, bounded by the computers it contains.
    std = [p for p in pcs if p['tier'] == 'standard']
    if std:
        hall_label = next((w for w in words if w[4].startswith('Standart')), None)
        x0 = min(p['x'] for p in std) - 14; x1 = max(p['x'] for p in std) + 30; y0 = (hall_label[1] - 8) if hall_label else min(p['y'] for p in std) - 30; y1 = max(p['y'] for p in std) + 24
        zones.append({'type': 'standard', 'poly': [(x0, y0), (x1, y0), (x1, y1), (x0, y1)], 'label': 'Standart 1–40', 'rect': pymupdf.Rect(x0, y0, x1, y1)})

    # Computers: facing. Back-to-back partners face each other; others face the nearest wall of their zone.
    def zone_of(x, y):
        best = None
        for z in zones:
            r = z['rect']
            if r.x0 - 2 <= x <= r.x1 + 2 and r.y0 - 2 <= y <= r.y1 + 2 and z['type'] not in ('service', 'exit', 'closed'):
                if not best or r.width * r.height < best['rect'].width * best['rect'].height: best = z
        return best

    out_pcs = []
    for p in pcs:
        partner = min((q for q in pcs if q is not p), key=lambda q: math.hypot(q['x'] - p['x'], q['y'] - p['y']))
        dist = math.hypot(partner['x'] - p['x'], partner['y'] - p['y'])
        z = zone_of(p['x'], p['y'])
        if p['diag']:
            # diagonal rows run along the 45° outer wall on their lower-left side: face that wall
            fx, fy = (-0.7071, 0.7071)
        elif dist < 24 and p['tier'] in ('standard', 'platinum') and partner['tier'] == p['tier']:
            fx, fy = (partner['x'] - p['x']) / dist, (partner['y'] - p['y']) / dist
        elif z:
            # Rows face the nearer long wall: horizontal rows face up/down, columns face left/right.
            r = z['rect']
            row = sum(1 for q in pcs if q is not p and abs(q['y'] - p['y']) < 3 and abs(q['x'] - p['x']) < 60)
            col = sum(1 for q in pcs if q is not p and abs(q['x'] - p['x']) < 3 and abs(q['y'] - p['y']) < 60)
            if row >= col:
                cand = [(p['y'] - r.y0, (0, -1)), (r.y1 - p['y'], (0, 1))]
            else:
                cand = [(p['x'] - r.x0, (-1, 0)), (r.x1 - p['x'], (1, 0))]
            fx, fy = min(cand)[1]
        else:
            fx, fy = (0, -1)
        out_pcs.append({'number': p['n'], 'tier': p['tier'], 'pos': M(p['x'], p['y']), 'facing': [round(fx, 3), round(fy, 3)],
                        'zone': None})

    # Zone ids and membership.
    FALLBACK = {'mafia': 'Mafiya xona (Karaoke)', 'exit': 'Zinapoya', 'hookah': 'Kalyan xona'}
    out_zones = []
    for i, z in enumerate(zones):
        label = re.sub(r'\s+', ' ', z['label']).strip()
        if z['type'] in FALLBACK and (not label or label in ('Tex',)):
            label = FALLBACK[z['type']]
        label = label.replace('Chiqish', 'Chiqish 2') if label == 'Chiqish' else label
        zid = re.sub(r'[^a-z0-9]+', '-', (z['type'] + '-' + label).lower()).strip('-') or f"{z['type']}-{i}"
        r = z['rect']
        out_zones.append({'id': zid, 'type': z['type'], 'label': label, 'poly': [M(x, y) for x, y in z['poly']],
                          'center': M((r.x0 + r.x1) / 2, (r.y0 + r.y1) / 2)})
    for pc, p in zip(out_pcs, pcs):
        z = zone_of(p['x'], p['y'])
        if z: pc['zone'] = out_zones[zones.index(z)]['id']

    furn = []
    for kind, r in furniture:
        w, h = r.width * S, r.height * S
        furn.append({'kind': kind, 'pos': M((r.x0 + r.x1) / 2, (r.y0 + r.y1) / 2), 'size': [round(w, 3), round(h, 3)]})

    # The building outline (floor) from the outer wall polyline, in drawing order.
    outer = next((d for d in drawings if rgb(d.get('color')) == (0.17, 0.17, 0.16) and d['type'] == 's'), None)
    floor = [M(p.x, p.y) for p in points(outer)] if outer else []

    layout = {'version': 1, 'name': a.name, 'units': 'm', 'scale_m_per_pt': S, 'wall_height': 2.7,
              'size': M(max(xs), max(ys)), 'floor': floor, 'zones': out_zones, 'walls': wall_out,
              'computers': sorted(out_pcs, key=lambda p: p['number']), 'furniture': furn}
    json.dump(layout, open(a.out, 'w'), ensure_ascii=False, separators=(',', ':'))
    print(f"zones={len(out_zones)} walls={len(wall_out)} computers={len(out_pcs)} furniture={len(furn)} size={layout['size']}")


if __name__ == '__main__':
    main()
