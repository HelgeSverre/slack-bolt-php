set -g _BOLT_SHOW_DESCRIPTIONS 1

function __bolt_project_directory
    set -l command $argv[1]
    set -l dir (command dirname -- "$command")

    if string match -q 'vendor/bin/bolt' -- "$command"
        or string match -q '*/vendor/bin/bolt' -- "$command"
        set dir "$dir/../.."
    end

    realpath -- "$dir" 2>/dev/null
end

function __bolt_is_bolt_command
    set -l tokens (commandline -xpc)

    test (count $tokens) -gt 0
    or return 1

    set -l command_name (command basename -- "$tokens[1]")

    if test "$command_name" = php
        test (count $tokens) -ge 2
        or return 1

        set command_name (command basename -- "$tokens[2]")
    end

    test "$command_name" = bolt
end

function __bolt_completions
    set -l tokens (commandline -xpc)
    set -l current_token (commandline -ct)

    test (count $tokens) -gt 0
    or return

    set -l command "$tokens[1]"

    if test (command basename -- "$command") = php
        test (count $tokens) -ge 2
        or return

        set command "$tokens[2]"
    end

    set -l project_directory (__bolt_project_directory "$command")
    or return

    set -l helper "$project_directory/vendor/bin/bolt-complete"
    set -l metadata "$project_directory/.thunder-bolt/completion/commands.json"

    test -x "$helper"
    or return

    test -f "$metadata"
    or return

    if test -z "$current_token"
        set -a tokens ''
    else
        set -a tokens "$current_token"
    end

    set -l current_index (math (count $tokens) - 1)

    set -l output ($helper "$metadata" "$current_index" $tokens 2>/dev/null)
    or return

    set -l tab (printf '\t')

    for line in $output
        test -n "$line"
        or continue

        if string match -q "*$tab*" -- "$line"
            set -l parts (string split -m 1 "$tab" -- "$line")
            set -l value "$parts[1]"
            set -l description "$parts[2]"

            if test -z "$value"
                continue
            end

            if test "$_BOLT_SHOW_DESCRIPTIONS" != 1
                echo -- "$value"
                continue
            end

            if test "$value" = "$description"
                echo -- "$value"
                continue
            end

            set -l escaped_value (string escape --style=regex -- "$value")
            set -l strip_pattern (string join '' '^' "$escaped_value" '[[:space:]]+')
            set description (string replace -r "$strip_pattern" '' -- "$description")

            if test -n "$description"
                echo -- "$value$tab$description"
            else
                echo -- "$value"
            end

            continue
        end

        echo -- "$line"
    end
end

complete -c bolt -f -a '(__bolt_completions)'
complete -c php -n '__bolt_is_bolt_command' -f -a '(__bolt_completions)'
# Adapted from Tempest 3.20.0; see NOTICE and docs/research/reference/tempest/LICENSE.md.
