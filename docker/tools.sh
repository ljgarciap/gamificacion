#!/bin/sh
# Comandos de calidad dentro del contenedor: analyze | test | all
set -e
cd /app
flutter pub get
case "${1:-all}" in
  analyze) for d in packages/* apps/*; do (cd "$d" && flutter analyze); done ;;
  test)    for d in packages/* apps/*; do [ -d "$d/test" ] && (cd "$d" && flutter test); done ;;
  all)     sh /app/docker/tools.sh analyze && sh /app/docker/tools.sh test ;;
  *) echo "uso: tools [analyze|test|all]"; exit 1 ;;
esac
