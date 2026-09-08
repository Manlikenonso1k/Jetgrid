import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Polls the grid endpoint. Polling rather than websockets is a deliberate
 * choice for a small instance — see GridDataController for the reasoning.
 *
 * The server tells US how often to come back (pollMs), so a deployment can
 * speed the loop up to 3s and it drops back to 15s on its own afterwards. The
 * tab stops polling entirely when hidden, so a dashboard left open overnight on
 * a second monitor costs nothing.
 */
export function useGridData(endpoint) {
    const [data, setData] = useState(null);
    const [error, setError] = useState(null);
    const timer = useRef(null);
    const cancelled = useRef(false);

    const poll = useCallback(async () => {
        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Grid endpoint returned ${response.status}`);
            }

            const payload = await response.json();

            if (cancelled.current) return;

            setData(payload);
            setError(null);

            return payload.pollMs ?? 15000;
        } catch (e) {
            if (cancelled.current) return;
            setError(e.message);
            // Back off on failure so a dead endpoint is not hammered.
            return 30000;
        }
    }, [endpoint]);

    useEffect(() => {
        cancelled.current = false;

        const schedule = (ms) => {
            clearTimeout(timer.current);
            timer.current = setTimeout(run, ms);
        };

        const run = async () => {
            if (document.hidden) {
                schedule(5000);
                return;
            }

            const next = await poll();
            if (!cancelled.current) schedule(next ?? 15000);
        };

        run();

        const onVisible = () => {
            if (!document.hidden) run();
        };

        document.addEventListener('visibilitychange', onVisible);

        return () => {
            cancelled.current = true;
            clearTimeout(timer.current);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [poll]);

    return { data, error };
}

/** Honour the OS setting, and keep honouring it if the user changes it live. */
export function usePrefersReducedMotion() {
    const [reduced, setReduced] = useState(
        () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false,
    );

    useEffect(() => {
        const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        if (!query) return;

        const onChange = (event) => setReduced(event.matches);
        query.addEventListener('change', onChange);

        return () => query.removeEventListener('change', onChange);
    }, []);

    return reduced;
}
