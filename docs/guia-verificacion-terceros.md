# Guía para verificar un sorteo (terceros y auditores)

Sirve para comprobar, **sin confiar en nuestro servidor**, que los premios de un lote se asignaron como se prometió y que nadie los cambió después. Solo necesita Python 3 (biblioteca estándar, sin instalar nada) y los archivos que el operador le entregue.

## Qué necesita

| Archivo | Qué es | Quién lo publica |
|---|---|---|
| `export.json` | Exportación de auditoría del lote (se genera solo cuando el lote ya está **revelado**) | El operador, al cerrar la campaña |
| `ancla.json` | Id y hash de la última entrada de la bitácora, tomados **antes** | El operador, publicados fuera de su base de datos (junto al compromiso y de forma periódica) |
| `verifier/gamif_verify.py` | El verificador, escrito aparte del backend a propósito | Este repositorio |

El **compromiso** (un hash) se publica al preparar el lote, antes de que existan premios asignados. La semilla se revela al cerrar la campaña. Si el compromiso que usted guardó al inicio coincide con el de `export.json`, el operador no pudo cambiar el reparto después.

## Verificar

```
python3 -I verifier/gamif_verify.py verify export.json --anchor ancla.json
```

Salida esperada: `OK: ...`. Cualquier otra cosa lista cada fallo encontrado y termina con código 1.

## Qué comprueba

1. **El compromiso se reproduce** con la semilla revelada, el pool de premios, el resumen de las huellas de código, la cadena de drand y la ronda comprometida.
2. **La baraja coincide**: recalcula desde cero qué premio le tocó a cada código y lo compara con las asignaciones exportadas. Un solo registro distinto se detecta.
3. **Los conteos son el stock**: cada premio se asignó exactamente la cantidad prometida.
4. **La bitácora es íntegra**: cada entrada guarda el hash de la anterior; alterar, borrar o reordenar cualquiera rompe la cadena desde ahí.
5. **La bitácora y la exportación cuentan lo mismo**: el compromiso, el valor de baliza y la semilla de `export.json` son los registrados en su momento.
6. **Con `--anchor`**: el último hash que se publicó fuera de la base sigue en la cadena, en la misma posición. Esto detecta que se haya **borrado el final** de la bitácora o reescrito la historia completa de forma coherente.

## Lo que debe hacer usted a mano

- **Comparar la baliza con drand.** El verificador no usa la red. Abra `https://api.drand.sh/<chain_hash>/public/<beacon_round>` (con los valores de `export.json`) y confirme que `randomness` es igual a `beacon_value`. Es lo que garantiza que el azar no lo eligió el operador.
- **Guardar el compromiso y el ancla en su poder** cuando se publiquen, no depender de volver a pedírselos al operador después. Sin un ancla que usted conserve, el punto 6 no tiene fuerza: una bitácora truncada sigue siendo una cadena válida y el verificador avisa de ello.

## Lotes con reserva (giro extra)

Si la exportación trae `reserve_assignments` y `commit_version: 2`, el lote reservó casillas para los giros del premio «giro extra». El verificador, además de lo anterior:

- recalcula el mazo completo de `N + R` posiciones (los `N` códigos y las `R` casillas de reserva) con la baraja del compromiso v2, y comprueba que el stock total es el del pool;
- exige la combinación canónica: sin reserva solo vale el compromiso v1 y con reserva solo el v2 (un lote no puede tener dos compromisos válidos);
- comprueba en la bitácora que las casillas se consumieron **en orden** (la k-ésima redención hija recibió la casilla k), que cada una entregó el premio de su casilla, que ningún padre jugó más giros de los que se le concedieron, que esos giros son los que el premio declaró al comprometer, que todo ocurrió con el lote activo y que los giros pendientes anotados al cerrar salen de la cadena;
- comprueba que la reserva exportada es la registrada al comprometer.

Los giros que quedaron sin jugar al cerrar caducan; la bitácora los deja anotados en `batch.closed` (`pending_spins`).

**Límite que el verificador no cubre:** el orden de las casillas es el de llegada de los giros, y quien opera conoce el premio de cada casilla desde la activación; el verificador comprueba que el orden es consistente, no *quién* giró en cada momento ni que no se hayan omitido los últimos eventos (para eso está el ancla).

## Qué NO prueba

- No prueba que cada premio se haya entregado: eso lo hace la app que acredita (el motor solo avisa).
- No prueba que ningún operador conozca de antemano qué código gana (el mapa código↔premio existe cifrado hasta que se revela). Se mitiga y se audita, no se elimina; ver el riesgo R3 de la spec.
- La bitácora no lleva datos personales: los canjes se anotan con un seudónimo.

## Para el operador

```
php artisan gamification:audit:anchor --out=ancla.json                       # al comprometer cada lote y cada cierto tiempo; PUBLÍQUELA fuera
php artisan gamification:audit:export {lote} --out=export.json --anchor=ancla.json   # solo lotes revelados
```

Ninguno sobrescribe archivos existentes.
