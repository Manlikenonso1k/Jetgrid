import { useFrame } from '@react-three/fiber';
import { useEffect, useMemo, useRef } from 'react';
import * as THREE from 'three';
import { plotPosition } from './Houses';

/**
 * The rooftop strobes.
 *
 * One instanced mesh for all of them, with per-instance colour recomputed each
 * frame: brightness IS the blink, so a strobe costs a colour write rather than
 * a material. Blink rate comes from the server (BeaconColor::blinkHz) so the
 * "faster = more urgent" rule is defined in one place, in PHP, and the scene
 * just obeys it.
 *
 * The duty cycle is deliberately short — a real aircraft wingtip strobe is a
 * brief flash against a dark body, not a square wave.
 */
export function Beacons({ houses, reducedMotion }) {
    const ref = useRef();
    const dummy = useMemo(() => new THREE.Object3D(), []);
    const color = useMemo(() => new THREE.Color(), []);
    const base = useMemo(() => new THREE.Color(), []);

    useEffect(() => {
        const mesh = ref.current;
        if (!mesh) return;

        houses.forEach((house, i) => {
            const [x, , z] = plotPosition(house.x, house.z);
            const height = 0.9 * house.scale + 0.56 * house.scale + 0.18;

            // Offset to a roof CORNER, like a wingtip light rather than a spire.
            dummy.position.set(x + 0.42 * house.scale, height, z + 0.42 * house.scale);
            dummy.scale.setScalar(0.13);
            dummy.updateMatrix();
            mesh.setMatrixAt(i, dummy.matrix);
        });

        mesh.count = houses.length;
        mesh.instanceMatrix.needsUpdate = true;
    }, [houses, dummy]);

    useFrame(({ clock }) => {
        const mesh = ref.current;
        if (!mesh || houses.length === 0) return;

        const t = clock.getElapsedTime();

        houses.forEach((house, i) => {
            base.set(house.beaconHex);

            let intensity;

            if (reducedMotion) {
                // No strobe at all: colour still carries the whole message.
                intensity = 1;
            } else {
                const phase = (t * house.blinkHz) % 1;
                // ~18% duty cycle, with a short falloff so it reads as a flash.
                intensity = phase < 0.18 ? 1 : Math.max(0.12, 0.5 - phase);
            }

            color.copy(base).multiplyScalar(intensity);
            mesh.setColorAt(i, color);
        });

        if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true;
    });

    if (houses.length === 0) return null;

    return (
        <instancedMesh ref={ref} args={[undefined, undefined, Math.max(houses.length, 64)]}>
            <sphereGeometry args={[1, 10, 10]} />
            {/* Basic, not standard: these are light sources, not lit surfaces. */}
            <meshBasicMaterial toneMapped={false} />
        </instancedMesh>
    );
}
