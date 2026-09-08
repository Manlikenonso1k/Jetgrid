import { useState } from 'react';
import { FlatGrid } from './FlatGrid';
import { Scene } from './Scene';
import { SidePanel } from './SidePanel';
import { useGridData, usePrefersReducedMotion } from './useGridData';

const LEGEND = [
    ['#39FF14', 'healthy'],
    ['#FF3B30', 'down / overloaded'],
    ['#FFD60A', 'updates or cert expiring'],
    ['#3B9DFF', 'deploying'],
    ['#8A8F98', 'adopted — monitoring only'],
];

export function GridDashboard({ gridEndpoint, siteEndpoint }) {
    const { data, error } = useGridData(gridEndpoint);
    const systemReducedMotion = usePrefersReducedMotion();
    const [flatOverride, setFlatOverride] = useState(null);
    const [selected, setSelected] = useState(null);

    const flat = flatOverride ?? systemReducedMotion;

    const houses = data?.houses ?? [];
    const emptyPlots = data?.emptyPlots ?? [];
    const capacity = data?.capacity;
    const server = data?.server;

    // Keep the open panel in step with new polls without losing the selection.
    const selectedHouse = selected ? houses.find((h) => h.id === selected.id) ?? selected : null;

    return (
        <div className="jg-scene">
            {!data && !error && <p className="jg-empty-state">Reading the server…</p>}

            {error && (
                <p className="jg-empty-state">
                    Could not load grid data: {error}
                    <br />
                    Retrying automatically.
                </p>
            )}

            {data && houses.length === 0 && (
                <p className="jg-empty-state">
                    No sites yet. Run <code>php&nbsp;artisan&nbsp;jetgrid:discover</code> to import what
                    is already on this server — read-only, nothing is modified.
                </p>
            )}

            {data && houses.length > 0 && (
                flat ? (
                    <FlatGrid houses={houses} emptyPlots={emptyPlots} onSelect={setSelected} />
                ) : (
                    <Scene
                        houses={houses}
                        emptyPlots={emptyPlots}
                        selectedId={selectedHouse?.id ?? null}
                        onSelect={setSelected}
                        reducedMotion={systemReducedMotion}
                    />
                )
            )}

            {data && (
                <div className="jg-scene__hud">
                    {data.readOnly && (
                        <span className="jg-chip jg-chip--warn" title={data.readOnlyReason}>
                            READ-ONLY
                        </span>
                    )}

                    {capacity && (
                        <span className="jg-chip jg-chip--neon">{capacity.headline}</span>
                    )}

                    {capacity?.burningCredits && (
                        <span className="jg-chip jg-chip--danger">
                            Burning CPU credits faster than they are earned
                        </span>
                    )}

                    {server && (
                        <span className="jg-chip">
                            RAM {server.ramPercent ?? '—'}% · disk {server.diskPercent ?? '—'}% · load{' '}
                            {server.load1 ?? '—'}
                        </span>
                    )}

                    {LEGEND.map(([hex, label]) => (
                        <span className="jg-chip" key={label}>
                            <span className="jg-chip__dot" style={{ background: hex }} />
                            {label}
                        </span>
                    ))}

                    <button
                        className="jg-chip"
                        style={{ pointerEvents: 'auto', cursor: 'pointer' }}
                        onClick={() => setFlatOverride(!flat)}
                    >
                        {flat ? 'Show 3D grid' : 'Show flat grid'}
                    </button>
                </div>
            )}

            <SidePanel site={selectedHouse} endpointBase={siteEndpoint} onClose={() => setSelected(null)} />
        </div>
    );
}
