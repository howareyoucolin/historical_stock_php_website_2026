#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG_FILE="${DEPLOY_CONFIG:-${SCRIPT_DIR}/dreamhost.env}"

if [[ ! -f "${CONFIG_FILE}" ]]; then
  echo "Missing deploy config: ${CONFIG_FILE}" >&2
  echo "Copy ${SCRIPT_DIR}/dreamhost.env.example to ${SCRIPT_DIR}/dreamhost.env first." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "${CONFIG_FILE}"

: "${DREAMHOST_HOST:?DREAMHOST_HOST is required}"
: "${DREAMHOST_USERNAME:?DREAMHOST_USERNAME is required}"
: "${DREAMHOST_REMOTE_PATH:?DREAMHOST_REMOTE_PATH is required}"
: "${PROD_DB_HOST:?PROD_DB_HOST is required}"
: "${PROD_DB_NAME:?PROD_DB_NAME is required}"
: "${PROD_DB_USER:?PROD_DB_USER is required}"
: "${PROD_DB_PASSWORD:?PROD_DB_PASSWORD is required}"

DREAMHOST_PORT="${DREAMHOST_PORT:-22}"
PROD_DB_CHARSET="${PROD_DB_CHARSET:-utf8mb4}"
PROD_SESSION_FILES_DIR="${PROD_SESSION_FILES_DIR:-${DREAMHOST_REMOTE_PATH}/storage/stock-reports}"
DELETE_FLAG=""
DRY_RUN=false

for arg in "$@"; do
  case "${arg}" in
    --delete)
      DELETE_FLAG="--delete"
      ;;
    --dry-run)
      DRY_RUN=true
      ;;
    *)
      echo "Unknown option: ${arg}" >&2
      echo "Usage: ./deploy/deploy.sh [--dry-run] [--delete]" >&2
      exit 1
      ;;
  esac
done

if [[ -n "${DREAMHOST_PASSWORD:-}" ]] && ! command -v sshpass >/dev/null 2>&1; then
  echo "sshpass is required when DREAMHOST_PASSWORD is set." >&2
  echo "Install sshpass, or leave DREAMHOST_PASSWORD empty and use SSH key / interactive auth." >&2
  exit 1
fi

# Build SSH options as plain strings (kept array-free so the script also runs on
# the older bash 3.2 shipped with macOS). When a password is configured, force
# password auth so a present-but-unusable local SSH key cannot trigger an
# interactive passphrase prompt that would hang this non-interactive deploy.
SSH_OPTS="-p ${DREAMHOST_PORT} -o StrictHostKeyChecking=accept-new"
SSH_BIN="ssh"
RSYNC_PREFIX=""
if [[ -n "${DREAMHOST_PASSWORD:-}" ]]; then
  SSH_OPTS="${SSH_OPTS} -o PreferredAuthentications=password -o PubkeyAuthentication=no"
  export SSHPASS="${DREAMHOST_PASSWORD}"
  SSH_BIN="sshpass -e ssh"
  RSYNC_PREFIX="sshpass -e"
fi
RSYNC_RSH="ssh ${SSH_OPTS}"

# Run a single command on the DreamHost server over SSH (stdin is forwarded).
remote_exec() {
  ${SSH_BIN} ${SSH_OPTS} "${DREAMHOST_USERNAME}@${DREAMHOST_HOST}" "$1"
}

echo "Ensuring remote directory exists: ${DREAMHOST_REMOTE_PATH}"
if [[ "${DRY_RUN}" == true ]]; then
  echo "[dry-run] Would ensure remote directory exists: ${DREAMHOST_REMOTE_PATH}"
else
  remote_exec "mkdir -p '${DREAMHOST_REMOTE_PATH}'"
fi

echo "Deploying public/ contents to ${DREAMHOST_USERNAME}@${DREAMHOST_HOST}:${DREAMHOST_REMOTE_PATH}"
cd "${PROJECT_ROOT}"

# Sync only the contents of public/ into the site root so the app's front
# controller (index.php) sits at the web root. config.php is excluded here and
# generated remotely from the deploy env below. DRY_FLAG/DELETE_FLAG are left
# unquoted so an empty value contributes no argument.
DRY_FLAG=""
[[ "${DRY_RUN}" == true ]] && DRY_FLAG="--dry-run"
${RSYNC_PREFIX} rsync -az --progress ${DRY_FLAG} ${DELETE_FLAG} \
  --exclude=config.php \
  -e "${RSYNC_RSH}" \
  ./public/ \
  "${DREAMHOST_USERNAME}@${DREAMHOST_HOST}:${DREAMHOST_REMOTE_PATH}/"

if [[ "${DRY_RUN}" == true ]]; then
  echo "[dry-run] Rsync preview complete."
else
  echo "Deploy complete."
fi

REMOTE_CONFIG_PATH="${DREAMHOST_REMOTE_PATH}/config.php"
REMOTE_STORAGE_PATH="${PROD_SESSION_FILES_DIR}"

read -r -d '' REMOTE_CONFIG_CONTENT <<EOF || true
<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => '$(printf '%s' "${PROD_DB_HOST}" | sed "s/'/'\\\\''/g")',
        'database' => '$(printf '%s' "${PROD_DB_NAME}" | sed "s/'/'\\\\''/g")',
        'username' => '$(printf '%s' "${PROD_DB_USER}" | sed "s/'/'\\\\''/g")',
        'password' => '$(printf '%s' "${PROD_DB_PASSWORD}" | sed "s/'/'\\\\''/g")',
        'charset' => '$(printf '%s' "${PROD_DB_CHARSET}" | sed "s/'/'\\\\''/g")',
    ],
    'paths' => [
        'session_files_dir' => '$(printf '%s' "${PROD_SESSION_FILES_DIR}" | sed "s/'/'\\\\''/g")',
    ],
];
EOF

if [[ "${DRY_RUN}" == true ]]; then
  echo "[dry-run] Would write remote config.php at ${REMOTE_CONFIG_PATH}"
  echo "[dry-run] Would ensure session files directory exists at ${REMOTE_STORAGE_PATH}"
else
  echo "Writing remote config.php"
  printf '%s\n' "${REMOTE_CONFIG_CONTENT}" | remote_exec "mkdir -p '${REMOTE_STORAGE_PATH}' && cat > '${REMOTE_CONFIG_PATH}'"
  echo "Remote app config updated."
fi
