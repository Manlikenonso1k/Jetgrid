import { createRoot } from 'react-dom/client';
import { reportBootFailure, SceneErrorBoundary } from './ErrorBoundary';

/**
 * Mounts the 3D dashboard into the custom Filament page.
 *
 * Why a Filament page rather than Inertia: Filament already owns authentication,
 * navigation, the theme and every other screen in this app. Adding Inertia for
 * one route would mean a second routing stack, a second auth surface and two
 * places to keep the layout consistent — for a single canvas that only needs a
 * div and a JSON endpoint. React mounts into the page; everything else stays
 * Livewire.
 */

/*
 * Roots are tracked so they can be unmounted before Livewire swaps the page.
 * A browser allows only a handful of live WebGL contexts (commonly 16), and
 * React roots left mounted across wire:navigate leak one each — after a few
 * navigations the oldest context is force-lost and the canvas dies with no error.
 * Unmounting lets R3F dispose the renderer properly.
 */
const roots = new Map();

function mount(element) {
    if (roots.has(element)) return;

    // Claimed synchronously: the dynamic import below is async, and a second
    // mountAll() can fire before it resolves.
    roots.set(element, null);

    import('./GridDashboard')
        .then(({ GridDashboard }) => {
            if (!element.isConnected) {
                roots.delete(element);
                return;
            }

            const root = createRoot(element);
            roots.set(element, root);

            root.render(
                <SceneErrorBoundary>
                    <GridDashboard
                        gridEndpoint={element.dataset.gridEndpoint}
                        siteEndpoint={element.dataset.siteEndpoint}
                    />
                </SceneErrorBoundary>,
            );
        })
        .catch((error) => {
            roots.delete(element);
            console.error('[JetGrid] dashboard bundle failed to evaluate:', error);
            reportBootFailure(element, error?.stack || String(error));
        });
}

function unmountAll() {
    roots.forEach((root) => root?.unmount());
    roots.clear();
}

function mountAll() {
    document.querySelectorAll('[data-jetgrid-scene]').forEach(mount);
}

document.addEventListener('DOMContentLoaded', mountAll);
// Filament navigates with Livewire, so the element can appear after load.
document.addEventListener('livewire:navigated', mountAll);
// Fires before the outgoing page is torn down, which is the only point where
// the WebGL context can still be released cleanly.
document.addEventListener('livewire:navigating', unmountAll);

mountAll();
