import { useEffect, useState } from 'react';

const bytes = (n) => {
    if (!n) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = n;
    let i = 0;
    while (value >= 1024 && i < units.length - 1) {
        value /= 1024;
        i++;
    }
    return `${value.toFixed(1)} ${units[i]}`;
};

/** Detail for one house. */
export function SidePanel({ site, endpointBase, onClose }) {
    const [detail, setDetail] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!site) return;

        let cancelled = false;
        setDetail(null);
        setError(null);

        fetch(`${endpointBase}/${site.id}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => {
                if (!r.ok) throw new Error(`Request failed (${r.status})`);
                return r.json();
            })
            .then((d) => !cancelled && setDetail(d))
            .catch((e) => !cancelled && setError(e.message));

        return () => {
            cancelled = true;
        };
    }, [site, endpointBase]);

    if (!site) return null;

    return (
        <aside className="jg-panel" aria-label={`Details for ${site.domain}`}>
            <button className="jg-panel__close" onClick={onClose} aria-label="Close details">
                ×
            </button>

            <h2>{site.domain}</h2>
            <p className="jg-panel__mode">{detail?.modeLabel ?? (site.protected ? 'Adopted — Protected' : 'Managed by JetGrid')}</p>

            {error && <p className="jg-locked">Could not load details: {error}</p>}

            <dl style={{ margin: 0 }}>
                <div className="jg-row">
                    <dt>Beacon</dt>
                    <dd>
                        <span
                            className="jg-chip__dot"
                            style={{ background: site.beaconHex, display: 'inline-block', marginRight: 6 }}
                        />
                        {site.beacon}
                    </dd>
                </div>
                <div className="jg-row">
                    <dt>Health</dt>
                    <dd>
                        {site.health}/100 <span style={{ color: '#8a97a1' }}>({site.healthBand})</span>
                    </dd>
                </div>
                <div className="jg-row">
                    <dt>Status</dt>
                    <dd>{site.status}</dd>
                </div>
                <div className="jg-row">
                    <dt>Disk</dt>
                    <dd>{bytes(site.diskBytes)}</dd>
                </div>
                <div className="jg-row">
                    <dt>Traffic</dt>
                    <dd>{site.traffic} req/min</dd>
                </div>
                <div className="jg-row">
                    <dt>Pending updates</dt>
                    <dd>{site.pendingUpdates}</dd>
                </div>
                <div className="jg-row">
                    <dt>Certificate</dt>
                    <dd>
                        {site.certDays === null || site.certDays === undefined
                            ? 'none found'
                            : `${site.certDays} days (${site.certStatus})`}
                    </dd>
                </div>
                {detail?.documentRoot && (
                    <div className="jg-row">
                        <dt>Document root</dt>
                        <dd style={{ fontSize: '0.72rem', wordBreak: 'break-all' }}>{detail.documentRoot}</dd>
                    </div>
                )}
                {detail?.vhostPath && (
                    <div className="jg-row">
                        <dt>vhost</dt>
                        <dd style={{ fontSize: '0.72rem', wordBreak: 'break-all' }}>{detail.vhostPath}</dd>
                    </div>
                )}
                {detail?.aliases?.length > 0 && (
                    <div className="jg-row">
                        <dt>Aliases</dt>
                        <dd style={{ fontSize: '0.72rem' }}>{detail.aliases.join(', ')}</dd>
                    </div>
                )}
            </dl>

            {/*
              Safety constraint #1: for a protected site the action buttons are
              not disabled, they are NOT RENDERED. A disabled button still says
              "this is something JetGrid does to this site", and it is not.
            */}
            {site.protected ? (
                <div className="jg-locked">
                    <strong>Adopted — monitoring only</strong>
                    JetGrid did not create this site and will never modify, restart, reconfigure or
                    issue certificates for it. No role, including god mode, can lift this. Its
                    certificate expiry above is read from disk, never touched.
                </div>
            ) : (
                <div style={{ marginTop: '1rem', display: 'flex', flexWrap: 'wrap', gap: '0.4rem' }}>
                    {(detail?.actions ?? []).map((action) => (
                        <span key={action} className="jg-chip jg-chip--neon" style={{ pointerEvents: 'auto' }}>
                            {action}
                        </span>
                    ))}
                    <p style={{ fontSize: '0.7rem', color: '#8a97a1', marginTop: '0.5rem', width: '100%' }}>
                        Actions run from the site's page in the panel, where each one shows its exact
                        shell command for confirmation first.
                    </p>
                </div>
            )}
        </aside>
    );
}
