import { Component } from 'react';

/*
 * Styles are inline rather than in jetgrid.css on purpose: the failure this
 * exists to report includes "the stylesheet never loaded", and a diagnostic that
 * depends on the thing it is diagnosing is no diagnostic at all.
 */
const shell = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: '260px',
    padding: '24px',
    boxSizing: 'border-box',
    background: '#140a0a',
    color: '#ffb4ae',
    font: '13px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
};

const inner = { maxWidth: '760px', width: '100%' };

const heading = {
    margin: '0 0 8px',
    color: '#ff6b6b',
    fontSize: '13px',
    fontWeight: 600,
    letterSpacing: '0.06em',
    textTransform: 'uppercase',
};

const pre = {
    margin: '0 0 12px',
    padding: '12px',
    overflowX: 'auto',
    borderRadius: '4px',
    border: '1px solid #47201f',
    background: '#0d0606',
    color: '#ffd9d6',
    whiteSpace: 'pre-wrap',
    wordBreak: 'break-word',
};

const hint = { margin: 0, color: '#a3736f' };

/**
 * Catches render-time faults in the 3D scene so a broken WebGL layer reports
 * itself instead of presenting as a blank page.
 *
 * It cannot catch a throw during module evaluation — by then React does not yet
 * exist — so the entry files also install a window.onerror handler. The two
 * together cover the whole startup path.
 */
export class SceneErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, info) {
        console.error('[JetGrid] scene crashed:', error, info?.componentStack);
    }

    render() {
        const { error } = this.state;

        if (!error) return this.props.children;

        return (
            <div style={shell}>
                <div style={inner}>
                    <h2 style={heading}>3D scene failed to render</h2>
                    <pre style={pre}>{error?.stack || String(error)}</pre>
                    <p style={hint}>
                        The rest of the page is unaffected. Full stack and component trace are in the
                        browser console.
                    </p>
                </div>
            </div>
        );
    }
}

/**
 * Surfaces a module-evaluation or async failure into the mount node. Without
 * this, an import that throws leaves an empty div and no visible signal at all.
 */
export function reportBootFailure(element, message) {
    if (!element) return;

    element.innerHTML = '';

    const box = document.createElement('div');
    Object.assign(box.style, shell);

    const body = document.createElement('div');
    Object.assign(body.style, inner);

    const title = document.createElement('h2');
    Object.assign(title.style, heading);
    title.textContent = 'Scene bundle failed to load';

    const detail = document.createElement('pre');
    Object.assign(detail.style, pre);
    detail.textContent = message;

    const note = document.createElement('p');
    Object.assign(note.style, hint);
    note.textContent = 'This ran before React mounted — check the console Network tab for a failed module request.';

    body.append(title, detail, note);
    box.append(body);
    element.append(box);
}
