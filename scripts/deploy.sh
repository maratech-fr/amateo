#!/usr/bin/env bash
# Local wrapper behind `make deploy [VERSION=vX.Y.Z]` — dispatches the deploy
# workflow (workflow_dispatch) and follows THE run it just created (not whatever
# ran last). This is what actually DEPLOYS: pushing a tag
# (`git tag vX.Y.Z && git push origin vX.Y.Z`) only builds and publishes the
# images (« tag ≠ déploiement »), it never rolls the VM.
set -euo pipefail

VERSION="${1:-}"

if [ -z "$VERSION" ]; then
  # Deploying "the current commit" only makes sense if origin/main HAS it —
  # workflow_dispatch builds origin's default branch, not the local tree.
  git fetch -q origin
  LOCAL=$(git rev-parse HEAD)
  REMOTE=$(git rev-parse origin/main)
  if [ "$LOCAL" != "$REMOTE" ]; then
    echo "FATAL: HEAD ($(git rev-parse --short HEAD)) != origin/main ($(git rev-parse --short origin/main))." >&2
    echo "       Le dispatch construit origin/main : push d'abord, ou passe VERSION=<tag>." >&2
    exit 1
  fi
fi

# 60s slack: createdAt is GitHub's clock — a locally-fast workstation clock
# must not hide the run we just dispatched. `first` still picks the newest.
STARTED=$(date -u -d '60 seconds ago' +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -v-60S +%Y-%m-%dT%H:%M:%SZ)
if [ -n "$VERSION" ]; then
  gh workflow run deploy.yml -f "version=$VERSION"
else
  gh workflow run deploy.yml
fi

# Attach to the run CREATED by this dispatch: poll for a run newer than the
# dispatch timestamp (the API often lags well past a fixed sleep).
echo "==> Waiting for the run to appear..."
RUN_ID=""
for _ in $(seq 1 30); do
  # event filter: a concurrent tag-push deploy must not be mistaken for OUR
  # dispatch (both runs share the workflow; only ours is workflow_dispatch).
  RUN_ID=$(gh run list --workflow=deploy.yml --limit 5 \
    --json databaseId,createdAt,event \
    --jq "[.[] | select(.event == \"workflow_dispatch\" and .createdAt >= \"$STARTED\")] | first | .databaseId" 2>/dev/null || true)
  [ -n "$RUN_ID" ] && [ "$RUN_ID" != "null" ] && break
  sleep 2
done
if [ -z "$RUN_ID" ] || [ "$RUN_ID" = "null" ]; then
  echo "FATAL: dispatched run not found after 60s — check GitHub → Actions → Deploy." >&2
  exit 1
fi

# --exit-status: a red deploy run must red this command too.
exec gh run watch --exit-status "$RUN_ID"
