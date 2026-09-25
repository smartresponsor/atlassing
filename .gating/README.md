# Gating Gate

Gating is the executable platform ecosystem gate.

Canonical naming:

- repository: `Gating`
- component runtime: `src/`
- consumer artifact surface: `.gating/`
- Composer package: `gating/gate`
- Symfony Console CLI entrypoint: `bin/gating`

The package is Symfony-oriented and starts as a standalone Symfony Console tooling package, not as a Symfony web application. The first responsibility remains repository and component validation.

## Boundary

- `policy/` describes what is accepted.
- `profile/` provides component-specific values.
- `schema/` defines machine-readable result/report/evidence contracts.
- `report/` and `evidence/` store generated output.
- `gate.sh` and `gate.ps1` preserve the existing shell gate.
- `bin/gating` is the package-level CLI entrypoint.
- Consumer `.gating/` is generated artifact state only (reports, evidence, cache, checksums); normative configuration and executable policy stay in Gating/Symfony-owned configuration surfaces.

## Responsibility

Gating consolidates repeated local checks into one executable platform standard. Local component tools may remain as thin wrappers while the shared policy and checks mature.

Gating should stay decoupled from individual ecosystem components. Component-specific values belong in profiles/policy, not in hardcoded rule logic.

## Canon-linked rules

Executable rules that directly enforce a Canonization architecture rule use the
same `CanonNNN<SemanticName>Rule` identity as the normative document, changing
only the file extension from `.md` to `.php`.

Existing generic rules may remain reusable enforcement primitives. They must not
be renamed to a `CanonNNN...Rule` unless they enforce the corresponding canon
rule with the declared coverage. See `docs/canon-rule-contract.md`.
