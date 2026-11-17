# SPEC-007 — Planes de premio por monto en la muestra (Ruleta de Aguinaldos)

Estado: **APROBADA** por Luis (2026-11-17, «aprobada, procede con el diseño»; los valores ⚑ siguen siendo provisionales). Diseño: `diseno-spec-007.md`. Rol: Analista. Fecha: 2026-11-17. Amplía `spec-006-demo-desde-el-panel.md` y `spec-006a-cuentas-de-la-muestra.md`. Origen: Project Charter «Ruleta de Aguinaldos SuperGiros» (sponsor José Díaz G., Hub Digital Nacional; go-live 7-dic-2026, cierre 15-ene-2027).

> **Todos los valores numéricos de esta spec son PROVISIONALES** (marcados con ⚑). Los fija Luis/el negocio; en la muestra se cambian desde el panel sin desplegar. Nada de esto se escribe como constante en el código (regla del workspace); lo único fijo en código son los **techos duros** de seguridad (§7).

## 1. Objetivo
Que la muestra se comporte como lo pide el charter: la ruleta se dispara por **tres situaciones** (compra, primer ingreso, regreso tras una semana sin comprar), y el premio posible depende del **plan** del usuario, que sale del **monto de la compra** (Bronce, Plata, Oro) o de su situación (Bienvenida/Reactivación). Así el negocio ve en la demo, antes del 7 de diciembre, cómo se sentirá cada plan.

## 2. Qué NO cambia
El servidor decide el premio (sorteo CSPRNG sobre un pool sembrado con stock exacto y verificable), la barrera de canje de la muestra (cada código solo lo juega su dueño), las cuentas con correo y código, la idempotencia de la compra y el saldo simulado. Los planes solo cambian **de qué pool sale el código** y **cuándo se otorga**.

## 3. Alcance

### 3.1 Los cuatro planes (⚑ valores provisionales; todo editable en el panel)
Cada plan tiene: nombre, condición de entrada, y su propia **mezcla de premios** (cuántos códigos de cada premio y cuántos sin premio). La **probabilidad de premio de un plan = premios / (premios + sin premio)** de su mezcla, así que es exacta y verificable, no «aproximada».

| Plan | Entra cuando… | Prob. ⚑ | Premios ⚑ (por cada 100 códigos) |
|---|---|---|---|
| Bienvenida / Reactivación | primer ingreso, o 7 días sin comprar | 100 % (charter) | $2.000 ×45 · $3.000 ×45 · $5.000 ×10 (máximo del charter) |
| Bronce | compra de $1.000 a $10.000 | 5 % («muy baja») | $10.000 ×1 · cupón básico Kupi ×4 · sin premio ×95 |
| Plata | compra de $10.001 a $50.000 | 15 % («media-baja») | $20.000 ×1 · $10.000 ×2 · cupón Kupi ×12 · sin premio ×85 |
| Oro | compra de $50.001 en adelante | 40 % (charter) | $30.000 ×2 · $20.000 ×5 · $10.000 ×13 · cupón Kupi mejor ×20 · sin premio ×60 |

- **Los premios de Kupi se representan con el tipo «cupón» que ya existe** (⚑ provisional). La redención real por correo y la alternativa de portafolio SuperGiros (electromenores, boletas, electrodomésticos) quedan fuera (§4).
- Cada premio en dinero respeta el máximo del charter de su plan (Bienvenida $5.000, Bronce $10.000, Plata $20.000, Oro $30.000); el panel **no deja guardar** una mezcla que lo supere (el tope por plan es un parámetro, no una constante).
- Los **umbrales** de monto ($1.000 / $10.000 / $50.000) son parámetros del panel. El panel valida que los rangos no se solapen ni dejen huecos (dos filas no pueden reclamar el mismo monto; todo monto ≥ el mínimo cae en exactamente un plan).

### 3.2 Los tres disparadores
1. **Compra** (cuenta como compra solo si incluye productos que participan, §3.3): se calcula el monto de lo que participa, se elige el plan por rangos y se entrega **un turno** de ese plan (⚑ «turnos por compra» = 1, parámetro; hoy el sistema daba uno por cada $20.000, esa regla se reemplaza en el modo por planes).
2. **Primer ingreso:** al crearse la cuenta, queda **un turno pendiente** del plan Bienvenida.
3. **Regreso tras una semana sin comprar:** al ingresar, si la cuenta no compró en los últimos **7 días** (⚑ parámetro) y no se le otorgó ya un turno de reactivación en esa ventana, queda un turno pendiente de Bienvenida. Un turno de Bienvenida por ventana, aunque ingrese varias veces.
   - Para poder **ver esto en la demo sin esperar una semana**, el superadmin tiene en la lista de cuentas un botón **«Simular una semana sin comprar»** (retrasa la marca de última compra de esa cuenta). Queda en la bitácora.

El servidor decide el plan y el turno; la app **nunca** manda el plan ni el monto elegible (solo ids y cantidades, como hoy).

### 3.3 Productos que no participan
El catálogo de la muestra permite marcar cada producto como **«participa»** o **«no participa»**. Por defecto (⚑) se agregan al catálogo de ejemplo «Pasa Plata», «Saca Plata» y «Recarga de Saldo» como **no participan**. Una compra que solo tiene productos que no participan se procesa y **no otorga turno**. En una compra mixta, el monto que elige el plan es **solo el de lo que participa**.
- Se mantiene la carga de saldo simulada tal como hoy (porcentaje de la compra); ⚑ decisión abierta D31.

### 3.4 Juego pendiente
Ya existe: el turno otorgado aparece en «Tus códigos» sin jugar y se puede abrir después de cerrarlo. En la muestra eso cubre el «juego pendiente» del charter (sin push ni campanita, §4). La app deja claro **cuál plan** otorgó cada turno (texto, no color).

### 3.5 Panel («Muestra»)
- Nuevo bloque **«Planes»**: por plan, nombre, rango de monto (o condición), mezcla de premios, probabilidad **calculada y mostrada** (premios/total), y su tope de premio en dinero. Mismas validaciones y la misma vista previa que ya tiene la mezcla.
- Bloque **«Disparadores»**: días sin compra (⚑ 7), turnos por compra (⚑ 1), turno de bienvenida al primer ingreso (sí/no).
- Catálogo: columna «participa».
- **Sembrar** crea un pool por plan con el stock exacto de cada mezcla; el resumen de estado muestra cuántos códigos quedan **por plan**.
- Modo: la configuración tiene un selector **«Pasos por monto» (actual) / «Planes»**. Las muestras ya configuradas siguen funcionando igual (se completa con el modo actual); el modo «Planes» es el predeterminado para una muestra nueva.

## 4. Fuera de alcance (etapa posterior, necesitan decisión o integración del host)
- **Correos a ganadores** (bono cargado / instrucciones de Kupi 100 % por correo): hoy el único correo es el del código de ingreso.
- **Premios de Kupi reales y productos del portafolio** (electromenores, boletas, electrodomésticos): la nota de viabilidad del charter es una **decisión de negocio abierta (D33)**.
- **Push y campanita de notificaciones:** viven en la app de SuperGiros. Nuestro lado es avisar al host que hay un juego pendiente (la bandeja de eventos ya existe); el host manda el push.
- **Pop-up sobre la pantalla principal o de éxito de la compra:** lo arma la app host con el SDK; en la muestra el flujo seguirá siendo pantalla completa.
- **Ventana de campaña 7-dic-2026 a 15-ene-2027:** en la muestra ya existe la caducidad en horas; el control por fechas se trata con el host real.

## 5. Decisiones abiertas de Luis (con propuesta por defecto)
- **D29 Probabilidades «muy baja» / «media-baja»:** ⚑ 5 % y 15 %.
- **D30 Premios y cantidades de cada plan:** ⚑ los de la tabla §3.1.
- **D31 Carga de saldo simulada:** ⚑ se mantiene el % sobre toda la compra; alternativa: solo sobre lo que participa.
- **D32 Compra menor a $1.000:** ⚑ no otorga turno.
- **D33 Kupi no viable sin app:** ⚑ en la muestra los premios de Kupi son «cupón»; decidir cuándo se cambia por premios del portafolio.
- **D34 Bienvenida/Reactivación:** ⚑ los $2.000 y $3.000 del charter son «bonos para la primera compra»; en la muestra se cargan como saldo al jugar (premio en dinero normal), sin condicionarlos a una compra posterior.

## 6. Criterios de aceptación
- **CA-70** Una compra de $8.000 de productos que participan otorga un turno del plan **Bronce**; $10.000 exactos, Bronce; $10.001, **Plata**; $50.000, Plata; $50.001, **Oro**; $999, ningún turno. (Las fronteras se prueban una por una.)
- **CA-71** Una compra de solo «Pasa Plata»/«Saca Plata»/«Recarga de Saldo» se procesa y no otorga turno. En una compra mixta el plan sale solo del monto que participa.
- **CA-72** Una cuenta nueva recibe un turno de Bienvenida. Reingresar no genera otro.
- **CA-73** Con la cuenta marcada «sin comprar 7 días», el siguiente ingreso otorga un turno de Bienvenida **una sola vez** por ventana; una compra reinicia la cuenta.
- **CA-74** La probabilidad mostrada en el panel coincide con premios/total de la mezcla y con el pool sembrado (el conteo real de la base). Un plan con mezcla 100 % premio nunca entrega «sin premio».
- **CA-75** El panel rechaza: rangos solapados o con huecos; un premio en dinero sobre el tope de su plan; un plan sin mezcla; una mezcla que supera el techo duro de códigos.
- **CA-76** Un cliente que manda un plan, un monto o un producto ajeno no puede elegir su plan (se ignora o se rechaza): el plan lo calcula el servidor.
- **CA-77** Se agotan los códigos de un plan: la compra de ese rango responde «sin códigos» sin tocar los pools de los demás planes y sin cobrar saldo de más (sin estado a medias).
- **CA-78** Dos compras simultáneas de la misma cuenta no otorgan más turnos que el tope por sesión; la misma `Idempotency-Key` repite el mismo resultado.
- **CA-79** Una muestra configurada **antes** de SPEC-007 sigue funcionando exactamente igual (modo «Pasos por monto»).
- **CA-80** Recorrido de extremo a extremo en el navegador: configurar planes desde el panel → sembrar → entrar con correo → comprar en cada rango → jugar el turno → ver el plan y el premio; verificado con el verificador independiente.

## 7. Seguridad y límites (para el diseño)
- La compra elige el plan **solo en el servidor** con el monto de los productos del catálogo (nunca con un monto del cliente).
- Techos duros en código (no editables desde el panel): turnos por compra, códigos por plan, planes por muestra, premio máximo absoluto, ventana mínima de reactivación. El panel solo se mueve dentro de ellos.
- El motor de sorteo y el verificador **no cambian**: cada plan es un conjunto de códigos con stock exacto.
- Revisar en el diseño: carrera entre otorgar el turno de reactivación y una compra simultánea; que el botón de simulación solo exista con el permiso de gestionar la muestra y quede en la bitácora.

## 8. Hitos propuestos (para el PM, tras aprobación)
M11 backend: configuración de planes, validaciones, pool por plan, elección de plan en la compra, productos que no participan. M12 backend: disparadores de bienvenida/reactivación y simulación. M13 panel: bloques «Planes» y «Disparadores», catálogo con «participa». M14 app: mostrar el plan de cada turno. M15 despliegue, E2E CA-80, QA y documentación.
