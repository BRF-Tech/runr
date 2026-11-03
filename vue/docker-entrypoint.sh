#!/bin/sh
# RunR UI container start: writes the platform's branding defaults to /branding-defaults.js,
# which index.html reads before the first paint (src/modules/white-label/prePaint.js), so a first
# visit opens in the platform's default theme and colours instead of RunR's dark theme until
# GET /api/branding answers. Then starts the web server (CMD: Caddy).
#
# The values are the API's (the Helm chart gives the ui container the same keys of runr-config:
# branding.colors.*, branding.defaultTheme):
#   BRANDING_PRIMARY_COLOR, BRANDING_ACCENT_COLOR, BRANDING_DARK_COLOR, BRANDING_LIGHT_COLOR
#                           "#rrggbb" (or "#rgb"); "r g b", "r, g, b" or "rgb(r, g, b)" (the chart
#                           writes a shade map's 500 that way) become "#rrggbb"
#   BRANDING_PRIMARY_SCALE, BRANDING_DARK_SCALE, BRANDING_LIGHT_SCALE
#                           a JSON object of shades, {"500":"0 159 227","600":"#007bc0",…}
#   BRANDING_DEFAULT_THEME  dark | light
# A value that is not one of these is left out (and said so): only hex digits, digits, spaces,
# "rgb(…)" and the JSON punctuation of a shade map reach the file, nothing a browser could run.
set -eu

ROOT="${RUNR_UI_ROOT:-/srv}"
TARGET="${ROOT}/branding-defaults.js"

warn() { printf 'runr-ui: %s\n' "$*" >&2; }

# a channel without leading zeros (printf would read "029" as octal), or nothing above 255
channel() {
  n="$(printf '%s' "$1" | sed 's/^0*//')"
  if [ -z "$n" ]; then n=0; fi
  # always status 0: under set -e a failing $(channel …) in an assignment would end the start
  if [ "$n" -le 255 ]; then printf '%s' "$n"; fi
}

# "#rgb" / "#rrggbb", or "r g b" / "r, g, b" / "rgb(r, g, b)" as "#rrggbb", else nothing
color() {
  name="$1"
  value="$(printenv "$name" 2>/dev/null || true)"
  [ -z "$value" ] && return 0
  if printf '%s' "$value" | grep -Eq '^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$'; then
    printf '"%s"' "$value"
    return 0
  fi
  if printf '%s' "$value" | grep -Eq '^(rgb\()? *[0-9]{1,3}[ ,]+[0-9]{1,3}[ ,]+[0-9]{1,3} *\)?$'; then
    # shellcheck disable=SC2046 # three numbers, split on purpose
    set -- $(printf '%s' "$value" | tr -c '0-9' ' ')
    r="$(channel "$1")"; g="$(channel "$2")"; b="$(channel "$3")"
    if [ -n "$r" ] && [ -n "$g" ] && [ -n "$b" ]; then
      printf '"#%02x%02x%02x"' "$r" "$g" "$b"
      return 0
    fi
  fi
  warn "$name is not a #rrggbb colour, left out"
}

# {"<shade>":"<#hex or r g b>", …} (Helm's toJson of a map), else nothing
scale() {
  name="$1"
  value="$(printenv "$name" 2>/dev/null || true)"
  case "$value" in ''|null|'{}') return 0 ;; esac
  # a shade's value as values.schema.json allows it: "#rrggbb", "r g b", "r, g, b", "rgb(r, g, b)"
  pair='"[0-9]{2,3}":"(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|(rgb\()? *[0-9]{1,3}[ ,]+[0-9]{1,3}[ ,]+[0-9]{1,3} *\)?)"'
  if printf '%s' "$value" | grep -Eq "^\{${pair}(,${pair})*\}$"; then
    printf '%s' "$value"
  else
    warn "$name is not a shade map, left out"
  fi
}

theme() {
  case "${BRANDING_DEFAULT_THEME:-}" in
    dark|light) printf '"%s"' "$BRANDING_DEFAULT_THEME" ;;
    '') ;;
    *) warn "BRANDING_DEFAULT_THEME is not dark or light, left out" ;;
  esac
}

json=''
add() {
  [ -z "$2" ] && return 0
  json="${json}${json:+,}\"$1\":$2"
}
add primary_color "$(color BRANDING_PRIMARY_COLOR)"
add primary_scale "$(scale BRANDING_PRIMARY_SCALE)"
add accent_color "$(color BRANDING_ACCENT_COLOR)"
add dark_color "$(color BRANDING_DARK_COLOR)"
add dark_scale "$(scale BRANDING_DARK_SCALE)"
add light_color "$(color BRANDING_LIGHT_COLOR)"
add light_scale "$(scale BRANDING_LIGHT_SCALE)"
add default_theme "$(theme)"

if [ -n "$json" ]; then
  body="{${json}}"
else
  body='null'
fi

# A read-only file system keeps the image's file (no defaults): the console still starts
if ! printf '// Written by the RunR UI container at start (docker-entrypoint.sh)\nwindow.__RUNR_BRANDING_DEFAULTS__ = %s\n' "$body" > "$TARGET" 2>/dev/null; then
  warn "could not write ${TARGET}: the console starts without the platform's branding defaults"
fi

exec "$@"
