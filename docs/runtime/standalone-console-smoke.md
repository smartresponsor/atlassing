# Standalone console smoke

Atlassing ships a standalone Symfony console runtime in addition to Symfony bundle integration.

The runtime shell is expected to support Atlas maintenance commands without requiring a host application.

## Commands

```powershell
php bin/console list
php bin/console atlas:assessment:select --component documentating --event-name workflow_dispatch --output var/atlas/generated/selection-plan.json
```

## Expected result

The targeted selection command should execute through the Symfony command boundary and write the Atlas selection plan through the Python engine backend.

Expected stable signals:

```text
selected_count: 1
selected_components: documentating
repository: local/documentating
access_error: null
status: selected
```

## State policy

`var/atlas/generated/selection-plan.json` is runtime-generated evidence and may contain volatile timestamps and current commit identifiers.

Do not commit smoke-generated changes to `var/atlas/generated/selection-plan.json` unless the generated Atlas state is intentionally being promoted.

