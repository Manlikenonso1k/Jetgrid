/**
 * Hardcoded stand-ins for the art-direction sandbox. No data layer, no polling —
 * the point is to iterate on the look with every beacon state on screen at once.
 *
 * Hexes and the urgency ordering mirror App\Enums\BeaconColor. Blink PERIOD is
 * in seconds here rather than the enum's Hz because the strobe profile is
 * written against a 0..1 phase; period is what reads naturally at that scale.
 */

export const BEACON = {
    red: { hex: '#FF3B30', period: 0.7 },
    blue: { hex: '#3B9DFF', period: 1.0 },
    yellow: { hex: '#FFD60A', period: 1.4 },
    purple: { hex: '#B15BFF', period: 2.0 },
    green: { hex: '#39FF14', period: 3.0 },
    // Detected but not running: a dark silhouette, deliberately no beacon.
    grey: null,
};

/**
 * Roof tints stay close to the body colour on purpose. At this camera distance a
 * saturated roof competes with the beacons, which are the only thing that should
 * carry colour.
 */
const ROOF_BY_TYPE = {
    laravel: '#4a3a3f',
    node: '#3d4a37',
    python: '#36414d',
    go: '#36494b',
    rust: '#4b4036',
    docker: '#374250',
    static: '#41414a',
    php: '#453a4a',
};

const PLOT = 3.2;

/** Dot beside the name on the label. Distinct hues, kept off the beacon palette. */
export const TYPE_BADGE = {
    laravel: '#ff5b47',
    node: '#7fd858',
    python: '#4b8bbe',
    go: '#43c3d4',
    rust: '#d98b4a',
    docker: '#4a90e2',
    static: '#9aa4ad',
    php: '#8892bf',
};

const LAYOUT = [
    { name: 'shopfront', type: 'laravel', beacon: 'green', scale: 1.15, port: 8000 },
    { name: 'api-gateway', type: 'node', beacon: 'green', scale: 0.95, port: 3000 },
    { name: 'billing', type: 'laravel', beacon: 'red', scale: 1.05, port: 8001 },
    { name: 'ingest-worker', type: 'python', beacon: 'red', scale: 0.9, port: 8002 },

    { name: 'admin-ui', type: 'node', beacon: 'yellow', scale: 1.0, port: 5173 },
    { name: 'docs-site', type: 'static', beacon: 'yellow', scale: 0.85, port: null },
    { name: 'checkout', type: 'laravel', beacon: 'blue', scale: 1.2, port: 8003 },
    { name: 'edge-proxy', type: 'go', beacon: 'blue', scale: 0.95, port: 8080 },

    { name: 'analytics', type: 'docker', beacon: 'purple', scale: 1.1, port: 9000 },
    { name: 'queue-runner', type: 'docker', beacon: 'purple', scale: 0.9, port: 9001 },
    { name: 'legacy-blog', type: 'php', beacon: 'grey', scale: 1.0, port: null },
    { name: 'archive', type: 'static', beacon: 'grey', scale: 0.8, port: null },
];

export const mockHouses = LAYOUT.map((entry, i) => {
    const col = i % 4;
    const row = Math.floor(i / 4);

    return {
        id: i + 1,
        ...entry,
        roofColor: ROOF_BY_TYPE[entry.type] ?? ROOF_BY_TYPE.static,
        position: [(col - 1.5) * PLOT, 0, (row - 1) * PLOT],
        // Keeps strobes from firing in unison, which is what makes a field of
        // blinking lights read as machinery rather than a screensaver.
        phase: (i * 0.37) % 1,
    };
});
