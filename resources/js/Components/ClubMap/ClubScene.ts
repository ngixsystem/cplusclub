import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';
import { CSS2DObject, CSS2DRenderer } from 'three/addons/renderers/CSS2DRenderer.js';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';

export type Pt = [number, number];
export type Zone = { id: string; type: string; label: string; poly: Pt[]; center: Pt };
export type Computer = { number: number; zone: string | null; tier: string; pos: Pt; facing: Pt };
export type Layout = {
    version: 1; name: string; wall_height: number; size: Pt; floor: Pt[]; zones: Zone[];
    walls: { a: Pt; b: Pt; kind: 'outer' | 'inner' | 'partition' }[]; computers: Computer[];
    furniture: { kind: string; pos: Pt; size: Pt }[];
};
export type PcState = 'free' | 'busy' | 'offline' | 'unknown';
export type Hover = { kind: 'pc'; number: number } | { kind: 'zone'; id: string } | null;

/** Zone accents: neon strips, floor tint and labels. Status colours are separate (see STATUS). */
export const ACCENT: Record<string, string> = {
    standard: '#2dd4bf', platinum: '#34d399', vip: '#a78bfa', gold: '#fbbf24', room: '#60a5fa',
    mafia: '#fb7185', hookah: '#fb923c', stream: '#f472b6', exit: '#a3e635', service: '#71717a', closed: '#18181b',
};
export const STATUS: Record<PcState, string> = { free: '#22c55e', busy: '#6d8bff', offline: '#3f3f46', unknown: '#71717a' };
const ENTERABLE = new Set(['standard', 'platinum', 'vip', 'gold', 'room', 'mafia', 'hookah', 'stream']);
const EYE = 1.72;

const ease = (t: number) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

type Callbacks = { onZone: (id: string | null) => void; onHover: (h: Hover, x: number, y: number) => void; onSelect: (n: number | null) => void };

export class ClubScene {
    private renderer: THREE.WebGLRenderer;
    private labels: CSS2DRenderer;
    private scene = new THREE.Scene();
    private camera: THREE.PerspectiveCamera;
    private controls: OrbitControls;
    private composer: EffectComposer;
    private bloom: UnrealBloomPass;
    private raf = 0;
    private flight: { from: THREE.Vector3; to: THREE.Vector3; fromT: THREE.Vector3; toT: THREE.Vector3; start: number; ms: number; done: () => void } | null = null;
    private zoneMeshes: THREE.Mesh[] = [];
    private zoneById = new Map<string, Zone>();
    private zoneLabels = new Map<string, { el: HTMLDivElement; count: HTMLSpanElement }>();
    private pcLabels: { zone: string | null; obj: CSS2DObject }[] = [];
    private pcIndex = new Map<number, number>();
    private screens!: THREE.InstancedMesh;
    private leds!: THREE.InstancedMesh;
    private rings!: THREE.InstancedMesh;
    private pickables: THREE.Object3D[] = [];
    private current: string | null = null;
    private zoneLight = new THREE.PointLight('#ffffff', 0, 18, 1.4);
    private hovered: Hover = null;
    private selected: number | null = null;
    private resize: ResizeObserver;
    private down = { x: 0, y: 0, t: 0 };
    private overview: { pos: THREE.Vector3; target: THREE.Vector3 };
    private reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    private disposed = false;
    private offset: THREE.Vector2;

    constructor(private host: HTMLElement, private layout: Layout, private cb: Callbacks) {
        const w = host.clientWidth || 800, h = host.clientHeight || 600;
        this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 1.75));
        this.renderer.setSize(w, h);
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 1.05;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        this.renderer.domElement.className = 'club-canvas';
        host.appendChild(this.renderer.domElement);

        this.labels = new CSS2DRenderer();
        this.labels.setSize(w, h);
        this.labels.domElement.className = 'club-labels';
        host.appendChild(this.labels.domElement);

        this.offset = new THREE.Vector2(layout.size[0] / 2, layout.size[1] / 2);
        const span = Math.max(layout.size[0], layout.size[1]);
        this.scene.background = new THREE.Color('#07070a');
        this.scene.fog = new THREE.Fog('#07070a', span * 1.6, span * 3.2);
        const pmrem = new THREE.PMREMGenerator(this.renderer);
        this.scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        this.scene.environmentIntensity = 0.18;

        this.camera = new THREE.PerspectiveCamera(42, w / h, 0.05, span * 6);
        this.overview = { pos: new THREE.Vector3(), target: new THREE.Vector3(0, 0, span * 0.04) };
        this.fitOverview(w / h);
        this.camera.position.copy(this.overview.pos);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        this.controls.target.copy(this.overview.target);
        this.controls.enableDamping = true;
        this.controls.dampingFactor = 0.08;
        this.overviewControls();

        this.composer = new EffectComposer(this.renderer);
        this.composer.addPass(new RenderPass(this.scene, this.camera));
        this.bloom = new UnrealBloomPass(new THREE.Vector2(w, h), 0.8, 0.5, 0.82);
        this.composer.addPass(this.bloom);
        this.composer.addPass(new OutputPass());

        this.build();
        this.bindEvents();
        this.resize = new ResizeObserver(() => this.onResize());
        this.resize.observe(host);
        this.loop();
    }

    /* ------------------------------------------------------------------ build */

    private v(p: Pt, y = 0) { return new THREE.Vector3(p[0] - this.offset.x, y, p[1] - this.offset.y); }

    private shape(poly: Pt[]) {
        const s = new THREE.Shape();
        poly.forEach((p, i) => { const x = p[0] - this.offset.x, z = p[1] - this.offset.y; i ? s.lineTo(x, -z) : s.moveTo(x, -z); });
        return s;
    }

    private build() {
        const L = this.layout, H = L.wall_height;
        const hemi = new THREE.HemisphereLight('#a5b4fc', '#111114', 0.8);
        this.scene.add(this.zoneLight);
        const key = new THREE.DirectionalLight('#fff4e6', 1.25);
        const span = Math.max(L.size[0], L.size[1]);
        key.position.set(-span * 0.35, span * 0.9, span * 0.25);
        key.castShadow = true;
        key.shadow.mapSize.set(2048, 2048);
        Object.assign(key.shadow.camera, { left: -span * 0.6, right: span * 0.6, top: span * 0.6, bottom: -span * 0.6, near: 1, far: span * 3 });
        key.shadow.bias = -0.0004;
        this.scene.add(hemi, key, new THREE.AmbientLight('#ffffff', 0.12));

        // Ground plane outside the building and the building floor.
        const ground = new THREE.Mesh(new THREE.PlaneGeometry(span * 6, span * 6), new THREE.MeshStandardMaterial({ color: '#060608', roughness: 1 }));
        ground.rotation.x = -Math.PI / 2; ground.position.y = -0.02; ground.receiveShadow = true;
        this.scene.add(ground);
        if (L.floor.length > 2) {
            const floor = new THREE.Mesh(new THREE.ShapeGeometry(this.shape(L.floor)), new THREE.MeshStandardMaterial({ color: '#17171b', roughness: 0.82, metalness: 0.08 }));
            floor.rotation.x = -Math.PI / 2; floor.receiveShadow = true;
            this.scene.add(floor);
        }

        // Zones: tinted floors (pickable), neon strips at floor and ceiling height, labels.
        for (const z of L.zones) {
            this.zoneById.set(z.id, z);
            const accent = new THREE.Color(ACCENT[z.type] ?? '#71717a');
            if (z.type === 'closed') {
                const geo = new THREE.ExtrudeGeometry(this.shape(z.poly), { depth: H, bevelEnabled: false });
                const block = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({ color: '#0b0b0d', roughness: 0.9 }));
                block.rotation.x = -Math.PI / 2; block.castShadow = true;
                this.scene.add(block);
                continue;
            }
            const tint = new THREE.Color('#151518').lerp(accent, z.type === 'service' ? 0.04 : 0.1);
            const mat = new THREE.MeshStandardMaterial({ color: tint, roughness: 0.55, metalness: 0.15, emissive: accent, emissiveIntensity: 0 });
            const mesh = new THREE.Mesh(new THREE.ShapeGeometry(this.shape(z.poly)), mat);
            mesh.rotation.x = -Math.PI / 2; mesh.position.y = 0.006; mesh.receiveShadow = true;
            mesh.userData = { zone: z.id };
            this.scene.add(mesh);
            if (ENTERABLE.has(z.type)) { this.zoneMeshes.push(mesh); this.pickables.push(mesh); }

            if (z.type !== 'service') this.neonOutline(z.poly, accent, H);
            this.zoneLabel(z);
        }

        // Walls with darker sides and a lighter top so the plan reads from above.
        const side = new THREE.MeshStandardMaterial({ color: '#1f1f24', roughness: 0.78, metalness: 0.05 });
        const top = new THREE.MeshStandardMaterial({ color: '#4a4a52', roughness: 0.6 });
        const mats = [side, side, top, side, side, side];
        for (const w of L.walls) {
            const a = this.v(w.a), b = this.v(w.b);
            const len = a.distanceTo(b);
            if (len < 0.05) continue;
            const t = w.kind === 'outer' ? 0.28 : w.kind === 'inner' ? 0.14 : 0.09;
            const m = new THREE.Mesh(new THREE.BoxGeometry(len + t * 0.6, H, t), mats);
            m.position.copy(a).add(b).multiplyScalar(0.5); m.position.y = H / 2;
            m.rotation.y = -Math.atan2(b.z - a.z, b.x - a.x);
            m.castShadow = true; m.receiveShadow = true;
            this.scene.add(m);
        }

        this.buildComputers();
        this.buildFurniture();
    }

    private neonOutline(poly: Pt[], color: THREE.Color, H: number) {
        const strong = new THREE.MeshBasicMaterial({ color: color.clone().multiplyScalar(2.2), toneMapped: false });
        const soft = new THREE.MeshBasicMaterial({ color: color.clone().multiplyScalar(0.9), toneMapped: false, transparent: true, opacity: 0.85 });
        const center = poly.reduce((c, p) => [c[0] + p[0] / poly.length, c[1] + p[1] / poly.length], [0, 0]);
        poly.forEach((p, i) => {
            const q = poly[(i + 1) % poly.length];
            // inset towards the zone centre so strips sit inside the walls
            const ins = (r: Pt): Pt => { const dx = center[0] - r[0], dz = center[1] - r[1], d = Math.hypot(dx, dz) || 1; return [r[0] + dx / d * 0.16, r[1] + dz / d * 0.16]; };
            const a = this.v(ins(p)), b = this.v(ins(q)), len = a.distanceTo(b);
            if (len < 0.3) return;
            for (const [y, mat, th] of [[H - 0.08, strong, 0.035], [0.03, soft, 0.025]] as [number, THREE.Material, number][]) {
                const strip = new THREE.Mesh(new THREE.BoxGeometry(len, th, th), mat);
                strip.position.copy(a).add(b).multiplyScalar(0.5); strip.position.y = y;
                strip.rotation.y = -Math.atan2(b.z - a.z, b.x - a.x);
                this.scene.add(strip);
            }
        });
    }

    private zoneLabel(z: Zone) {
        if (!ENTERABLE.has(z.type) && z.type !== 'service' && z.type !== 'exit') return;
        const el = document.createElement('div');
        el.className = `zone-label zone-${z.type}${ENTERABLE.has(z.type) ? ' is-enterable' : ''}`;
        el.style.setProperty('--accent', ACCENT[z.type] ?? '#71717a');
        const name = document.createElement('b'); name.textContent = this.prettyLabel(z.label);
        const count = document.createElement('span');
        el.append(name, count);
        if (ENTERABLE.has(z.type)) {
            el.tabIndex = 0; el.setAttribute('role', 'button'); el.setAttribute('aria-label', 'Войти в зону ' + name.textContent);
            el.addEventListener('click', e => { e.stopPropagation(); this.flyToZone(z.id); });
            el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.flyToZone(z.id); } });
        }
        const obj = new CSS2DObject(el);
        obj.position.copy(this.v(z.center, this.layout.wall_height + 0.6));
        this.scene.add(obj);
        this.zoneLabels.set(z.id, { el, count });
    }

    prettyLabel(label: string) { return label.replace(/(\d+)\s*[–-]\s*(\d+)/, '$1–$2').replace(/^(.)/, s => s.toUpperCase()); }

    private buildComputers() {
        const pcs = this.layout.computers, n = pcs.length;
        const box = (w: number, h: number, d: number) => new THREE.BoxGeometry(w, h, d);
        const dark = new THREE.MeshStandardMaterial({ color: '#141417', roughness: 0.45, metalness: 0.35 });
        const desk = new THREE.MeshStandardMaterial({ color: '#2a2a31', roughness: 0.38, metalness: 0.25 });
        const chairMat = new THREE.MeshStandardMaterial({ color: '#ffffff', roughness: 0.6, metalness: 0.1 });
        const screenTex = this.screenTexture();
        const parts: { geo: THREE.BufferGeometry; mat: THREE.Material; local: THREE.Matrix4; shadow?: boolean; key?: 'screens' | 'leds' | 'rings' | 'chair' }[] = [
            { geo: box(1.0, 0.04, 0.62), mat: desk, local: new THREE.Matrix4().makeTranslation(0, 0.74, 0), shadow: true },
            { geo: box(0.94, 0.7, 0.03), mat: dark, local: new THREE.Matrix4().makeTranslation(0, 0.37, 0.28), shadow: true },
            { geo: box(0.04, 0.72, 0.56), mat: dark, local: new THREE.Matrix4().makeTranslation(-0.47, 0.36, 0) },
            { geo: box(0.04, 0.72, 0.56), mat: dark, local: new THREE.Matrix4().makeTranslation(0.47, 0.36, 0) },
            { geo: box(0.62, 0.37, 0.04), mat: dark, local: new THREE.Matrix4().makeTranslation(0, 1.06, 0.2), shadow: true },
            { geo: box(0.05, 0.26, 0.05), mat: dark, local: new THREE.Matrix4().makeTranslation(0, 0.88, 0.23) },
            { geo: box(0.44, 0.02, 0.14), mat: dark, local: new THREE.Matrix4().makeTranslation(0, 0.77, -0.08) },
            { geo: box(0.22, 0.44, 0.44), mat: dark, local: new THREE.Matrix4().makeTranslation(0.36, 0.98, 0.1), shadow: true },
            { geo: box(0.52, 0.1, 0.5), mat: chairMat, local: new THREE.Matrix4().makeTranslation(0, 0.5, -0.56), shadow: true, key: 'chair' },
            { geo: box(0.5, 0.7, 0.09), mat: chairMat, local: new THREE.Matrix4().makeRotationX(-0.12).premultiply(new THREE.Matrix4().makeTranslation(0, 0.92, -0.82)), shadow: true, key: 'chair' },
            { geo: box(0.08, 0.42, 0.08), mat: dark, local: new THREE.Matrix4().makeTranslation(0, 0.24, -0.56) },
            { geo: new THREE.PlaneGeometry(0.58, 0.33), mat: new THREE.MeshBasicMaterial({ map: screenTex, toneMapped: false }),
                local: new THREE.Matrix4().makeRotationY(Math.PI).premultiply(new THREE.Matrix4().makeTranslation(0, 1.06, 0.177)), key: 'screens' },
            { geo: box(1.0, 0.018, 0.018), mat: new THREE.MeshBasicMaterial({ toneMapped: false }), local: new THREE.Matrix4().makeTranslation(0, 0.71, -0.31), key: 'leds' },
            { geo: new THREE.RingGeometry(0.34, 0.4, 40), mat: new THREE.MeshBasicMaterial({ toneMapped: false, transparent: true, opacity: 0.7, side: THREE.DoubleSide, depthWrite: false }),
                local: new THREE.Matrix4().makeRotationX(-Math.PI / 2).premultiply(new THREE.Matrix4().makeTranslation(0, 0.02, -0.22)), key: 'rings' },
        ];
        const base = new THREE.Matrix4(), q = new THREE.Quaternion(), m = new THREE.Matrix4(), up = new THREE.Vector3(0, 1, 0), one = new THREE.Vector3(1, 1, 1);
        for (const part of parts) {
            const inst = new THREE.InstancedMesh(part.geo, part.mat, n);
            pcs.forEach((pc, i) => {
                q.setFromAxisAngle(up, Math.atan2(pc.facing[0], pc.facing[1]));
                base.compose(this.v(pc.pos), q, one);
                inst.setMatrixAt(i, m.multiplyMatrices(base, part.local));
            });
            inst.castShadow = !!part.shadow; inst.receiveShadow = true;
            inst.userData = { pcs: true };
            if (part.key === 'chair') {
                // Gaming chairs take the zone's accent, like the reference renders.
                pcs.forEach((pc, i) => {
                    const type = pc.zone ? this.zoneById.get(pc.zone)?.type : null;
                    inst.setColorAt(i, new THREE.Color('#2b2b31').lerp(new THREE.Color(ACCENT[type ?? ''] ?? '#52525b'), 0.3));
                });
            } else if (part.key) {
                for (let i = 0; i < n; i++) inst.setColorAt(i, new THREE.Color(STATUS.unknown));
                this[part.key] = inst;
            }
            this.scene.add(inst);
            this.pickables.push(inst);
        }
        pcs.forEach((pc, i) => {
            this.pcIndex.set(pc.number, i);
            const el = document.createElement('div');
            el.className = 'pc-label'; el.textContent = String(pc.number);
            const obj = new CSS2DObject(el);
            obj.position.copy(this.v(pc.pos, 1.42)).add(new THREE.Vector3(pc.facing[0] * 0.2, 0, pc.facing[1] * 0.2));
            obj.visible = false;
            this.scene.add(obj);
            this.pcLabels.push({ zone: pc.zone, obj });
        });
    }

    /** Stylised in-game frame; instance colour tints it per status (green idle, blue in game, dark when off). */
    private screenTexture() {
        const c = document.createElement('canvas'); c.width = 128; c.height = 72;
        const g = c.getContext('2d')!;
        const grd = g.createLinearGradient(0, 0, 128, 72);
        grd.addColorStop(0, '#ffffff'); grd.addColorStop(0.55, '#b9c6ff'); grd.addColorStop(1, '#5b62a8');
        g.fillStyle = grd; g.fillRect(0, 0, 128, 72);
        g.fillStyle = 'rgba(255,255,255,.55)';
        for (let i = 0; i < 6; i++) g.fillRect(8 + i * 19, 52 - (i % 3) * 9, 10, 20 + (i % 3) * 9);
        g.fillStyle = 'rgba(10,10,30,.35)'; g.fillRect(0, 62, 128, 10);
        const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace;
        return t;
    }

    private buildFurniture() {
        const mat = (color: string, extra: Partial<THREE.MeshStandardMaterialParameters> = {}) => new THREE.MeshStandardMaterial({ color, roughness: 0.7, ...extra });
        const sofa = mat('#3a2a22'), cushion = mat('#4c372c'), tvBody = mat('#09090b', { metalness: 0.4, roughness: 0.3 });
        const tvScreen = new THREE.MeshBasicMaterial({ color: '#3b52d6', toneMapped: false });
        const table = mat('#5b1f24', { roughness: 0.45 }), chair = mat('#7f1d2a'), speaker = mat('#1a1a1d'), wood = mat('#2a2a30');
        const add = (geo: THREE.BufferGeometry, m: THREE.Material, p: THREE.Vector3, rotY = 0) => {
            const mesh = new THREE.Mesh(geo, m); mesh.position.copy(p); mesh.rotation.y = rotY; mesh.castShadow = true; mesh.receiveShadow = true; this.scene.add(mesh); return mesh;
        };
        for (const f of this.layout.furniture) {
            const [w, d] = f.size, long = Math.max(w, d), short = Math.min(w, d), rot = w >= d ? 0 : Math.PI / 2;
            const p = this.v(f.pos);
            if (f.kind === 'sofa') {
                const depth = Math.max(short, 0.8);
                add(new THREE.BoxGeometry(long, 0.42, depth), sofa, p.clone().setY(0.21), rot);
                add(new THREE.BoxGeometry(long, 0.18, depth * 0.85), cushion, p.clone().setY(0.5), rot);
            } else if (f.kind === 'tv') {
                const width = Math.max(long, 1.2);
                add(new THREE.BoxGeometry(width, width * 0.56, 0.08), tvBody, p.clone().setY(1.55), rot);
                const scr = add(new THREE.BoxGeometry(width * 0.94, width * 0.5, 0.09), tvScreen, p.clone().setY(1.55), rot);
                scr.castShadow = false;
            } else if (f.kind === 'table') {
                add(new THREE.BoxGeometry(Math.max(long, 1.2), 0.06, Math.max(short, 0.7)), table, p.clone().setY(0.74), rot);
                add(new THREE.BoxGeometry(0.1, 0.72, 0.1), wood, p.clone().setY(0.36));
            } else if (f.kind === 'chair') {
                add(new THREE.BoxGeometry(0.42, 0.08, 0.42), chair, p.clone().setY(0.46));
                add(new THREE.BoxGeometry(0.06, 0.44, 0.06), wood, p.clone().setY(0.22));
            } else if (f.kind === 'speaker') {
                add(new THREE.BoxGeometry(0.3, 1.0, 0.3), speaker, p.clone().setY(0.5));
                const led = add(new THREE.BoxGeometry(0.31, 0.03, 0.31), new THREE.MeshBasicMaterial({ color: '#f59e0b', toneMapped: false }), p.clone().setY(0.98));
                led.castShadow = false;
            } else if (f.kind === 'round_table') {
                add(new THREE.CylinderGeometry(Math.max(long / 2, 0.35), Math.max(long / 2, 0.35), 0.05, 32), wood, p.clone().setY(0.74));
                add(new THREE.CylinderGeometry(0.05, 0.05, 0.72, 12), speaker, p.clone().setY(0.36));
            } else if (f.kind === 'bench') {
                add(new THREE.BoxGeometry(long, 0.45, Math.max(short, 0.5)), wood, p.clone().setY(0.225), rot);
            }
        }
    }

    /* ----------------------------------------------------------------- state */

    setStatus(states: Map<number, PcState>) {
        const c = new THREE.Color();
        const screenTint: Record<PcState, string> = { busy: '#9fb2ff', free: '#3fdc8a', offline: '#060607', unknown: '#202024' };
        const ledTint: Record<PcState, string> = { busy: '#7c8cff', free: '#22c55e', offline: '#2a0f12', unknown: '#2a2a2e' };
        this.layout.computers.forEach((pc, i) => {
            const s = states.get(pc.number) ?? 'unknown';
            this.screens.setColorAt(i, c.set(screenTint[s]));
            this.leds.setColorAt(i, c.set(ledTint[s]).multiplyScalar(s === 'offline' || s === 'unknown' ? 1 : 2.4));
            this.rings.setColorAt(i, c.set(STATUS[s]).multiplyScalar(s === 'offline' || s === 'unknown' ? 0.5 : 1.6));
        });
        for (const m of [this.screens, this.leds, this.rings]) if (m.instanceColor) m.instanceColor.needsUpdate = true;
    }

    setZoneCounts(counts: Map<string, { busy: number; total: number }>) {
        for (const [id, label] of this.zoneLabels) {
            const c = counts.get(id);
            label.count.textContent = c && c.total ? `занято ${c.busy} из ${c.total}` : '';
        }
    }

    /* ---------------------------------------------------------------- camera */

    private overviewControls() {
        const span = Math.max(this.layout.size[0], this.layout.size[1]);
        Object.assign(this.controls, { enablePan: true, enableZoom: true, enableRotate: true, rotateSpeed: 0.6, minDistance: span * 0.25, maxDistance: span * 4,
            minPolarAngle: 0.15, maxPolarAngle: 1.22, screenSpacePanning: false });
        this.camera.fov = 42; this.camera.updateProjectionMatrix();
    }

    private lookControls() {
        Object.assign(this.controls, { enablePan: false, enableZoom: false, enableRotate: true, rotateSpeed: -0.32, minDistance: 0.12, maxDistance: 0.12,
            minPolarAngle: 0.45, maxPolarAngle: 2.3 });
    }

    private fly(pos: THREE.Vector3, target: THREE.Vector3, done: () => void) {
        this.controls.enabled = false;
        if (this.reduced) { this.camera.position.copy(pos); this.controls.target.copy(target); this.controls.enabled = true; done(); return; }
        // Widen the limits during the flight so OrbitControls does not clamp the path.
        Object.assign(this.controls, { minDistance: 0, maxDistance: Infinity, minPolarAngle: 0, maxPolarAngle: Math.PI });
        this.flight = { from: this.camera.position.clone(), to: pos, fromT: this.controls.target.clone(), toT: target, start: performance.now(), ms: 1300, done };
    }

    flyToZone(id: string) {
        const z = this.zoneById.get(id);
        if (!z || !ENTERABLE.has(z.type)) return;
        const inZone = this.layout.computers.filter(p => p.zone === id);
        const xs = z.poly.map(p => p[0]), ys = z.poly.map(p => p[1]);
        const [x0, x1, y0, y1] = [Math.min(...xs) + 0.4, Math.max(...xs) - 0.4, Math.min(...ys) + 0.4, Math.max(...ys) - 0.4];
        const pcs = inZone.map(p => this.v(p.pos));
        const centroid = pcs.length ? pcs.reduce((s, p) => s.add(p), new THREE.Vector3()).multiplyScalar(1 / pcs.length) : this.v(z.center);
        const facing = inZone.reduce((s, p) => s.add(new THREE.Vector3(p.facing[0], 0, p.facing[1])), new THREE.Vector3());
        const consistency = inZone.length ? facing.length() / inZone.length : 0;
        facing.normalize();
        // Stand where nothing is in the way (corners and wall middles), preferring the spot
        // behind the players so the screens are in view, like a camera mounted in the room.
        let pos = this.v(z.center), best = -Infinity;
        for (const fx of [0, 0.5, 1]) for (const fy of [0, 0.5, 1]) {
            if (fx === 0.5 && fy === 0.5 && pcs.length) continue;
            const cand = this.v([x0 + (x1 - x0) * fx, y0 + (y1 - y0) * fy]);
            const clear = pcs.length ? Math.min(...pcs.map(p => p.distanceTo(cand))) : 1;
            const toC = centroid.clone().sub(cand).setY(0);
            const behind = toC.length() > 0.01 ? Math.max(0, toC.normalize().dot(facing)) * consistency : 0;
            const score = Math.min(clear, 2.2) + behind * 1.6 + centroid.distanceTo(cand) * 0.08;
            if (score > best) { best = score; pos = cand; }
        }
        const eye = pcs.length ? 2.05 : EYE;
        pos.y = eye;
        const look = pcs.length ? centroid.clone().setY(0.9) : this.v(z.center, eye - 0.3);
        if (look.clone().setY(0).distanceTo(pos.clone().setY(0)) < 0.5) look.add(new THREE.Vector3(0.5, 0, 0));
        const target = pos.clone().addScaledVector(look.sub(pos).normalize(), 0.12);
        this.zoneLight.color.set('#ffffff').lerp(new THREE.Color(ACCENT[z.type] ?? '#ffffff'), 0.3);
        this.zoneLight.position.copy(this.v(z.center, this.layout.wall_height - 0.25));
        this.zoneLight.intensity = 9;
        this.current = id;
        this.cb.onZone(id);
        this.showPcLabels(id);
        this.fly(pos, target, () => this.lookControls());
    }

    flyToOverview() {
        this.current = null;
        this.zoneLight.intensity = 0;
        this.cb.onZone(null);
        this.select(null);
        this.showPcLabels(null);
        this.fly(this.overview.pos.clone(), this.overview.target.clone(), () => this.overviewControls());
    }

    select(n: number | null) {
        this.selected = n;
        this.pcLabels.forEach(({ obj }, i) => obj.element.classList.toggle('is-selected', this.layout.computers[i].number === n));
        this.cb.onSelect(n);
    }

    private showPcLabels(zone: string | null) {
        for (const l of this.pcLabels) l.obj.visible = zone !== null && l.zone === zone;
        for (const [id, l] of this.zoneLabels) l.el.classList.toggle('is-dim', zone !== null && id !== zone);
        for (const [id, l] of this.zoneLabels) l.el.classList.toggle('is-hidden', zone !== null && id === zone);
    }

    /* ---------------------------------------------------------------- events */

    private ray = new THREE.Raycaster();
    private ndc = new THREE.Vector2();

    private pick(ev: PointerEvent): Hover {
        const r = this.renderer.domElement.getBoundingClientRect();
        this.ndc.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
        this.ray.setFromCamera(this.ndc, this.camera);
        for (const hit of this.ray.intersectObjects(this.pickables, false)) {
            if (hit.object instanceof THREE.InstancedMesh && hit.instanceId !== undefined) return { kind: 'pc', number: this.layout.computers[hit.instanceId].number };
            if (hit.object.userData.zone) return { kind: 'zone', id: hit.object.userData.zone };
        }
        return null;
    }

    private onMove = (ev: PointerEvent) => {
        if (this.flight || ev.buttons) return;
        const h = this.pick(ev);
        const zoneOf = (x: Hover) => (x?.kind === 'zone' ? x.id : x?.kind === 'pc' ? this.layout.computers[this.pcIndex.get(x.number)!].zone : null);
        const prevZone = zoneOf(this.hovered), nextZone = this.current ? null : zoneOf(h);
        if (prevZone !== nextZone) {
            for (const m of this.zoneMeshes) (m.material as THREE.MeshStandardMaterial).emissiveIntensity = m.userData.zone === nextZone ? 0.12 : 0;
        }
        this.hovered = h;
        this.renderer.domElement.style.cursor = h ? 'pointer' : this.current ? 'grab' : 'default';
        this.cb.onHover(h, ev.clientX, ev.clientY);
    };

    private onDown = (ev: PointerEvent) => { this.down = { x: ev.clientX, y: ev.clientY, t: performance.now() }; };

    private onUp = (ev: PointerEvent) => {
        if (this.flight || Math.hypot(ev.clientX - this.down.x, ev.clientY - this.down.y) > 6) return;
        const h = this.pick(ev);
        if (!h) { if (this.current) this.select(null); return; }
        if (h.kind === 'pc') {
            const zone = this.layout.computers[this.pcIndex.get(h.number)!].zone;
            if (this.current && zone === this.current) this.select(h.number);
            else if (zone) { this.flyToZone(zone); this.select(h.number); }
        } else if (h.id !== this.current) this.flyToZone(h.id);
    };

    private onWheel = (ev: WheelEvent) => {
        if (!this.current) return;
        ev.preventDefault();
        this.camera.fov = THREE.MathUtils.clamp(this.camera.fov + Math.sign(ev.deltaY) * 4, 28, 78);
        this.camera.updateProjectionMatrix();
    };

    private onKey = (ev: KeyboardEvent) => { if (ev.key === 'Escape' && this.current) this.flyToOverview(); };

    private bindEvents() {
        const el = this.renderer.domElement;
        el.addEventListener('pointermove', this.onMove);
        el.addEventListener('pointerdown', this.onDown);
        el.addEventListener('pointerup', this.onUp);
        el.addEventListener('pointerleave', () => this.cb.onHover(null, 0, 0));
        el.addEventListener('wheel', this.onWheel, { passive: false });
        window.addEventListener('keydown', this.onKey);
    }

    /** Narrow screens need the camera further back to keep the whole floor in frame. */
    private fitOverview(aspect: number) {
        const span = Math.max(this.layout.size[0], this.layout.size[1]), k = Math.max(1, 1.0 / aspect);
        this.overview.pos.set(span * 0.12 * k, span * 0.86 * k, span * 0.68 * k).add(this.overview.target);
    }

    private onResize() {
        const w = this.host.clientWidth, h = this.host.clientHeight;
        if (!w || !h) return;
        this.fitOverview(w / h);
        this.camera.aspect = w / h; this.camera.updateProjectionMatrix();
        this.renderer.setSize(w, h); this.labels.setSize(w, h); this.composer.setSize(w, h); this.bloom.setSize(w, h);
    }

    private loop = () => {
        if (this.disposed) return;
        this.raf = requestAnimationFrame(this.loop);
        if (document.hidden) return;
        if (this.flight) {
            const f = this.flight, t = Math.min(1, (performance.now() - f.start) / f.ms), k = ease(t);
            this.camera.position.lerpVectors(f.from, f.to, k);
            // arc the path upwards so the camera swoops over walls instead of through them
            this.camera.position.y += Math.sin(Math.PI * k) * Math.min(6, f.from.distanceTo(f.to) * 0.18);
            this.controls.target.lerpVectors(f.fromT, f.toT, k);
            this.camera.lookAt(this.controls.target);
            if (t >= 1) { this.flight = null; this.controls.enabled = true; f.done(); }
        } else {
            this.controls.update();
        }
        this.composer.render();
        this.labels.render(this.scene, this.camera);
    };

    dispose() {
        this.disposed = true;
        cancelAnimationFrame(this.raf);
        this.resize.disconnect();
        window.removeEventListener('keydown', this.onKey);
        this.controls.dispose();
        this.scene.traverse(o => {
            const m = o as THREE.Mesh;
            m.geometry?.dispose();
            const mats = Array.isArray(m.material) ? m.material : m.material ? [m.material] : [];
            mats.forEach(x => { (x as THREE.MeshBasicMaterial).map?.dispose(); x.dispose(); });
        });
        this.composer.dispose();
        this.renderer.dispose();
        this.renderer.domElement.remove();
        this.labels.domElement.remove();
    }
}
