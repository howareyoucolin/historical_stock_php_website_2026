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

SSH_CMD=(ssh -p "${DREAMHOST_PORT}" -o StrictHostKeyChecking=accept-new)
RSYNC_PREFIX=()
RSYNC_RSH="ssh -p ${DREAMHOST_PORT} -o StrictHostKeyChecking=accept-new"

if [[ -n "${DREAMHOST_PASSWORD:-}" ]]; then
  export SSHPASS="${DREAMHOST_PASSWORD}"
  SSH_CMD=(sshpass -e "${SSH_CMD[@]}")
  RSYNC_PREFIX=(sshpass -e)
fi

echo "Ensuring remote directory exists: ${DREAMHOST_REMOTE_PATH}"
if [[ "${DRY_RUN}" == true ]]; then
  echo "[dry-run] Would ensure remote directory exists: ${DREAMHOST_REMOTE_PATH}"
else
  "${SSH_CMD[@]}" "${DREAMHOST_USERNAME}@${DREAMHOST_HOST}" "mkdir -p '${DREAMHOST_REMOTE_PATH}'"
fi

echo "Deploying stock_report_website to ${DREAMHOST_USERNAME}@${DREAMHOST_HOST}:${DREAMHOST_REMOTE_PATH}"
cd "${PROJECT_ROOT}"

RSYNC_ARGS=(
  -az
  --progress
  ${DELETE_FLAG}
  $([[ "${DRY_RUN}" == true ]] && printf '%s' "--dry-run")
  --exclude=.git/
  --exclude=.gitignore
  --exclude=.dockerignore
  --exclude=deploy/
  --exclude=Dockerfile
  --exclude=docker-compose.yml
  --exclude=public/config.php
  --exclude=storage/stock-reports/
  -e "${RSYNC_RSH}"
  ./
  "${DREAMHOST_USERNAME}@${DREAMHOST_HOST}:${DREAMHOST_REMOTE_PATH}/"
)

# Remove empty argument when --delete is not requested.
FILTERED_RSYNC_ARGS=()
for arg in "${RSYNC_ARGS[@]}"; do
  if [[ -n "${arg}" ]]; then
    FILTERED_RSYNC_ARGS+=("${arg}")
  fi
done

"${RSYNC_PREFIX[@]}" rsync "${FILTERED_RSYNC_ARGS[@]}"

if [[ "${DRY_RUN}" == true ]]; then
  echo "[dry-run] Rsync preview complete."
else
  echo "Deploy complete."
fi

REMOTE_CONFIG_PATH="${DREAMHOST_REMOTE_PATH}/public/config.php"
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
  echo "[dry-run] Would write remote public/config.php at ${REMOTE_CONFIG_PATH}"
  echo "[dry-run] Would ensure session files directory exists at ${REMOTE_STORAGE_PATH}"
else
  echo "Writing remote public/config.php"
  printf '%s\n' "${REMOTE_CONFIG_CONTENT}" | "${SSH_CMD[@]}" "${DREAMHOST_USERNAME}@${DREAMHOST_HOST}" "mkdir -p '${REMOTE_STORAGE_PATH}' && cat > '${REMOTE_CONFIG_PATH}'"
  echo "Remote app config updated."
fi
