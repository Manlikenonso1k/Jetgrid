import { Grid, OrbitControls } from '@react-three/drei';
import { Canvas } from '@react-three/fiber';
import { Suspense } from 'react';
import { AirTraffic } from './AirTraffic';
import { Beacons } from './Beacons';
import { EmptyPlots, Houses } from './Houses';

const PLOT = 2.6;

function Ground() {
    return (
        <>
            <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -0.02, 0]} receiveShadow>
                <planeGeometry args={[300, 300]} />
                <meshStandardMaterial color="#080c0e" roughness={1} />
            </mesh>

            {/*
              Neon grid lines. This is the one place the true #39FF14 belongs: on
              the dark canvas, where it reads as neon instead of noise.

              drei's Grid rather than three's gridHelper: the helper draws 1px
              GL_LINES that all but vanish at this camera distance, while this is
              a shader on a plane, so the lines keep their weight and fade out
              with distance instead of ending in a hard square edge — which is
              what a view from a jet should look like.

              cellSize is exactly one plot, so the grid reads as the plots the
              houses actually sit on rather than as decorative graph paper.
            */}
            <Grid
                position={[0, 0.01, 0]}
                infiniteGrid
                cellSize={PLOT}
                cellThickness={0.6}
                cellColor="#1d5f2a"
                sectionSize={PLOT * 4}
                sectionThickness={1}
                // Not full #39FF14: at section spacing the true neon reads as
                // laser beams cutting across the scene rather than as ground.
                sectionColor="#2bb332"
                fadeDistance={38}
                fadeStrength={1.8}
                followCamera={false}
            />
        </>
    );
}

/**
 * Top-down / isometric view: you are in a jet looking down at the settlement.
 *
 * Camera drifts slowly by default (autoRotate), drag to pan, scroll to zoom.
 * The drift stops entirely under prefers-reduced-motion.
 */
export function Scene({ houses, emptyPlots, selectedId, onSelect, reducedMotion }) {
    return (
        <Canvas
            shadows
            dpr={[1, 1.75]}
            // Close enough that a three-house settlement fills the frame; the
            // camera pulls back on its own as the grid grows.
            camera={{ position: [9, 10, 9], fov: 40 }}
            gl={{ antialias: true, powerPreference: 'high-performance' }}
            onPointerMissed={() => onSelect(null)}
        >
            <color attach="background" args={['#070a0c']} />
            <fog attach="fog" args={['#070a0c', 34, 96]} />

            <ambientLight intensity={0.6} />
            <directionalLight
                position={[12, 20, 9]}
                intensity={1.5}
                castShadow
                shadow-mapSize={[1024, 1024]}
            />
            {/* A cold rim light so the grey adopted houses stay distinguishable. */}
            <directionalLight position={[-11, 9, -10]} intensity={0.6} color="#8fc0ff" />
            {/* A faint neon bounce off the grid, so houses sit in the scene
                rather than floating on top of it. */}
            <hemisphereLight args={['#2a4d33', '#05070a', 0.55]} />

            <Suspense fallback={null}>
                <Ground />
                <EmptyPlots plots={emptyPlots} />
                <Houses houses={houses} selectedId={selectedId} onSelect={onSelect} />
                <Beacons houses={houses} reducedMotion={reducedMotion} />
                <AirTraffic houses={houses} reducedMotion={reducedMotion} />
            </Suspense>

            <OrbitControls
                makeDefault
                enablePan
                enableZoom
                autoRotate={!reducedMotion}
                autoRotateSpeed={0.28}
                minDistance={5}
                maxDistance={70}
                // Stay above the horizon: this is a view from a jet, not from
                // inside the ground.
                maxPolarAngle={Math.PI / 2.25}
                target={[0, 0, 0]}
            />
        </Canvas>
    );
}
