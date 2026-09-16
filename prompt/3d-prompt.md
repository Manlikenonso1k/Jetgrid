The 3D houses in the JetGrid grid scene currently look too basic/toy-like — a plain BoxGeometry body with a 4-sided ConeGeometry pyramid roof, flat material, no ground contact, all identical. Find the component where the house geometry is built for the InstancedMesh (likely something like Grid.jsx, Houses.tsx, or wherever createHouseGeometry/InstancedMesh is defined) and improve it while preserving the existing instancing architecture (one shared geometry/material per layer — houses, roofs, beacons — do not switch to per-object materials or break the low-draw-call design described in the README).

Make these specific changes:

Replace the pyramid roof with a gable roof — two slanted rectangular faces meeting at a ridge line with triangular gable ends, built via an extruded THREE.Shape cross-section, merged into the same base geometry as the body using BufferGeometryUtils.mergeGeometries. Add a small chimney box as well.
Bevel the body's edges — swap BoxGeometry for RoundedBoxGeometry (from three/addons/geometries/RoundedBoxGeometry.js) with a small radius (~0.02–0.04 relative to house scale).
Fix the material — use MeshStandardMaterial with roughness: 0.85, metalness: 0 for a matte, non-plastic look. Roof material should be a separate mesh/material (as it already is per the README) but same standard-material treatment.
Add a grounding effect — a soft radial-gradient AO blob (transparent plane, low opacity) instanced beneath each house so they don't look like they're floating. If real shadow maps are cheap enough given current instance counts, prefer those; otherwise fake AO plane.
Add per-instance variation — use instanceColor on the InstancedMesh to give each house a subtle random hue/lightness jitter (seeded from the site id, so it's deterministic) so the settlement doesn't look copy-pasted.
Do not add per-instance windows/doors as geometry — that would balloon triangle count. If you want facade detail, fake it with a thin instanced "trim" mesh on the front face or a small UV-mapped texture atlas, and only do this if it doesn't compromise performance.

Constraints:

Preserve prefers-reduced-motion fallback and the flat static grid mode exactly as documented in the README.
Preserve the existing house-size-scales-with-disk-footprint logic and beacon strobe behavior — don't touch those systems.
Keep everything to a handful of draw calls total, as the current architecture requires.
Run the existing test suite after the change and confirm nothing breaks.

Show me the diff before applying if there's any ambiguity about which file holds the house geometry.