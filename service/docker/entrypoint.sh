#!/bin/sh
# Container entrypoint: it only execs the start command, so the worker and scheduler services
# run this same image with their own command. Migrations are not run here (an entrypoint runs
# once per replica and would race); the platform runs them once per deploy.
set -eu

exec "$@"
