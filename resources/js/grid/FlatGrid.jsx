/**
 * The prefers-reduced-motion fallback (Feature 1).
 *
 * Not a degraded 3D scene — a genuinely different, static presentation that
 * carries the same information: colour, health, traffic and remaining capacity,
 * with no motion at all.
 */
export function FlatGrid({ houses, emptyPlots, onSelect }) {
    return (
        <div className="jg-flat">
            {houses.map((house) => (
                <button
                    key={house.id}
                    className="jg-flat__card"
                    data-protected={house.protected}
                    onClick={() => onSelect(house)}
                >
                    <div className="jg-flat__top">
                        <span className="jg-chip__dot" style={{ background: house.beaconHex }} />
                        <span className="jg-flat__name" title={house.domain}>
                            {house.domain}
                        </span>
                    </div>
                    <div className="jg-flat__meta">
                        {house.protected ? 'Adopted — protected' : `Health ${house.health}/100`}
                        {house.certDays !== null && house.certDays !== undefined && ` · cert ${house.certDays}d`}
                        {house.traffic > 0 && ` · ${house.traffic} req/min`}
                    </div>
                </button>
            ))}

            {emptyPlots.map((plot, i) => (
                <div key={`plot-${i}`} className="jg-flat__card jg-flat__empty">
                    free plot
                </div>
            ))}

            {houses.length === 0 && (
                <p className="jg-empty-state">
                    No sites yet. Run <code>php artisan jetgrid:discover</code> to import what is
                    already on this server, read-only.
                </p>
            )}
        </div>
    );
}
