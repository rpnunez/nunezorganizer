#!/usr/bin/env bash
# Render deploy/templates/**/*.tpl with values from deploy.env.
#
#   render-templates.sh [--out DIR]          # all templates -> DIR (default deploy/build)
#   render-templates.sh --stdout FILE.tpl    # one template -> stdout
#
# Placeholders look like {{VAR}} and must be set in deploy.env (or the
# environment). Rendering fails if any placeholder is unset or left over, so
# nothing half-configured reaches the server. Pure bash: no envsubst needed.

DEPLOY_SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source-path=SCRIPTDIR
source "$DEPLOY_SELF_DIR/lib.sh"

OUT_DIR="$DEPLOY_DIR/build"
STDOUT_TPL=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --out) OUT_DIR="${2:?}"; shift 2 ;;
    --stdout) STDOUT_TPL="${2:?}"; shift 2 ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

load_config

render() {
  local tpl="$1" content name val
  content="$(<"$tpl")"
  while [[ "$content" =~ \{\{([A-Z][A-Z0-9_]*)\}\} ]]; do
    name="${BASH_REMATCH[1]}"
    [[ -n "${!name+x}" ]] || die "$tpl: {{$name}} is not set in $NUNEZ_DEPLOY_CONFIG"
    val="${!name}"
    content="${content//"{{$name}}"/$val}"
  done
  [[ "$content" != *"{{"*"}}"* ]] || die "$tpl: unrendered placeholder left"
  printf '%s\n' "$content"
}

if [[ -n "$STDOUT_TPL" ]]; then
  render "$STDOUT_TPL"
  exit 0
fi

mkdir -p "$OUT_DIR"
count=0
while IFS= read -r -d '' tpl; do
  rel="${tpl#"$DEPLOY_DIR/templates/"}"
  dest="$OUT_DIR/${rel%.tpl}"
  mkdir -p "$(dirname "$dest")"
  render "$tpl" > "$dest"
  count=$((count + 1))
  log "rendered $rel"
done < <(find "$DEPLOY_DIR/templates" -name '*.tpl' ! -path '*/env/*' -print0 | sort -z)
log "$count file(s) in $OUT_DIR. Review them, then see docs/runbook.md#install-server-files"
