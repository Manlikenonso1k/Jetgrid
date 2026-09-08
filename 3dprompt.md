TASK: Three changes to JetGrid. Do them in order, verify each before the next.

=============================================================
PART A — Add project name labels to the houses
=============================================================

Houses render correctly but have no visible names. Add labels.

Use drei's <Text> (troika SDF text) wrapped in <Billboard> so labels always
face the camera, positioned above each house's roof. NOT <Html> for the primary
label — DOM overlays get expensive at 50+ houses and don't respect scene fog.

Requirements:
- Label text = project name (folder name, or "name" from package.json /
  composer.json)
- Color: off-white (#e8f5e8) for readability. Do NOT make labels emissive or
  toneMapped={false} — they should not bloom, or they'll smear into unreadable
  blobs. Only the beacons bloom.
- Add a small secondary line under the name showing the port when running
  (e.g. ":8000"), dimmer and smaller.
- depthWrite={false} and a raised renderOrder so labels aren't clipped by
  geometry in front of them.

DISTANCE HANDLING (important — this is what makes or breaks it):
- At the default high-altitude camera position, 30+ labels at fixed size will
  overlap into noise. Implement a distance threshold in useFrame: fade label
  opacity to 0 when the camera is beyond a configurable distance, and scale
  labels slightly with distance so they stay legible when zoomed in.
- When labels are hidden (zoomed out), show the name on hover instead, via a
  single <Html> tooltip that follows the hovered house. One DOM node, not N.
- Add a UI toggle: "Always show names" / "On hover" / "Off". Default to
  on-hover-plus-nearby.

Also add a type badge — a small colored dot or glyph beside the name indicating
Laravel / Node / Python / Docker / static.

Verify on /grid-preview with 12+ mock houses at multiple zoom levels before
moving to Part B.

=============================================================
PART B — Move the 3D grid into the Filament panel
=============================================================

The grid should live at /admin/grid inside the Filament panel (with sidebar and
topbar), and be the landing page after login. Keep /grid-preview as a
DEV-ONLY route gated behind app()->environment('local') for art-direction
iteration without auth.

Implementation:
- Create a Filament custom page (php artisan make:filament-page Grid) or
  override the panel's Dashboard page so /admin lands directly on the grid.
  Recommend which and say why.
- Set $maxContentWidth = MaxWidth::Full and strip the page's default padding so
  the canvas goes edge to edge. Filament's default constrained width will
  letterbox the scene otherwise.
- Give the canvas wrapper an explicit resolved height — calc(100vh - topbar
  height) or similar. Do not rely on h-full through a chain of parents; that's
  what caused the earlier blank canvas.

CRITICAL — Livewire will destroy your React tree:
Filament pages are Livewire components. On any Livewire re-render (polling,
notifications, a filter change), Livewire's DOM diffing will rip out or
duplicate the React-mounted node and the canvas goes blank or ghosts.
- Put wire:ignore on the React mount container. Non-negotiable.
- Mount React once on DOMContentLoaded / Alpine init, and guard against double
  mounting if the page is revisited via Livewire navigation (wire:navigate).
  Check for an existing root before calling createRoot.
- If the panel has wire:navigate SPA mode enabled, handle cleanup: unmount the
  React root and dispose the WebGL context on navigate-away, or you'll leak
  contexts and hit the browser's WebGL context limit after a few navigations.

Asset loading:
- Use @vite in the Filament page's blade view pointing at the React entry.
  Confirm that entry is in laravel-vite-plugin's input array.
- Filament ships its own compiled assets; these are independent. Don't try to
  route the React bundle through FilamentAsset.

Theming:
- The Filament chrome stays white/light per the brand. The canvas stays dark.
  Make sure the page background behind the canvas is dark so there's no white
  seam if the canvas doesn't fill perfectly.
- If Filament dark mode is enabled, verify the grid page looks right in both.

Data:
- Feed real discovered projects (not mocks) via a Livewire property serialized
  to JSON in a data attribute, or a small JSON endpoint the React app fetches.
  Recommend one; prefer the endpoint if the payload polls for live status.

=============================================================
PART C — Public marketing homepage at /
=============================================================

/ currently isn't a real landing page. Build one for logged-out visitors
explaining what JetGrid is, with signup as the goal.

Routing:
- / = public landing page, no auth, no Filament panel chrome
- Authenticated visitors hitting / redirect to /admin (the grid)
- Login / Register CTAs point at the Filament panel's auth routes. Ensure
  ->registration() is enabled in the panel provider if signup runs through
  Filament.

Content sections:
1. Hero — what JetGrid is in one line, with a CTA. Something like "Your server,
   seen from above." Include a visual: EITHER a lightweight non-interactive
   version of the 3D scene (fixed camera, slow drift, ~8 houses, NO
   postprocessing) OR a static screenshot/looping video. Recommend which after
   measuring — the landing page must not take seconds to become interactive.
   If the 3D hero pushes LCP past ~2.5s, use the static version.
2. The problem — managing multiple sites on one box, certs expiring, no
   visibility.
3. Features — grid visibility, HTTPS issuance and auto-renewal, capacity
   estimation, protected/adopted sites, local dev mode. Icons, short copy.
4. Pricing tiers — Free / Starter / Pro / Unlimited, with the limits already
   defined in the plans table. Pull from the DB, don't hardcode, so the page
   and the enforcement never drift apart.
5. Footer — repo link, docs, contact.

Style:
- White base, neon green accent, matching the panel. Dark section only where
  the 3D visual sits, so the neon reads.
- Fully responsive. Mobile gets the static visual regardless.
- Blade + Tailwind. Do not pull in a second React app for the landing page; if
  the 3D hero is used, mount it as a single isolated island.

=============================================================
DELIVERABLE
=============================================================
- Files created/modified with a one-line reason each
- Confirmation that /admin/grid survives a Livewire re-render without the
  canvas blanking (test it: trigger a notification or filter change and verify)
- Confirmation that / loads for guests and redirects for authenticated users
- Note any assumption you made about the panel's existing configuration