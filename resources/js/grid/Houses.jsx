import { useEffect, useMemo, useRef } from 'react';
import * as THREE from 'three';
import { RoundedBoxGeometry } from 'three/examples/jsm/geometries/RoundedBoxGeometry.js';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

const PLOT = 2.6; // world units between plot centres

export function plotPosition(x, z) {
    return [x * PLOT, 0, z * PLOT];
}

/*
 * Proportions live here rather than inline, because the beacon in Beacons.jsx is
 * positioned against the EAVE and has to move with this. Changing a number here
 * without reading that file will float the strobes off the rooftops.
 */
const BODY_W = 1.15; // × house.scale
const BODY_H = 0.9;
const ROOF_OVERHANG = 1.08; // eaves project past the walls
const ROOF_PITCH = 0.55;

/**
 * A gable roof: two slanted faces meeting at a ridge, with triangular gable
 * ends. Extruding a triangular cross-section along Z produces exactly that in
 * one geometry — the two slopes are the extruded sides and the gable ends are
 * the extrusion caps.
 *
 * Spans y 0→1 with its base at the origin, so an instance placed at the body's
 * top lands its eaves exactly on the walls with no magic offset.
 */
function buildRoofGeometry() {
    const shape = new THREE.Shape();
    shape.moveTo(-0.5, 0);
    shape.lineTo(0.5, 0);
    shape.lineTo(0, 1);
    shape.closePath();

    const gable = new THREE.ExtrudeGeometry(shape, {
        depth: 1,
        bevelEnabled: false,
        curveSegments: 1,
    });
    gable.translate(0, 0, -0.5);

    const chimney = new THREE.BoxGeometry(0.12, 0.55, 0.12);
    chimney.translate(0.22, 0.52, 0.2);

    // toNonIndexed() on both: mergeGeometries refuses a mix of indexed and
    // non-indexed inputs, and ExtrudeGeometry is already non-indexed.
    const merged = mergeGeometries([gable.toNonIndexed(), chimney.toNonIndexed()], false);

    gable.dispose();
    chimney.dispose();

    // Deliberately no computeVertexNormals(): both inputs already carry flat
    // normals, and recomputing would smooth across the ridge and turn a crisp
    // gable into a soft lump.
    return merged;
}

/** A soft contact blob. Shadow maps at this range are too coarse to darken
 *  where a wall actually meets the ground, which is what sells the contact. */
function buildGroundTexture() {
    const size = 128;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
    gradient.addColorStop(0, 'rgba(0,0,0,0.55)');
    gradient.addColorStop(0.5, 'rgba(0,0,0,0.28)');
    gradient.addColorStop(1, 'rgba(0,0,0,0)');

    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, size, size);

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;

    return texture;
}

/**
 * Deterministic 0..1 from an integer site id.
 *
 * Seeded rather than Math.random() so a house keeps its tint across re-renders
 * and polls — a settlement that reshuffles its colours every fifteen seconds
 * looks broken, not varied.
 */
function hashUnit(id, salt = 0) {
    let h = Math.imul((id | 0) ^ (salt * 0x9e3779b9), 0x85ebca6b);
    h = Math.imul(h ^ (h >>> 13), 0xc2b2ae35);

    return ((h ^ (h >>> 16)) >>> 0) / 4294967296;
}

/**
 * Every house body and every roof is drawn from ONE instanced mesh each, so the
 * whole settlement is three draw calls regardless of how many sites there are —
 * bodies, roofs, and the ground blobs. That is what keeps this at 60fps on a mid
 * laptop with a hundred houses.
 */
export function Houses({ houses, selectedId, onSelect }) {
    const bodyRef = useRef();
    const roofRef = useRef();
    const groundRef = useRef();
    const dummy = useMemo(() => new THREE.Object3D(), []);
    const color = useMemo(() => new THREE.Color(), []);

    const bodyGeometry = useMemo(() => new RoundedBoxGeometry(1, 1, 1, 2, 0.045), []);
    const roofGeometry = useMemo(() => buildRoofGeometry(), []);
    const groundTexture = useMemo(() => buildGroundTexture(), []);

    // Built imperatively, so nothing else will free them.
    useEffect(
        () => () => {
            bodyGeometry.dispose();
            roofGeometry.dispose();
            groundTexture.dispose();
        },
        [bodyGeometry, roofGeometry, groundTexture],
    );

    useEffect(() => {
        const body = bodyRef.current;
        const roof = roofRef.current;
        const ground = groundRef.current;
        if (!body || !roof || !ground) return;

        houses.forEach((house, i) => {
            const [x, , z] = plotPosition(house.x, house.z);
            const height = BODY_H * house.scale;
            const width = BODY_W * house.scale;

            dummy.position.set(x, height / 2, z);
            dummy.scale.set(width, height, width);
            dummy.rotation.set(0, 0, 0);
            dummy.updateMatrix();
            body.setMatrixAt(i, dummy.matrix);

            // Base sits exactly on the wall top; the overhang puts the eaves
            // just past the walls, which is what stops it reading as a lid.
            const roofWidth = width * ROOF_OVERHANG;
            dummy.position.set(x, height, z);
            dummy.scale.set(roofWidth, ROOF_PITCH * house.scale, roofWidth);
            dummy.rotation.set(0, 0, 0);
            dummy.updateMatrix();
            roof.setMatrixAt(i, dummy.matrix);

            // Wider than the house and squashed to the floor.
            dummy.position.set(x, 0.015, z);
            dummy.scale.setScalar(width * 2.1);
            dummy.rotation.set(-Math.PI / 2, 0, 0);
            dummy.updateMatrix();
            ground.setMatrixAt(i, dummy.matrix);

            /*
             * An adopted site is rendered in cold grey and never gets the green
             * trim — you can tell at a glance which houses JetGrid manages.
             * These are lit surfaces, so they need real value, not near-black.
             */
            const isSelected = house.id === selectedId;

            // Seeded jitter so the settlement does not look copy-pasted. Kept
            // small: this is variation, not a second colour channel, and it must
            // never blur the adopted/managed distinction the greys carry.
            const hue = (hashUnit(house.id, 1) - 0.5) * 0.04;
            const sat = (hashUnit(house.id, 2) - 0.5) * 0.08;
            const light = (hashUnit(house.id, 3) - 0.5) * 0.1;

            color.set(isSelected ? '#7bd68d' : house.protected ? '#767f8a' : '#3f7d55');
            if (!isSelected) color.offsetHSL(hue, sat, light);
            body.setColorAt(i, color);

            color.set(isSelected ? '#9ae5aa' : house.protected ? '#8e97a2' : '#54a06d');
            if (!isSelected) color.offsetHSL(hue, sat, light * 0.6);
            roof.setColorAt(i, color);
        });

        body.count = houses.length;
        roof.count = houses.length;
        ground.count = houses.length;
        body.instanceMatrix.needsUpdate = true;
        roof.instanceMatrix.needsUpdate = true;
        ground.instanceMatrix.needsUpdate = true;
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
            {/*
              Drawn before the houses with depthWrite off so the blobs never
              occlude the walls standing on them.
            */}
            <instancedMesh
                ref={groundRef}
                args={[undefined, undefined, capacity]}
                renderOrder={-1}
                raycast={() => null}
            >
                <planeGeometry args={[1, 1]} />
                <meshBasicMaterial
                    map={groundTexture}
                    transparent
                    opacity={0.75}
                    depthWrite={false}
                    color="#000000"
                />
            </instancedMesh>

            <instancedMesh
                ref={bodyRef}
                args={[bodyGeometry, undefined, capacity]}
                castShadow
                receiveShadow
                onClick={handleClick}
                onPointerOver={() => (document.body.style.cursor = 'pointer')}
                onPointerOut={() => (document.body.style.cursor = 'auto')}
            >
                {/* Matte: metalness above zero on a painted wall reads as plastic. */}
                <meshStandardMaterial roughness={0.85} metalness={0} />
            </instancedMesh>

            <instancedMesh
                ref={roofRef}
                args={[roofGeometry, undefined, capacity]}
                castShadow
                receiveShadow
            >
                <meshStandardMaterial roughness={0.85} metalness={0} />
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
