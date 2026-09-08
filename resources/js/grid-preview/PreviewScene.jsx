import {
    ContactShadows,
    Grid,
    Html,
    Instance,
    Instances,
    OrbitControls,
    PerspectiveCamera,
} from '@react-three/drei';
import { Canvas, useFrame } from '@react-three/fiber';
import { Bloom, EffectComposer } from '@react-three/postprocessing';
import { useRef, useState } from 'react';
import * as THREE from 'three';
import { HouseLabels } from '../grid/HouseLabels';
import { BEACON, mockHouses, TYPE_BADGE } from './mockHouses';

const BODY_LIT = '#252c2e';
const BODY_DARK = '#14181a';
const ROOF_DARK = '#1c2022';

const NEAR_DISTANCE = 30;
const FAR_DISTANCE = 52;

/**
 * Aircraft anticollision profile over a 0..1 phase: sharp rise, short hold,
 * quick decay, then dark for most of the period. A sine fade reads as breathing;
 * this reads as a warning light, which is the whole point of the metaphor.
 */
function strobe(phase) {
    if (phase < 0.05) return phase / 0.05;
    if (phase < 0.14) return 1;
    if (phase < 0.34) return 1 - (phase - 0.14) / 0.2;
    return 0;
}

function bodyHeight(scale) {
    return 1.0 * scale;
}

function roofApex(scale) {
    return bodyHeight(scale) + 0.85 * scale;
}

function labelHeight(scale) {
    return roofApex(scale) + 0.75;
}

/**
 * One mesh and one light per beacon rather than an instanced batch: emissive
 * intensity cannot vary per instance, and the light spilling onto the roof below
 * is most of what sells the neon. Costly per house, so the real dashboard will
 * need a cap on how many get a live light — the preview only ever has twelve.
 */
function Beacon({ house }) {
    const spec = BEACON[house.beacon];
    const material = useRef();
    const light = useRef();

    const y = roofApex(house.scale) + 0.14;

    useFrame(({ clock }) => {
        if (!material.current) return;

        const phase = (clock.getElapsedTime() / spec.period + house.phase) % 1;
        const s = strobe(phase);

        material.current.emissiveIntensity = 0.4 + s * 11;
        if (light.current) light.current.intensity = s * 3.4;
    });

    return (
        <group position={[house.position[0], y, house.position[2]]}>
            <mesh>
                <sphereGeometry args={[0.11, 12, 12]} />
                <meshStandardMaterial
                    ref={material}
                    color={spec.hex}
                    emissive={spec.hex}
                    emissiveIntensity={1}
                    toneMapped={false}
                />
            </mesh>
            <pointLight ref={light} color={spec.hex} distance={2.6} decay={2} intensity={1} />
        </group>
    );
}

function Houses({ houses, onHover }) {
    return (
        <>
            <Instances limit={200} range={houses.length}>
                <boxGeometry args={[1, 1, 1]} />
                <meshStandardMaterial roughness={0.75} metalness={0.1} />
                {houses.map((house) => {
                    const h = bodyHeight(house.scale);
                    const w = 1.3 * house.scale;

                    return (
                        <Instance
                            key={house.id}
                            position={[house.position[0], h / 2, house.position[2]]}
                            scale={[w, h, w]}
                            color={house.beacon === 'grey' ? BODY_DARK : BODY_LIT}
                            onPointerOver={(event) => {
                                event.stopPropagation();
                                onHover(house);
                            }}
                            onPointerOut={() => onHover(null)}
                        />
                    );
                })}
            </Instances>

            <Instances limit={200} range={houses.length}>
                <coneGeometry args={[1, 1, 4]} />
                <meshStandardMaterial roughness={0.8} metalness={0.05} />
                {houses.map((house) => {
                    const h = bodyHeight(house.scale);
                    const r = 0.95 * house.scale;

                    return (
                        <Instance
                            key={house.id}
                            position={[house.position[0], h + 0.425 * house.scale, house.position[2]]}
                            scale={[r, 0.85 * house.scale, r]}
                            rotation={[0, Math.PI / 4, 0]}
                            color={house.beacon === 'grey' ? ROOF_DARK : house.roofColor}
                        />
                    );
                })}
            </Instances>
        </>
    );
}

/**
 * Flips a single boolean when the camera crosses the label-fade threshold, so
 * the hover tooltip can take over exactly when the 3D labels give up. State
 * changes on the crossing only — not per frame.
 */
function LabelRangeWatch({ threshold, onChange }) {
    const wasFar = useRef(null);

    useFrame(({ camera }) => {
        const far = camera.position.length() > threshold;
        if (far !== wasFar.current) {
            wasFar.current = far;
            onChange(far);
        }
    });

    return null;
}

function Ground() {
    return (
        <>
            <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -0.01, 0]}>
                <planeGeometry args={[400, 400]} />
                <meshStandardMaterial color="#080b09" roughness={1} metalness={0} />
            </mesh>

            <Grid
                position={[0, 0, 0]}
                infiniteGrid
                cellSize={3.2}
                cellThickness={0.55}
                cellColor="#16401d"
                sectionSize={12.8}
                sectionThickness={1.1}
                sectionColor="#27912f"
                fadeDistance={70}
                fadeStrength={1.5}
                followCamera={false}
            />
        </>
    );
}

export function GridPreview() {
    const [labelMode, setLabelMode] = useState('hover');
    const [hovered, setHovered] = useState(null);
    const [outOfLabelRange, setOutOfLabelRange] = useState(false);

    const labels = mockHouses.map((house) => ({
        id: house.id,
        name: house.name,
        port: house.port,
        type: house.type,
        badge: TYPE_BADGE[house.type] ?? TYPE_BADGE.static,
        position: [house.position[0], labelHeight(house.scale), house.position[2]],
    }));

    // Only stand in for the 3D labels when they have actually faded out.
    const showTooltip = labelMode === 'hover' && outOfLabelRange && hovered;

    return (
        <div className="jg-preview">
            <Canvas
                dpr={[1, 1.75]}
                gl={{
                    antialias: true,
                    powerPreference: 'high-performance',
                    toneMapping: THREE.ACESFilmicToneMapping,
                }}
                onPointerMissed={() => setHovered(null)}
            >
                <color attach="background" args={['#0a0e0a']} />
                <fogExp2 attach="fog" args={['#0a0e0a', 0.026]} />

                <PerspectiveCamera makeDefault fov={30} position={[11.5, 25, 11.5]} />

                {/* Deliberately underlit: the emissive beacons are the light source
                    that matters, and flat fill is what made this look generic. */}
                <ambientLight intensity={0.15} />
                <directionalLight position={[14, 22, 8]} intensity={0.85} color="#cfe6ff" />

                <Ground />
                <Houses houses={mockHouses} onHover={setHovered} />
                {mockHouses
                    .filter((house) => BEACON[house.beacon])
                    .map((house) => (
                        <Beacon key={house.id} house={house} />
                    ))}

                <HouseLabels
                    labels={labels}
                    mode={labelMode}
                    nearDistance={NEAR_DISTANCE}
                    farDistance={FAR_DISTANCE}
                />

                <LabelRangeWatch threshold={NEAR_DISTANCE} onChange={setOutOfLabelRange} />

                {showTooltip && (
                    <Html
                        position={[
                            hovered.position[0],
                            labelHeight(hovered.scale),
                            hovered.position[2],
                        ]}
                        center
                        distanceFactor={22}
                        zIndexRange={[20, 0]}
                    >
                        <div className="jg-tip">
                            <span
                                className="jg-tip__dot"
                                style={{ background: TYPE_BADGE[hovered.type] ?? '#9aa4ad' }}
                            />
                            {hovered.name}
                            {hovered.port && <span className="jg-tip__port">:{hovered.port}</span>}
                        </div>
                    </Html>
                )}

                <ContactShadows
                    position={[0, 0.005, 0]}
                    scale={44}
                    resolution={1024}
                    blur={2.4}
                    opacity={0.65}
                    far={6}
                    color="#000000"
                />

                <EffectComposer>
                    <Bloom intensity={1.2} luminanceThreshold={0.2} mipmapBlur />
                </EffectComposer>

                <OrbitControls
                    makeDefault
                    enablePan
                    enableZoom
                    minDistance={8}
                    maxDistance={90}
                    maxPolarAngle={Math.PI / 2.15}
                    target={[0, 0, 0]}
                />
            </Canvas>

            <Legend labelMode={labelMode} onLabelMode={setLabelMode} />
        </div>
    );
}

const MODES = [
    ['always', 'Always show names'],
    ['hover', 'On hover + nearby'],
    ['off', 'Off'],
];

function Legend({ labelMode, onLabelMode }) {
    const rows = [
        ['green', 'running, healthy', '3.0s'],
        ['blue', 'starting / deploying', '1.0s'],
        ['yellow', 'deps missing, cert expiring', '1.4s'],
        ['red', 'crashed, port held, erroring', '0.7s'],
        ['purple', 'running under Docker', '2.0s'],
        ['grey', 'detected, not running', 'no beacon'],
    ];

    return (
        <div className="jg-legend">
            <h1>JetGrid — scene preview</h1>
            <p>12 mock houses, every beacon state. No data layer.</p>
            <ul>
                {rows.map(([key, label, period]) => (
                    <li key={key}>
                        <span
                            className="jg-swatch"
                            style={{
                                background: BEACON[key] ? BEACON[key].hex : 'transparent',
                                borderColor: BEACON[key] ? BEACON[key].hex : '#4a5057',
                                boxShadow: BEACON[key] ? `0 0 8px ${BEACON[key].hex}` : 'none',
                            }}
                        />
                        <span className="jg-label">{label}</span>
                        <span className="jg-period">{period}</span>
                    </li>
                ))}
            </ul>

            <div className="jg-modes">
                <span className="jg-modes__title">Labels</span>
                {MODES.map(([value, label]) => (
                    <button
                        key={value}
                        type="button"
                        className={value === labelMode ? 'jg-mode jg-mode--on' : 'jg-mode'}
                        onClick={() => onLabelMode(value)}
                    >
                        {label}
                    </button>
                ))}
            </div>
        </div>
    );
}
