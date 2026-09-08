import { useEffect, useMemo, useRef } from 'react';
import * as THREE from 'three';

const PLOT = 2.6; // world units between plot centres

export function plotPosition(x, z) {
    return [x * PLOT, 0, z * PLOT];
}

/**
 * Every house body and every roof is drawn from ONE instanced mesh each, so the
 * whole settlement is two draw calls regardless of how many sites there are.
 * That is what keeps this at 60fps on a mid laptop with a hundred houses.
 */
export function Houses({ houses, selectedId, onSelect }) {
    const bodyRef = useRef();
    const roofRef = useRef();
    const dummy = useMemo(() => new THREE.Object3D(), []);
    const color = useMemo(() => new THREE.Color(), []);

    useEffect(() => {
        const body = bodyRef.current;
        const roof = roofRef.current;
        if (!body || !roof) return;

        houses.forEach((house, i) => {
            const [x, , z] = plotPosition(house.x, house.z);
            const height = 0.9 * house.scale;
            const width = 1.15 * house.scale;

            dummy.position.set(x, height / 2, z);
            dummy.scale.set(width, height, width);
            dummy.rotation.set(0, 0, 0);
            dummy.updateMatrix();
            body.setMatrixAt(i, dummy.matrix);

            // A taller pitch: at a shallow one the roof reads as a flat cap from
            // this camera angle and every house looks like a plain box.
            dummy.position.set(x, height + 0.4 * house.scale, z);
            dummy.scale.set(width * 0.9, 0.8 * house.scale, width * 0.9);
            dummy.rotation.set(0, Math.PI / 4, 0);
            dummy.updateMatrix();
            roof.setMatrixAt(i, dummy.matrix);

            // An adopted site is rendered in cold grey and never gets the green
            // trim — you can tell at a glance which houses JetGrid manages.
            // These are lit surfaces, so they need real value, not near-black.
            const isSelected = house.id === selectedId;
            color.set(isSelected ? '#7bd68d' : house.protected ? '#767f8a' : '#3f7d55');
            body.setColorAt(i, color);

            color.set(isSelected ? '#9ae5aa' : house.protected ? '#8e97a2' : '#54a06d');
            roof.setColorAt(i, color);
        });

        body.count = houses.length;
        roof.count = houses.length;
        body.instanceMatrix.needsUpdate = true;
        roof.instanceMatrix.needsUpdate = true;
        if (body.instanceColor) body.instanceColor.needsUpdate = true;
        if (roof.instanceColor) roof.instanceColor.needsUpdate = true;
    }, [houses, selectedId, dummy, color]);

    // Instanced meshes cannot grow, so allocate headroom and drive `count`.
    const capacity = Math.max(houses.length, 64);

    const handleClick = (event) => {
        event.stopPropagation();
        const house = houses[event.instanceId];
        if (house) onSelect(house);
    };

    return (
        <group>
            <instancedMesh
                ref={bodyRef}
                args={[undefined, undefined, capacity]}
                castShadow
                onClick={handleClick}
                onPointerOver={() => (document.body.style.cursor = 'pointer')}
                onPointerOut={() => (document.body.style.cursor = 'auto')}
            >
                <boxGeometry args={[1, 1, 1]} />
                <meshStandardMaterial roughness={0.6} metalness={0.12} />
            </instancedMesh>

            <instancedMesh ref={roofRef} args={[undefined, undefined, capacity]} castShadow>
                <coneGeometry args={[0.78, 1, 4]} />
                <meshStandardMaterial roughness={0.7} metalness={0.05} />
            </instancedMesh>
        </group>
    );
}

/** Empty plots = remaining capacity (Feature 2). */
export function EmptyPlots({ plots }) {
    const ref = useRef();
    const dummy = useMemo(() => new THREE.Object3D(), []);

    useEffect(() => {
        const mesh = ref.current;
        if (!mesh) return;

        plots.forEach((plot, i) => {
            const [x, , z] = plotPosition(plot.x, plot.z);
            dummy.position.set(x, 0.02, z);
            dummy.rotation.set(-Math.PI / 2, 0, 0);
            dummy.scale.set(1, 1, 1);
            dummy.updateMatrix();
            mesh.setMatrixAt(i, dummy.matrix);
        });

        mesh.count = plots.length;
        mesh.instanceMatrix.needsUpdate = true;
    }, [plots, dummy]);

    if (plots.length === 0) return null;

    return (
        <instancedMesh ref={ref} args={[undefined, undefined, Math.max(plots.length, 48)]}>
            <planeGeometry args={[1.3, 1.3]} />
            <meshBasicMaterial color="#2f7a41" transparent opacity={0.5} side={THREE.DoubleSide} />
        </instancedMesh>
    );
}
