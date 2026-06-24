# Atlassing / Atlas

`atlassing/atlas` owns the Smart Responsor Atlas responsibility: quality signals, maturity/readiness assessment, documentation coverage, component profiles, dashboard payloads, and host-application surfaces.

This repository is separated from `documentating/documentation`:

- `documentating/documentation` owns static Adoc/Antora documentation and exports documentation indexes.
- `atlassing/atlas` consumes documentation indexes and builds Atlas quality/readiness surfaces.
- `interfacing` links to Atlassing surfaces or consumes Atlassing contracts instead of duplicating Atlas state.

## Ownership contract

Atlassing is the canonical owner of Atlas assessment lifecycle and state.

- Atlassing owns scoring contracts, metric catalogs, probe families, repository registry, assessment schedules, snapshots, generated Atlas payloads, and Atlas API surfaces.
- Documentating may publish static documentation derived from Atlas outputs, but it does not own the scoring lifecycle.
- Interfacing may expose interface-level navigation or links to Atlas, but it does not own Atlas scoring state.
- The public Atlas contour is expected to live under `atlass.smartresponsor.com` when deployed as an own site.

## Modes

1. Standalone Symfony-oriented component mode.
2. Symfony bundle mode inside a host application.
3. Payload producer mode for CRUDing + Viewing + Interfacing.

Current runtime note: this repository supports both Symfony bundle integration and its own standalone console shell.

The assessment workflow should prefer `php bin/console` in this repository and keep the transitional Python engine under `tools/atlas/python-engine/` as a controlled fallback.

## Canon

- Composer package: `atlassing/atlas`.
- Autoload root: `App\`.
- Business class prefix: `Atlas*`.
- Database table prefix: `atlas_`.
- API owner root: `/api/atlassing/...`.
- Web owner root: `/atlassing/...`.
- No `/src/Domain/`.
- No Port/Adapter pattern.
- Controllers must not render Atlas UI directly; payload is returned to Viewing.

## Import zone

Previous Quality Atlas materials exported from `documentating/documentation` belong under:

```text
import/documentating-export/
```

They are source material for migration, not final runtime UI ownership.

## W01 Atlas engine import

This cumulative slice includes the clean Atlas engine state exported from Documentating/GitHub sync.

Canonical Atlas runtime/state paths:

```text
var/atlas/contract/
var/atlas/repository/
var/atlas/schedule/
var/atlas/card/
var/atlas/component/
var/atlas/generated/
```

Transitional engine path:

```text
tools/atlas/python-engine/
```

The old `.sync/quality-atlas` path is not the target owner path for Atlassing. Documentating should consume Atlas exports for static Antora publication, not own scoring lifecycle.

