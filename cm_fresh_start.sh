#!/usr/bin/env bash
set -euo pipefail

APP_ROOT_DEFAULT="/var/www/html/app"
APP_ROOT="$APP_ROOT_DEFAULT"
FORCE=0
DRYRUN=1
WWW="www-data"

usage() {
  cat <<'TXT'
cm_fresh_start.sh — "fresh start" reset testnih podatkov (inquiries + logs + internal occupancy)

Privzeto: DRY-RUN (nič ne briše). Za dejansko izvedbo dodaj --force.

Uporaba:
  ./cm_fresh_start.sh [--app-root /var/www/html/app] [--force]

Kaj naredi:
  1) Pobriše VSE pod common/data/json/inquiries/ (vključno z logs/)
  2) Iz vseh units/<UNIT>/occupancy.json odstrani samo INTERNAL/soft segmente (source=internal ali lock=soft)
  3) Regenerira occupancy_merged.json (če je v projektu helper)

Ne dotika se:
  - site_settings.json, integrations, prices, special_offers, promo_codes, itd.
TXT
}

log() { echo "[cm_reset] $*"; }

run_rm_tree_as_www() {
  local path="$1"
  if [[ ! -d "$path" ]]; then
    log "SKIP (ne obstaja): $path"
    return 0
  fi

  # varovalka proti napačni poti
  if [[ "$path" != "$APP_ROOT/common/data/json/inquiries"* ]]; then
    log "ERROR: zavrnjena pot (ni inquiries subtree): $path"
    exit 2
  fi

  if (( DRYRUN )); then
    log "DRY-RUN: sudo -u $WWW rm -rf '$path'/*"
  else
    log "RM: $path/* (kot $WWW)"
    sudo -u "$WWW" rm -rf "$path"/* || true
  fi
}

filter_internal_occupancy() {
  local occ_path="$1"
  if [[ ! -f "$occ_path" ]]; then
    log "SKIP (ni occupancy.json): $occ_path"
    return 0
  fi

  # python filter: odstrani internal/soft segmente, pusti ICS hard + ostale
  if (( DRYRUN )); then
    log "DRY-RUN: filter internal/soft iz $occ_path"
    return 0
  fi

  python3 - "$occ_path" <<'PY'
import json, sys, os, tempfile

path = sys.argv[1]

with open(path, "r", encoding="utf-8") as f:
    try:
        arr = json.load(f)
    except Exception as e:
        print(f"[cm_reset] ERROR reading JSON: {path} -> {e}", file=sys.stderr)
        sys.exit(3)

if not isinstance(arr, list):
    # ničesar ne dotikamo, če format ni pričakovan
    print(f"[cm_reset] WARN: occupancy not array, skip: {path}", file=sys.stderr)
    sys.exit(0)

def is_internal(row: dict) -> bool:
    src  = (row.get("source") or "")
    lock = (row.get("lock") or "")
    _id  = str(row.get("id") or "")
    # internal/soft heuristika:
    if src == "internal": return True
    if lock == "soft": return True
    if _id.startswith("soft:"): return True
    return False

out = []
removed = 0
for row in arr:
    if isinstance(row, dict) and is_internal(row):
        removed += 1
        continue
    out.append(row)

# atomic write
d = os.path.dirname(path)
fd, tmp = tempfile.mkstemp(prefix=".occ_tmp_", dir=d, text=True)
os.close(fd)
with open(tmp, "w", encoding="utf-8") as f:
    json.dump(out, f, ensure_ascii=False, indent=2)
    f.write("\n")
os.replace(tmp, path)

print(f"[cm_reset] OK: {os.path.basename(path)} removed_internal={removed} kept={len(out)}")
PY
}

regen_merged_if_possible() {
  local unit_dir="$1"
  local unit_id="$2"

  # Heuristika: če obstaja znan regen helper, ga pokličemo (če ne, samo skip)
  # V tvojem projektu se pogosto uporablja PHP helper cm_regen_merged_for_unit(...)
  local php_try="$APP_ROOT/scripts/regen_merged_unit.php"
  local py_try="$APP_ROOT/scripts/regen_merged_unit.py"

  if (( DRYRUN )); then
    log "DRY-RUN: regen merged za $unit_id (če obstaja helper)"
    return 0
  fi

  if [[ -f "$php_try" ]]; then
    php "$php_try" "$unit_id" >/dev/null 2>&1 || true
    log "regen merged (php helper): $unit_id"
    return 0
  fi

  if [[ -f "$py_try" ]]; then
    python3 "$py_try" "$unit_id" >/dev/null 2>&1 || true
    log "regen merged (py helper): $unit_id"
    return 0
  fi

  # fallback: nič
  log "SKIP regen merged (ni helperja): $unit_id"
}

# --- args ---
while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-root)
      APP_ROOT="$2"; shift 2;;
    --force)
      FORCE=1; DRYRUN=0; shift;;
    -h|--help)
      usage; exit 0;;
    *)
      log "Neznan argument: $1"; usage; exit 1;;
  esac
done

if [[ ! -d "$APP_ROOT/common/data/json" ]]; then
  log "ERROR: app-root ni pravilen (manjka common/data/json): $APP_ROOT"
  exit 2
fi

INQ_ROOT="$APP_ROOT/common/data/json/inquiries"
UNITS_ROOT="$APP_ROOT/common/data/json/units"

log "APP_ROOT=$APP_ROOT"
log "MODE=$([[ $DRYRUN -eq 1 ]] && echo DRY-RUN || echo FORCE)"

if (( DRYRUN )); then
  log "INFO: nič se ne briše. Za izvedbo dodaj --force."
fi

# 1) Pobriši inquiries tree (vse)
run_rm_tree_as_www "$INQ_ROOT"

# 2) Filter internal/soft occupancy.json v vseh enotah
if [[ -d "$UNITS_ROOT" ]]; then
  while IFS= read -r -d '' udir; do
    unit_id="$(basename "$udir")"
    occ="$udir/occupancy.json"
    filter_internal_occupancy "$occ"
    regen_merged_if_possible "$udir" "$unit_id"
  done < <(find "$UNITS_ROOT" -mindepth 1 -maxdepth 1 -type d -print0)
else
  log "WARN: ni units root: $UNITS_ROOT"
fi

log "DONE."
