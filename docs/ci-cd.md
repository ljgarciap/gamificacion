# CI/CD (GitHub Actions)

Archivo: `.github/workflows/ci-cd.yml`. Cada cambio se prueba y, si es un push a `master` y pasa todo, se despliega a la demostración (VPS) por SSH.

## Qué hace
| Trabajo | Cuándo | Qué comprueba |
|---|---|---|
| `backend` | PR y push | `docker compose` con PostgreSQL 16 y `php artisan test` (≈ 874 pruebas, con concurrencia real) |
| `verifier` | PR y push | Pruebas del verificador independiente (Python) y que `vectors.json` es el que genera el verificador |
| `flutter` | PR y push | `dart format`, `flutter analyze` y las pruebas de cada paquete y de la app (Flutter 3.32.8) |
| `panel` | PR y push | Pruebas unitarias, compilación de producción y `npm audit` (solo dependencias de producción, nivel alto) |
| `deploy` | **solo push a `master`** (o ejecución manual desde `master`), después de que pasen los cuatro | Sube el código al servidor con `rsync` y corre `deploy/demo-up.sh` |

Las pruebas E2E del navegador (`admin-panel/e2e`) no corren en CI: necesitan la pila completa y gastan códigos reales; se corren a mano (`docs/qa-spec-005.md`).

Todos los pasos se comprobaron desde un clon limpio del repositorio antes de publicarlos (backend 874 pasan; verificador y vectores; formato y análisis de Flutter; panel con `npm ci`, pruebas, compilación y auditoría).

## Cómo se despliega
1. Se prueba el commit. Si falla algo, **no se despliega**.
2. `rsync` sube el árbol del commit a `/opt/gamificacion` en el servidor (sin `.git`) y borra lo que ya no existe. **Se conservan** `deploy/demo.env` (secretos), `deploy/demo-credentials.txt`, `deploy/seed.log` y `deploy/.seeded`.
3. `deploy/demo-up.sh` reconstruye lo que cambió, levanta, migra la base (`migrate --force`) y comprueba que `/up` responde. **No vuelve a sembrar** códigos de prueba (solo la primera vez o con `DEMO_SEED=1`) y no imprime secretos en el registro.
4. Despliegues simultáneos se encolan (no se pisan).

Es un despliegue con reinicio breve (no «sin cortes»): los contenedores que cambian se recrean. Para una demo basta; para producción real habría que construir imágenes versionadas y hacer un despliegue gradual.

## Configuración (una sola vez)
Los secretos van en *Settings → Secrets and variables → Actions* del repositorio. **Sin ellos el trabajo `deploy` solo avisa y no hace nada** (las pruebas siguen corriendo).

| Secreto | Valor |
|---|---|
| `DEPLOY_HOST` | IP o nombre del servidor |
| `DEPLOY_SSH_KEY` | Llave privada SSH **dedicada** al despliegue (no la personal) |
| `DEPLOY_KNOWN_HOSTS` | Salida de `ssh-keyscan <host>` (fija la identidad del servidor: sin esto no se confía en él) |

Pasos:
```bash
# 1) Llave nueva solo para el despliegue
ssh-keygen -t ed25519 -N "" -C "gamificacion-ci" -f ./deploy_key
# 2) Autorizarla en el servidor, sin reenvíos
ssh root@<host> "echo 'no-agent-forwarding,no-port-forwarding,no-X11-forwarding $(cat deploy_key.pub)' >> ~/.ssh/authorized_keys"
# 3) Marca de «ya sembrado» (si la demo ya tiene códigos), para que el CI no vuelva a sembrar
ssh root@<host> "touch /opt/gamificacion/deploy/.seeded"
# 4) Secretos del repositorio
gh secret set DEPLOY_SSH_KEY < deploy_key
gh secret set DEPLOY_HOST --body "<host>"
ssh-keyscan <host> 2>/dev/null | gh secret set DEPLOY_KNOWN_HOSTS
# 5) Borrar la llave privada local
rm deploy_key deploy_key.pub
```
Opcional pero recomendable: en *Settings → Environments → demo* agregar **Required reviewers**: cada despliegue espera una aprobación manual en GitHub.

## Volver a una versión anterior (rollback)
*Actions → CI/CD → Run workflow*, elegir la rama o crear una desde el commit bueno. El flujo vuelve a probar y a desplegar ese commit. (Las migraciones no se deshacen solas: un cambio de esquema destructivo requiere un plan aparte.)

## Seguridad
- El repositorio es público: **nunca** se versionan secretos (`deploy/demo.env` está en `.gitignore`); los de CI viven en *Secrets*, que GitHub no entrega a los pull requests de forks.
- `deploy` no corre en pull requests ni fuera de `master`.
- Los secretos entran al paso por variables de entorno, no interpolados en el script.
- La llave de despliegue es de `root` en el servidor de demostración: acotarla (usuario sin privilegios con acceso a Docker y a `/opt/gamificacion`) es lo recomendable antes de usarla con algo que no sea una demo.
