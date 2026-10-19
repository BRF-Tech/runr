# shellcheck shell=sh
# Module selection of a Compose install, sourced by build.sh and deploy.sh: TENANCY_ENABLED,
# AUTH_PROVIDER_ENABLED, TICKET_ENABLED and BILLING_ENABLED resolved the way docker-compose.yml resolves them for the
# API containers (the environment first, then .env, then the defaults of docker-compose.yml), so the
# UI is built with the modules the API enables. POSIX sh.

# runr_env_value FILE KEY: the value of KEY in FILE (the last assignment; surrounding quotes and a
# trailing comment dropped), empty when the file or the key is missing
runr_env_value() {
    [ -f "$1" ] || return 0
    sed -n "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*//p" "$1" | tail -n 1 |
        sed -e 's/[[:space:]]#.*$//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/"
}

# runr_modules ENV_FILE: sets and exports TENANCY_ENABLED, AUTH_PROVIDER_ENABLED, TICKET_ENABLED and
# BILLING_ENABLED, each "true" or "false" (like the API entrypoint, only "true" enables a module)
runr_modules() {
    for _runr_spec in TENANCY_ENABLED:false AUTH_PROVIDER_ENABLED:false TICKET_ENABLED:true BILLING_ENABLED:false; do
        _runr_key=${_runr_spec%%:*}
        eval "_runr_val=\${${_runr_key}:-}"
        [ -n "$_runr_val" ] || _runr_val=$(runr_env_value "$1" "$_runr_key")
        [ -n "$_runr_val" ] || _runr_val=${_runr_spec#*:}
        if [ "$_runr_val" = "true" ]; then _runr_val=true; else _runr_val=false; fi
        eval "${_runr_key}=\$_runr_val"
        export "${_runr_key?}"
    done
    unset _runr_spec _runr_key _runr_val
}
