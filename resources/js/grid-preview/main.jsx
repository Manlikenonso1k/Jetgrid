import { createRoot } from 'react-dom/client';
import { reportBootFailure, SceneErrorBoundary } from '../grid/ErrorBoundary';
import './preview.css';

const element = document.getElementById('jetgrid-preview');

/*
 * PreviewScene is imported dynamically so that a throw while three, drei or
 * postprocessing are being evaluated lands in the catch below. A static import
 * is hoisted above every statement here, so the failure would happen before any
 * handler existed and the page would simply stay blank.
 */
if (element) {
    window.addEventListener('error', (event) => {
        console.error('[JetGrid] uncaught error during boot:', event.error ?? event.message);
    });

    import('./PreviewScene')
        .then(({ GridPreview }) => {
            createRoot(element).render(
                <SceneErrorBoundary>
                    <GridPreview />
                </SceneErrorBoundary>,
            );
        })
        .catch((error) => {
            console.error('[JetGrid] preview bundle failed to evaluate:', error);
            reportBootFailure(element, error?.stack || String(error));
        });
}
