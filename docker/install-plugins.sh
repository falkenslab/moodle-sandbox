#!/usr/bin/env bash
# Instala en el código de Moodle (el directorio actual) los plugins de docker/plugins.txt hechos
# para su versión: install-plugins.sh <lista> <versión de Moodle, v4.5.15>. Lo ejecuta el
# Dockerfile; Moodle los instala en la base de datos al instalar o actualizar el sitio.
set -euo pipefail

list="$1"
moodle="${2#v}"

# Los plugins van en public/ desde Moodle 5.1 y en la raíz del código antes (4.5).
root=""
if [ -d public ]; then root="public/"; fi

# La carpeta de cada tipo de plugin, dentro del código de Moodle.
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
    *) echo "Tipo de plugin desconocido: $1" >&2; return 1 ;;
  esac
}

tmp="$(mktemp -d)"
while read -r series component kind source ref; do
  case "$series" in ''|'#'*) continue ;; esac
  # "4.5" vale para 4.5.x, "5" para 5.x, "*" para todas.
  if [ "$series" != "*" ] && [[ "$moodle" != "$series" && "$moodle" != "$series".* ]]; then continue; fi
  dir="$(plugin_dir "$component")"
  echo "$component ($ref) -> $dir"
  rm -rf "$dir"
  case "$kind" in
    zip)
      curl -fsSL -o "$tmp/plugin.zip" "$source"
      echo "$ref  $tmp/plugin.zip" | md5sum -c --quiet -
      rm -rf "$tmp/unzip" && unzip -q "$tmp/plugin.zip" -d "$tmp/unzip"
      # Un zip del directorio lleva una sola carpeta, con el nombre del plugin.
      mv "$tmp/unzip"/* "$dir"
      ;;
    git)
      # Una etiqueta o un commit: un commit no se puede clonar con --branch.
      git init -q "$dir"
      git -C "$dir" fetch -q --depth 1 "$source" "$ref"
      git -C "$dir" checkout -q FETCH_HEAD
      rm -rf "$dir/.git"
      ;;
    *) echo "Origen desconocido en $component: $kind" >&2; exit 1 ;;
  esac
done < "$list"
rm -rf "$tmp"
