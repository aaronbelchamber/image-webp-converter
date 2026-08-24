# Copilot instructions

## GitHub Actions — public repos only

CI runs on **public** repos, where Actions minutes are free and unlimited. Do not add or enable
automatic CI on a private repo.

Where a private repo has a public counterpart, the public mirror already runs the full suite for
free and the two copies are near-identical by construction — **that run is the coverage for the
pair.** An automatic private run buys a duplicate of a result already in hand, and bills for it.

- `belchamber-plugins-private` → `image-webp-converter`
- `wordpress-site-manager-private` → `wordpress-site-manager`

To switch CI off on a private repo, change the trigger to manual dispatch:

```yaml
on:
  workflow_dispatch:
```

Never delete the workflow file, and never use `gh workflow disable` — a disabled workflow cannot be
dispatched at all, which defeats the point of keeping it for the offload-and-debug case.

Every Actions job bills **rounded up to a whole minute**: six 15-second jobs cost six minutes, not
one. That makes wide matrices on private repos far more expensive than their wall-clock suggests.

Also worth knowing: workflows are only read from `<repo-root>/.github/workflows/`, and a `paths:`
filter that cannot match produces no runs at all. Both have already left workflows here silently
dead — verify with `gh run list` rather than assuming a registered workflow is a running one.
