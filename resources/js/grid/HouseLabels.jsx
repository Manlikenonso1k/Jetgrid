import { Billboard, Text } from '@react-three/drei';
import { useFrame } from '@react-three/fiber';
import { useRef } from 'react';
import * as THREE from 'three';

const NAME_SIZE = 0.42;
const PORT_SIZE = 0.28;
const NAME_COLOR = '#e8f5e8';
const PORT_COLOR = '#7d9384';

/*
 * Deliberately NOT emissive and NOT toneMapped={false}. Text pushed above the
 * bloom threshold smears into an unreadable blob at any real label density —
 * the beacons are the only thing in this scene allowed to bloom.
 */
const textCommon = {
    depthWrite: false,
    anchorX: 'center',
    anchorY: 'middle',
    font: undefined,
};

/**
 * Names above the roofs.
 *
 * Fade and scale are driven by mutating material opacity and group scale inside
 * useFrame rather than by React state: this runs every frame for every house, and
 * a setState per frame would re-render the whole tree sixty times a second.
 *
 * `labels` entries: { id, name, port, type, badge, position: [x, y, z] }
 */
export function HouseLabels({ labels, mode, nearDistance = 30, farDistance = 52 }) {
    const groups = useRef([]);
    const texts = useRef([]);
    const anchor = useRef(new THREE.Vector3());

    useFrame(({ camera }) => {
        if (mode === 'off') return;

        labels.forEach((label, i) => {
            const group = groups.current[i];
            if (!group) return;

            anchor.current.set(label.position[0], label.position[1], label.position[2]);
            const distance = camera.position.distanceTo(anchor.current);

            let opacity;

            if (mode === 'always') {
                opacity = 1;
            } else if (distance <= nearDistance) {
                opacity = 1;
            } else if (distance >= farDistance) {
                opacity = 0;
            } else {
                opacity = 1 - (distance - nearDistance) / (farDistance - nearDistance);
            }

            group.visible = opacity > 0.02;
            if (!group.visible) return;

            /*
             * Partial distance compensation. Fully constant screen size (scale
             * proportional to distance) makes a zoomed-out grid a wall of text;
             * none at all makes labels unreadable specks. The square root keeps
             * them legible at both ends.
             */
            const s = Math.min(2.2, Math.max(0.75, Math.sqrt(distance / 22)));
            group.scale.setScalar(s);

            (texts.current[i] || []).forEach((mesh) => {
                if (!mesh?.material) return;
                mesh.material.transparent = true;
                mesh.material.opacity = opacity;
                mesh.material.depthWrite = false;
            });
        });
    });

    if (mode === 'off') return null;

    return (
        <group>
            {labels.map((label, i) => {
                // Rough centring: troika reports real width only after an async
                // sync, and one frame of visibly off-centre text is worse than a
                // few percent of drift.
                const estimated = label.name.length * NAME_SIZE * 0.52;
                const dotX = -(estimated / 2) - 0.18;

                return (
                    <Billboard
                        key={label.id}
                        ref={(node) => (groups.current[i] = node)}
                        position={label.position}
                        renderOrder={10}
                    >
                        <mesh position={[dotX, 0, 0]} renderOrder={11}>
                            <circleGeometry args={[0.1, 16]} />
                            <meshBasicMaterial color={label.badge} depthWrite={false} transparent />
                        </mesh>

                        <Text
                            {...textCommon}
                            ref={(node) => {
                                texts.current[i] = texts.current[i] || [];
                                texts.current[i][0] = node;
                            }}
                            position={[0.06, 0, 0]}
                            fontSize={NAME_SIZE}
                            color={NAME_COLOR}
                            outlineWidth={0.02}
                            outlineColor="#04120a"
                            renderOrder={11}
                        >
                            {label.name}
                        </Text>

                        {label.port && (
                            <Text
                                {...textCommon}
                                ref={(node) => {
                                    texts.current[i] = texts.current[i] || [];
                                    texts.current[i][1] = node;
                                }}
                                position={[0.06, -0.36, 0]}
                                fontSize={PORT_SIZE}
                                color={PORT_COLOR}
                                outlineWidth={0.015}
                                outlineColor="#04120a"
                                renderOrder={11}
                            >
                                {`:${label.port}`}
                            </Text>
                        )}
                    </Billboard>
                );
            })}
        </group>
    );
}
