#!/usr/bin/env bash
set -euo pipefail

payload="$(cat)"
tool_name="$(jq -r '.tool_name // empty' <<<"$payload")"

deny() {
  echo "$1" >&2
  exit 2
}

case "$tool_name" in
  Edit|Write|NotebookEdit)
    deny "Blocked: the planner does not edit files directly. Delegate this to the builder instead: herdr agent prompt ktn-builder \"<concrete task description>\" --wait --timeout 120000. See HARNESS.md."
    ;;
  Bash)
    command="$(jq -r '.tool_input.command // empty' <<<"$payload")"
    case "$command" in
      herdr\ *|git\ status*|git\ diff*|git\ log*|git\ show*|cat\ *|ls|ls\ *|grep\ *|rg\ *|find\ *|head\ *|tail\ *|wc\ *|pwd)
        exit 0
        ;;
      *)
        deny "Blocked: the planner does not run write/build commands itself (only read-only inspection and \`herdr agent ...\`). Delegate execution to the builder: herdr agent prompt ktn-builder \"<concrete task description>\" --wait --timeout 120000. See HARNESS.md."
        ;;
    esac
    ;;
  *)
    exit 0
    ;;
esac
