#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import yaml

ROOT = Path(__file__).resolve().parents[3]
REGISTRY_FILE = ROOT / 'var' / 'atlas' / 'repository' / 'ecosystem-repositories.yaml'
POLICY_FILE = ROOT / 'var' / 'atlas' / 'schedule' / 'assessment-policy.yaml'
SNAPSHOT_ROOT = ROOT / 'var' / 'atlas' / 'component'
OUTPUT_FILE = ROOT / 'var' / 'atlas' / 'generated' / 'selection-plan.json'


def load_yaml(path: Path) -> Any:
    return yaml.safe_load(path.read_text(encoding='utf-8'))


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding='utf-8')


def run(cmd: list[str], cwd: Path) -> tuple[int, str]:
    proc = subprocess.run(cmd, cwd=cwd, text=True, capture_output=True)
    return proc.returncode, (proc.stdout + '\n' + proc.stderr).rstrip()


def parse_iso(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        if 'T' not in value:
            return datetime.fromisoformat(value + 'T00:00:00').replace(tzinfo=timezone.utc)
        dt = datetime.fromisoformat(value.replace('Z', '+00:00'))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt.astimezone(timezone.utc)
    except Exception:
        return None


def now_utc() -> datetime:
    return datetime.now(timezone.utc)


def github_request(path: str, token: str) -> Any:
    req = urllib.request.Request(
        'https://api.github.com' + path,
        headers={
            'Authorization': f'Bearer {token}',
            'Accept': 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28',
            'User-Agent': 'quality-atlas-selector',
        },
    )
    with urllib.request.urlopen(req, timeout=60) as resp:
        return json.loads(resp.read().decode('utf-8'))


def local_head_info(repo_root: Path, branch: str) -> dict[str, Any]:
    rc_branch, out_branch = run(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], repo_root)
    rc_head, out_head = run(['git', 'rev-parse', 'HEAD'], repo_root)
    rc_tag, out_tag = run(['git', 'describe', '--tags', '--exact-match', 'HEAD'], repo_root)
    return {
        'branch': out_branch.splitlines()[0] if rc_branch == 0 and out_branch else branch,
        'head_commit': out_head.splitlines()[0] if rc_head == 0 and out_head else None,
        'head_tag': out_tag.splitlines()[0] if rc_tag == 0 and out_tag else None,
    }


def local_changed_files(repo_root: Path, base: str | None, head: str | None) -> list[str]:
    if not base or not head or base == head:
        return []
    rc, out = run(['git', 'diff', '--name-only', f'{base}..{head}'], repo_root)
    if rc != 0:
        return []
    return [line.strip() for line in out.splitlines() if line.strip()]


def local_dirty_files(repo_root: Path) -> list[str]:
    rc, out = run(['git', 'status', '--porcelain=v1', '--untracked-files=all'], repo_root)
    if rc != 0:
        return []
    paths: list[str] = []
    for line in out.splitlines():
        if len(line) < 4:
            continue
        value = line[3:].strip()
        if ' -> ' in value:
            value = value.split(' -> ', 1)[1]
        if value:
            paths.append(value.replace('\\', '/'))
    return paths


def resolve_local_repo(workspace_root: Path, repo_root: Path, component: dict[str, Any]) -> Path | None:
    component_id = str(component.get('component_id') or '').strip()
    component_title = str(component.get('component_title') or '').strip()
    explicit_local_path = str(component.get('local_path') or '').strip()
    candidates: list[Path] = []
    for name in [component_title, component_id]:
        if name:
            candidates.append(workspace_root / name)
    if workspace_root.exists():
        wanted = {value.lower() for value in [component_id, component_title] if value}
        for child in workspace_root.iterdir():
            if child.is_dir() and child.name.lower() in wanted:
                candidates.append(child)
    if explicit_local_path:
        candidates.extend([repo_root / explicit_local_path, workspace_root / explicit_local_path])
    seen: set[str] = set()
    for candidate in candidates:
        resolved = candidate.resolve()
        key = str(resolved).lower()
        if key in seen:
            continue
        seen.add(key)
        if resolved.is_dir() and (resolved / '.git').exists():
            return resolved
    return None


def remote_head_info(repo_full_name: str, branch: str, token: str) -> dict[str, Any]:
    commit_payload = github_request(f'/repos/{repo_full_name}/commits/{urllib.parse.quote(branch)}', token)
    head_commit = commit_payload.get('sha')
    head_tag = None
    try:
        tags = github_request(f'/repos/{repo_full_name}/tags?per_page=100', token)
        for tag in tags:
            if (tag.get('commit') or {}).get('sha') == head_commit:
                head_tag = tag.get('name')
                break
    except Exception:
        pass
    return {'branch': branch, 'head_commit': head_commit, 'head_tag': head_tag}


def remote_changed_files(repo_full_name: str, base: str | None, head: str | None, token: str) -> list[str]:
    if not base or not head or base == head:
        return []
    try:
        payload = github_request(f'/repos/{repo_full_name}/compare/{base}...{head}', token)
    except urllib.error.HTTPError:
        return []
    return [item.get('filename') for item in payload.get('files') or [] if item.get('filename')]


def classify_changes(paths: list[str], policy: dict[str, Any]) -> tuple[bool, list[str], list[str]]:
    meaningful_prefixes = policy.get('meaningful_path_prefixes') or []
    noise_prefixes = policy.get('noise_path_prefixes') or []
    meaningful: list[str] = []
    noise: list[str] = []
    for path in paths:
        if any(path == prefix or path.startswith(prefix) for prefix in noise_prefixes):
            noise.append(path)
            continue
        if any(path == prefix or path.startswith(prefix) for prefix in meaningful_prefixes):
            meaningful.append(path)
            continue
        meaningful.append(path)
    return bool(meaningful), meaningful[:20], noise[:20]


def load_snapshot_state(component_id: str) -> dict[str, Any]:
    current_path = SNAPSHOT_ROOT / component_id / 'current.yaml'
    if not current_path.exists():
        return {}
    payload = load_yaml(current_path)
    snapshot = payload.get('snapshot') or {}
    basis = snapshot.get('assessment_basis') or {}
    generated_at = snapshot.get('generated_at')
    if not generated_at:
        generated_at = datetime.fromtimestamp(current_path.stat().st_mtime, tz=timezone.utc).isoformat()
    return {
        'snapshot_date': snapshot.get('date'),
        'snapshot_generated_at': generated_at,
        'snapshot_label': snapshot.get('label'),
        'assessed_commit': basis.get('assessed_commit'),
        'assessed_tag': basis.get('assessed_tag'),
    }


def should_select(component: dict[str, Any], policy: dict[str, Any], state: dict[str, Any], head: dict[str, Any], changed_files: list[str], event_name: str) -> tuple[bool, str, dict[str, Any]]:
    override = (policy.get('component_overrides') or {}).get(component['component_id'], {})
    cadence_group = override.get('cadence_group') or component.get('cadence_group') or policy['defaults']['cadence_group']
    cadence = (policy.get('cadence_groups') or {}).get(cadence_group, {})
    min_interval_hours = int(cadence.get('min_interval_hours', policy['defaults']['cooldown_hours']))
    force_refresh_max_age = int(policy['defaults'].get('force_refresh_max_age_hours', 168))
    last_dt = parse_iso(state.get('snapshot_generated_at') or state.get('snapshot_date'))
    hours_since = None if last_dt is None else round((now_utc() - last_dt).total_seconds() / 3600, 2)
    if event_name == 'workflow_dispatch':
        return True, 'manual_override', {'cadence_group': cadence_group, 'hours_since_last_assessment': hours_since}
    if not state.get('snapshot_date'):
        return True, 'bootstrap_missing_snapshot', {'cadence_group': cadence_group, 'hours_since_last_assessment': hours_since}
    if head.get('head_tag') and head.get('head_tag') != state.get('assessed_tag'):
        return True, 'new_tag', {'cadence_group': cadence_group, 'hours_since_last_assessment': hours_since}
    significant, meaningful, noise = classify_changes(changed_files, policy)
    details = {
        'cadence_group': cadence_group,
        'hours_since_last_assessment': hours_since,
        'meaningful_changes': meaningful,
        'noise_changes': noise,
    }
    if head.get('working_tree_dirty') and significant:
        if hours_since is None or hours_since >= min_interval_hours:
            return True, 'meaningful_local_worktree_change', details
        return False, 'cooldown_active', details
    if head.get('head_commit') and state.get('assessed_commit') and head['head_commit'] != state['assessed_commit']:
        if significant:
            if hours_since is None or hours_since >= min_interval_hours:
                return True, 'meaningful_change', details
            return False, 'cooldown_active', details
        return False, 'trivial_change', details
    if hours_since is not None and hours_since >= force_refresh_max_age:
        return True, 'stale_refresh', details
    return False, 'no_meaningful_change', details


def default_selection_details(component: dict[str, Any], policy: dict[str, Any], state: dict[str, Any]) -> dict[str, Any]:
    override = (policy.get('component_overrides') or {}).get(component['component_id'], {})
    cadence_group = override.get('cadence_group') or component.get('cadence_group') or policy['defaults']['cadence_group']
    last_dt = parse_iso(state.get('snapshot_generated_at') or state.get('snapshot_date'))
    hours_since = None if last_dt is None else round((now_utc() - last_dt).total_seconds() / 3600, 2)
    return {
        'cadence_group': cadence_group,
        'hours_since_last_assessment': hours_since,
    }


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--registry', default=str(REGISTRY_FILE))
    parser.add_argument('--policy', default=str(POLICY_FILE))
    parser.add_argument('--repo-root', default=str(ROOT))
    parser.add_argument('--workspace-root', default=str(ROOT.parent))
    parser.add_argument('--event-name', default=os.environ.get('GITHUB_EVENT_NAME', 'workflow_dispatch'))
    parser.add_argument('--component', action='append', dest='components', default=[])
    parser.add_argument('--output', default=str(OUTPUT_FILE))
    args = parser.parse_args()

    registry = load_yaml(Path(args.registry))
    policy = load_yaml(Path(args.policy))
    repo_root = Path(args.repo_root).resolve()
    workspace_root = Path(args.workspace_root).resolve()
    repo_token = os.environ.get('QUALITY_ATLAS_REPO_TOKEN') or os.environ.get('GITHUB_TOKEN') or ''
    repositories = registry.get('repositories') or []
    registry_by_id = {item['component_id']: item for item in repositories if item.get('component_id')}
    registry_by_lower = {str(item['component_id']).lower(): str(item['component_id']) for item in repositories if item.get('component_id')}
    requested_manual = [str(item) for item in (args.components or []) if str(item).strip()]
    normalized_manual: set[str] = set()
    unknown_manual: list[str] = []
    for raw in requested_manual:
        key = raw.strip()
        if not key:
            continue
        resolved = registry_by_id.get(key)
        if resolved is None:
            resolved_id = registry_by_lower.get(key.lower())
            resolved = registry_by_id.get(resolved_id) if resolved_id else None
        if resolved is None:
            unknown_manual.append(raw)
            continue
        normalized_manual.add(resolved['component_id'])

    selected_components: list[str] = []
    items: list[dict[str, Any]] = []
    if unknown_manual:
        payload = {
            'generated_at': now_utc().isoformat(),
            'event_name': args.event_name,
            'manual_components': requested_manual,
            'normalized_manual_components': sorted(normalized_manual),
            'unknown_manual_components': unknown_manual,
            'selected_components': sorted(normalized_manual),
            'selected_count': len(normalized_manual),
            'items': items,
            'status': 'unknown_manual_component',
        }
        write_json(Path(args.output), payload)
        print(json.dumps({'selected_components': sorted(normalized_manual), 'selected_count': len(normalized_manual), 'status': payload['status'], 'unknown_manual_components': unknown_manual, 'output': args.output}, indent=2))
        raise SystemExit(2)

    for component in repositories:
        if not component.get('enabled'):
            continue
        component_id = component['component_id']
        if normalized_manual and component_id not in normalized_manual:
            continue
        state = load_snapshot_state(component_id)
        branch = component.get('default_branch') or policy['defaults'].get('branch', 'master')
        local_path = component.get('local_path') or ''
        github_repository = component.get('github_repository') or ''
        local_repo = resolve_local_repo(workspace_root, repo_root, component)
        head = {'branch': branch, 'head_commit': None, 'head_tag': None}
        changed_files: list[str] = []
        access_error = None
        repository_source = ''
        if local_repo is not None:
            repository_source = str(local_repo)
            head = local_head_info(local_repo, branch)
            committed_changes = local_changed_files(local_repo, state.get('assessed_commit'), head.get('head_commit'))
            dirty_changes = local_dirty_files(local_repo)
            head['working_tree_dirty'] = bool(dirty_changes)
            changed_files = list(dict.fromkeys([*committed_changes, *dirty_changes]))
        elif not local_path and not github_repository:
            access_error = 'No github_repository or local_path configured.'
            select = False
            reason = 'missing_repository_target'
            details = default_selection_details(component, policy, state)
        else:
            if not repo_token or not github_repository:
                access_error = 'No local repository and no accessible GitHub repository target.'
            else:
                try:
                    repository_source = github_repository
                    head = remote_head_info(github_repository, branch, repo_token)
                    changed_files = remote_changed_files(github_repository, state.get('assessed_commit'), head.get('head_commit'), repo_token)
                except Exception as exc:
                    access_error = str(exc)
        if access_error:
            select = False
            reason = 'missing_repository_target' if not local_path and not github_repository else 'blocked_access'
            details = default_selection_details(component, policy, state)
        else:
            select, reason, details = should_select(component, policy, state, head, changed_files, args.event_name)
        item = {
            'component': component_id,
            'title': component.get('component_title'),
            'repository': repository_source or github_repository or local_path,
            'repository_source': 'local' if local_repo is not None else ('remote' if github_repository else 'missing'),
            'selected': select,
            'reason': reason,
            'event_name': args.event_name,
            'current_head_commit': head.get('head_commit'),
            'current_head_tag': head.get('head_tag'),
            'last_assessed_commit': state.get('assessed_commit'),
            'last_assessed_tag': state.get('assessed_tag'),
            'last_assessed_date': state.get('snapshot_date'),
            'changed_files_sample': changed_files[:20],
            'access_error': access_error,
            **details,
        }
        items.append(item)
        if select:
            selected_components.append(component_id)

    payload = {
        'generated_at': now_utc().isoformat(),
        'event_name': args.event_name,
        'manual_components': requested_manual,
        'normalized_manual_components': sorted(normalized_manual),
        'unknown_manual_components': unknown_manual,
        'selected_components': selected_components,
        'selected_count': len(selected_components),
        'items': items,
        'status': 'selected' if selected_components else 'skipped',
    }
    write_json(Path(args.output), payload)
    print(json.dumps({'selected_components': selected_components, 'selected_count': len(selected_components), 'status': payload['status'], 'output': args.output}, indent=2))


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        raise
