import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import * as THREE from 'three';
import { plotPosition } from './Houses';

const MAX_PARTICLES = 320;

/**
 * Feature 14 — the air-traffic layer.
 *
 * Particles fly in toward each house at a rate proportional to its request
 * volume, so "which project is actually getting traffic" is readable at a
 * glance without looking at a single number.
 *
 * One BufferGeometry, one draw call, positions updated in place. Nothing is
 * allocated per frame: the particle pool is fixed and shares are recomputed
 * only when the traffic figures change.
 */
export function AirTraffic({ houses, reducedMotion }) {
    const pointsRef = useRef();

    const positions = useMemo(() => new Float32Array(MAX_PARTICLES * 3), []);
    const colors = useMemo(() => new Float32Array(MAX_PARTICLES * 3), []);

    // Per-particle state, allocated once.
    const state = useMemo(
        () =>
            Array.from({ length: MAX_PARTICLES }, () => ({
                active: false,
                t: 0,
                speed: 0.2,
                from: new THREE.Vector3(),
                to: new THREE.Vector3(),
                arc: 3,
            })),
        [],
    );

    const trafficKey = houses.map((h) => `${h.id}:${h.traffic}`).join(',');

    useEffect(() => {
        const withTraffic = houses.filter((h) => h.traffic > 0);
        const total = withTraffic.reduce((sum, h) => sum + h.traffic, 0);

        if (total === 0 || withTraffic.length === 0) {
            state.forEach((p) => (p.active = false));
            return;
        }

        // Allocate the pool proportionally to each site's share of requests.
        let cursor = 0;
        const color = new THREE.Color();

        withTraffic.forEach((house) => {
            const share = Math.max(2, Math.round((house.traffic / total) * MAX_PARTICLES));
            const [hx, , hz] = plotPosition(house.x, house.z);
            color.set(house.protected ? '#aab6c2' : '#39ff14');

            for (let n = 0; n < share && cursor < MAX_PARTICLES; n++, cursor++) {
                const particle = state[cursor];
                const angle = Math.random() * Math.PI * 2;
                // Short approach lanes. Spawned far out, the particles spread
                // across the whole sky and stop reading as traffic arriving
                // somewhere — they just look like stars.
                const distance = 4 + Math.random() * 3.5;

                particle.active = true;
                particle.t = Math.random();
                particle.speed = 0.22 + Math.random() * 0.2;
                particle.arc = 1.1 + Math.random() * 1.2;
                particle.from.set(hx + Math.cos(angle) * distance, 0.35, hz + Math.sin(angle) * distance);
                particle.to.set(hx, 0.5, hz);

                colors[cursor * 3] = color.r;
                colors[cursor * 3 + 1] = color.g;
                colors[cursor * 3 + 2] = color.b;
            }
        });

        for (let i = cursor; i < MAX_PARTICLES; i++) {
            state[i].active = false;
        }

        if (pointsRef.current) {
            pointsRef.current.geometry.attributes.color.needsUpdate = true;
        }
        // trafficKey is the real dependency: rebuild only when volumes change.
    }, [trafficKey, houses, state, colors]);

    useFrame((_, delta) => {
        const points = pointsRef.current;
        if (!points) return;

        const step = reducedMotion ? 0 : Math.min(delta, 0.05);

        for (let i = 0; i < MAX_PARTICLES; i++) {
            const particle = state[i];

            if (!particle.active) {
                // Park inactive particles far below the camera frustum.
                positions[i * 3 + 1] = -999;
                continue;
            }

            particle.t += particle.speed * step;
            if (particle.t > 1) particle.t -= 1;

            const t = particle.t;
            positions[i * 3] = particle.from.x + (particle.to.x - particle.from.x) * t;
            positions[i * 3 + 2] = particle.from.z + (particle.to.z - particle.from.z) * t;
            // Parabolic arc: high on approach, landing on the rooftop.
            positions[i * 3 + 1] = particle.from.y + Math.sin(t * Math.PI) * particle.arc;
        }

        points.geometry.attributes.position.needsUpdate = true;
    });

    return (
        <points ref={pointsRef}>
            <bufferGeometry>
                <bufferAttribute attach="attributes-position" args={[positions, 3]} />
                <bufferAttribute attach="attributes-color" args={[colors, 3]} />
            </bufferGeometry>
            <pointsMaterial
                size={0.1}
                vertexColors
                transparent
                opacity={0.9}
                sizeAttenuation
                depthWrite={false}
                toneMapped={false}
            />
        </points>
    );
}
