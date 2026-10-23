if ! (( $+functions[compdef] )); then
    autoload -Uz compinit 2>/dev/null
    compinit -i 2>/dev/null
fi

if (( $+functions[compdef] )); then
    typeset -g _BOLT_SHOW_DESCRIPTIONS=1

    if [[ -z "${_bolt_php_original_compdef:-}" ]] && [[ -n "${_comps[php]:-}" ]] && [[ "${_comps[php]}" != "_bolt" ]]; then
        typeset -g _bolt_php_original_compdef="${_comps[php]}"
    fi

    _bolt_php_passthrough() {
        local service=php

        if [[ -n "${_bolt_php_original_compdef:-}" ]] && (( $+functions[$_bolt_php_original_compdef] )); then
            "$_bolt_php_original_compdef"
            return $?
        fi

        if (( $+functions[_default] )); then
            _default
            return $?
        fi

        return 1
    }

    _bolt_resolve_command() {
        local command command_name passthrough_status

        if [[ "${words[1]}" == "php" ]]; then
            if (( ${#words[@]} < 2 )) || [[ -z "${words[2]}" ]]; then
                _bolt_php_passthrough
                passthrough_status=$?
                REPLY="$passthrough_status"
                return 3
            fi

            command="${words[2]}"
            command_name="${command:t}"

            if [[ "$command_name" != "bolt" ]]; then
                _bolt_php_passthrough
                passthrough_status=$?
                REPLY="$passthrough_status"
                return 3
            fi
        else
            command="${words[1]}"
            command_name="${command:t}"
        fi

        [[ "$command_name" == "bolt" ]] || return 2

        REPLY="$command"
        return 0
    }

    _bolt_project_directory() {
        local command="$1"

        if [[ "$command" == "vendor/bin/bolt" || "$command" == */vendor/bin/bolt ]]; then
            REPLY="${command:h:h:A}"
            return 0
        fi

        REPLY="${command:h:A}"
        return 0
    }

    _bolt_add_completions() {
        local output="$1"
        local candidate completion_value completion_display tab
        local -a completions with_equals_values with_equals_display without_equals_values without_equals_display

        completions=("${(@f)output}")
        tab=$'\t'

        for candidate in "${completions[@]}"; do
            [[ -z "$candidate" ]] && continue

            completion_value="${candidate%%$tab*}"
            completion_display="$completion_value"

            # Helper entries can be "value<TAB>description".
            if [[ "$candidate" == *"$tab"* ]]; then
                completion_display="${candidate#*$tab}"
            fi

            [[ -z "$completion_value" ]] && continue

            if [[ "$completion_value" == *= ]]; then
                with_equals_values+=("$completion_value")
                with_equals_display+=("$completion_display")
            else
                without_equals_values+=("$completion_value")
                without_equals_display+=("$completion_display")
            fi
        done

        if (( ${#without_equals_values[@]} )); then
            if (( _BOLT_SHOW_DESCRIPTIONS )); then
                compadd -Q -l -d without_equals_display -- "${without_equals_values[@]}"
            else
                compadd -Q -- "${without_equals_values[@]}"
            fi
        fi

        if (( ${#with_equals_values[@]} )); then
            if (( _BOLT_SHOW_DESCRIPTIONS )); then
                compadd -Q -l -d with_equals_display -S '' -- "${with_equals_values[@]}"
            else
                compadd -Q -S '' -- "${with_equals_values[@]}"
            fi
        fi
    }

    _bolt() {
        local command project_directory helper metadata output resolve_status

        _bolt_resolve_command
        resolve_status=$?

        case "$resolve_status" in
            0)
                command="$REPLY"
                ;;
            3)
                return "$REPLY"
                ;;
            *)
                return 0
                ;;
        esac

        _compskip=all

        _bolt_project_directory "$command"
        project_directory="$REPLY"

        helper="${project_directory}/vendor/bin/bolt-complete"
        metadata="${project_directory}/.thunder-bolt/completion/commands.json"

        [[ -x "$helper" ]] || return 0
        [[ -f "$metadata" ]] || return 0

        output="$($helper "$metadata" "$((CURRENT - 1))" "${words[@]}" 2>/dev/null)" || return 0
        [[ -z "$output" ]] && return 0

        _bolt_add_completions "$output"
        return 0
    }

    compdef _bolt ./bolt
    compdef _bolt vendor/bin/bolt
    compdef _bolt ./vendor/bin/bolt
    compdef _bolt php
    compdef _bolt -p '*/bolt'
    compdef _bolt -p '*/vendor/bin/bolt'
fi
# Adapted from Tempest 3.20.0; see NOTICE and docs/research/reference/tempest/LICENSE.md.
