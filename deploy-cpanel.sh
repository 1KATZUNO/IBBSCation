#!/bin/bash
#
# Publica en el web root lo que ya esta en el repo.
#
# POR QUE EXISTE:
# Las tareas de .cpanel.yml (entre ellas copiar public/* al web root) solo se
# ejecutan cuando se usa "Deploy HEAD Commit" desde la interfaz de cPanel.
# Si se actualiza el codigo con un `git pull` desde el Terminal, las vistas y
# el codigo PHP quedan al dia --porque Laravel corre desde el repo-- pero
# public/ NO se copia: por eso imagenes y assets nuevos daban 404 mientras el
# resto de la app ya mostraba los cambios.
#
# USO (desde ~/repositories/IBBSCation):
#   bash deploy-cpanel.sh
#
set -u

REPOPATH="${REPOPATH:-/home/ibbsccou/repositories/IBBSCation}"
DEPLOYPATH="${DEPLOYPATH:-/home/ibbsccou/public_html/IBBSC}"
PHP="${PHP:-/usr/local/bin/ea-php82}"
[ -x "$PHP" ] || PHP="php"

cd "$REPOPATH" || { echo "No existe $REPOPATH"; exit 1; }
[ -f artisan ] || { echo "No parece el repo de Laravel: falta artisan"; exit 1; }
[ -d "$DEPLOYPATH" ] || { echo "No existe el web root $DEPLOYPATH"; exit 1; }

echo "Repo:     $REPOPATH"
echo "Web root: $DEPLOYPATH"
echo "PHP:      $PHP"
echo

echo "==> Copiando public/ al web root"
cp -R public/* "$DEPLOYPATH"/
cp public/.htaccess "$DEPLOYPATH"/ 2>/dev/null || true

echo "==> Rehaciendo el symlink de storage"
rm -rf "$DEPLOYPATH/storage"
ln -s "$REPOPATH/storage/app/public" "$DEPLOYPATH/storage"

echo "==> Limpiando caches de Laravel"
"$PHP" artisan optimize:clear

echo "==> Permisos"
chmod -R 755 "$DEPLOYPATH" 2>/dev/null || true
chmod -R 775 "$REPOPATH/storage" "$REPOPATH/bootstrap/cache" 2>/dev/null || true

echo
echo "==> Verificacion de imagenes en el web root"
faltan=0
for f in public/images/*; do
  n="$(basename "$f")"
  if [ -f "$DEPLOYPATH/images/$n" ]; then
    echo "    OK      images/$n"
  else
    echo "    FALTA   images/$n"
    faltan=$((faltan + 1))
  fi
done

echo
if [ "$faltan" -eq 0 ]; then
  echo "Listo. Todas las imagenes estan publicadas."
else
  echo "Atencion: $faltan imagen(es) no llegaron al web root."
fi
