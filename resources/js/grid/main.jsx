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
 *
 * GridDashboard is loaded dynamically so a failure inside three/drei is reported
 * in the page instead of leaving an empty div behind.
 */
function mount(element) {
    if (element.dataset.jgMounted === 'true') return;
    element.dataset.jgMounted = 'true';

    import('./GridDashboard')
        .then(({ GridDashboard }) => {
            createRoot(element).render(
                <SceneErrorBoundary>
                    <GridDashboard
                        gridEndpoint={element.dataset.gridEndpoint}
                        siteEndpoint={element.dataset.siteEndpoint}
                    />
                </SceneErrorBoundary>,
            );
        })
        .catch((error) => {
            console.error('[JetGrid] dashboard bundle failed to evaluate:', error);
            reportBootFailure(element, error?.stack || String(error));
        });
}

function mountAll() {
    document.querySelectorAll('[data-jetgrid-scene]').forEach(mount);
}

document.addEventListener('DOMContentLoaded', mountAll);
// Filament navigates with Livewire, so the element can appear after load.
document.addEventListener('livewire:navigated', mountAll);
mountAll();
