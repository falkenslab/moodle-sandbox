#!/usr/bin/env bash
# Installs into Moodle's code (the current directory) the plugins of docker/plugins.txt made for
# its version: install-plugins.sh <list> <Moodle version, v4.5.15>. The Dockerfile runs it; Moodle
# installs them in the database when the site is installed or upgraded.
set -euo pipefail

list="$1"
moodle="${2#v}"

# Plugins go in public/ from Moodle 5.1 on, and in the code's root before (4.5).
root=""
if [ -d public ]; then root="public/"; fi

# Each plugin type's folder, inside Moodle's code.
plugin_dir() {
  local type="${1%%_*}" name="${1#*_}"
  case "$type" in
    mod)        echo "${root}mod/$name" ;;
    qtype)      echo "${root}question/type/$name" ;;
    qbehaviour) echo "${root}question/behaviour/$name" ;;
    block)      echo "${root}blocks/$name" ;;
    format)     echo "${root}course/format/$name" ;;
    theme)      echo "${root}theme/$name" ;;
    report)     echo "${root}report/$name" ;;
    tiny)       echo "${root}lib/editor/tiny/plugins/$name" ;;
    *) echo "Unknown plugin type: $1" >&2; return 1 ;;
  esac
}

tmp="$(mktemp -d)"
while read -r series component kind source ref; do
  case "$series" in ''|'#'*) continue ;; esac
  # "4.5" matches 4.5.x, "5" matches 5.x, "*" matches all.
  if [ "$series" != "*" ] && [[ "$moodle" != "$series" && "$moodle" != "$series".* ]]; then continue; fi
  dir="$(plugin_dir "$component")"
  echo "$component ($ref) -> $dir"
  rm -rf "$dir"
  case "$kind" in
    zip)
      curl -fsSL -o "$tmp/plugin.zip" "$source"
      echo "$ref  $tmp/plugin.zip" | md5sum -c --quiet -
      rm -rf "$tmp/unzip" && unzip -q "$tmp/plugin.zip" -d "$tmp/unzip"
      # A plugins-directory zip holds a single folder, named after the plugin.
      mv "$tmp/unzip"/* "$dir"
      ;;
    git)
      # A tag or a commit: a commit can't be cloned with --branch.
      git init -q "$dir"
      git -C "$dir" fetch -q --depth 1 "$source" "$ref"
      git -C "$dir" checkout -q FETCH_HEAD
      rm -rf "$dir/.git"
      ;;
    *) echo "Unknown source in $component: $kind" >&2; exit 1 ;;
  esac
done < "$list"
rm -rf "$tmp"
