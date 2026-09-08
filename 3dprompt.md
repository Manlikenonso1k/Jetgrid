DEBUG: /grid-preview renders a blank white page. /admin/sites (pure Filament,
no React) works fine. The Vite IPv6 fix was applied but nothing changed.

Do NOT guess or apply another speculative fix. Bisect the problem
systematically and report findings at each step before moving on.

STEP 1 — Eliminate the Vite dev server entirely
Run `npm run build`, then delete public/hot, then load /grid-preview with the
dev server STOPPED so Laravel serves compiled assets from public/build.
- If the page now renders: the fault is in the Vite dev server config, not the
  React code. Report that and stop.
- If still white: the fault is in the React code or the Blade wiring. Continue.
This single step splits the problem space in half — do it first.

STEP 2 — Verify the HTML actually contains what it should
Curl or view-source the raw HTML of /grid-preview and confirm:
- The @vite directive emitted a <script type="module"> tag
- The script src is reachable (fetch it directly, check for 200 and that the
  body is JS, not an HTML error page)
- The mount element exists, and its id/class EXACTLY matches what the React
  entry file targets in createRoot(document.getElementById(...))
Report the actual mount element id found in the Blade file and the actual
selector used in the JS entry file, side by side. A mismatch here is silent.

STEP 3 — Prove React mounts at all
Temporarily replace the entire scene component with a plain
<div style={{color:'red'}}>MOUNTED</div>. Load the page.
- Nothing: React isn't mounting. Problem is entry wiring or the Vite react
  plugin. Check vite.config.js actually includes @vitejs/plugin-react and that
  the entry file is listed in laravel-vite-plugin's input array.
- "MOUNTED" appears: React is fine, the fault is inside the 3D scene. Continue.

STEP 4 — Canvas sizing
R3F's <Canvas> sizes to its parent. If any ancestor has no resolved height, the
canvas collapses to 0px and renders nothing with NO console error.
Inspect the rendered DOM and report the computed height of the canvas and every
ancestor up to <body>. Fix by giving the canvas wrapper an explicit height
(e.g. style={{height:'70vh'}} or a fixed px value) — not h-full on a chain of
parents that never resolves.

STEP 5 — Dependency compatibility
Print the installed versions of three, @react-three/fiber, @react-three/drei,
and @react-three/postprocessing from package.json AND node_modules. These have
strict peer requirements — postprocessing in particular breaks hard against
mismatched three/R3F versions and can throw during module evaluation, which
blanks the page. Report the versions and whether they are a compatible set.
If incompatible, tell me the correct version set BEFORE changing anything.

STEP 6 — Isolate the scene
Comment out <EffectComposer>/<Bloom> and render a single <mesh> with a
boxGeometry and meshBasicMaterial. If that shows, re-add postprocessing and
confirm it's the culprit. Add an error boundary around the scene so future
failures surface a message instead of a white page.

REPORT FORMAT
For each step: what you ran, the literal output, and your conclusion. Do not
apply a fix until you've identified which step fails. If two steps could both
explain it, say so rather than picking one.

Add a permanent React error boundary around the grid scene as part of the fix,
so this never presents as a silent white page again.