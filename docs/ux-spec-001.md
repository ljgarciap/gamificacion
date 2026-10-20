# UX/UI SPEC-001 — Flujo de código, Ruleta, Raspa y Resultado

Estado: borrador para aprobación de Luis. Base visual: artifact "Gira y Gana" (SuperGIROS).

## Tokens de diseño (tema por defecto)
| Token | Valor | Uso |
|---|---|---|
| primary | #0B4EF0 | botón principal, acentos |
| navy | #14235C | títulos, texto fuerte, cabecera |
| accent | #FFC800 | premios, puntero ruleta, resaltados |
| info | #21C1F3 | segmentos alternos, detalles |
| background | #F2F4F7 | fondo de pantalla |
| surface | #FFFFFF | tarjetas |
| error | #D32F2F | errores de código |
| success | #1B9E5A | premio ganado |
Tipografía: Inter/Poppins (configurable). Radio de tarjetas 16, botones 12. Espaciado base 8.
Contraste mínimo AA (texto navy sobre blanco ≈ 14:1; texto blanco sobre primary ≈ 5.5:1).

## Pantalla 1 — Ingreso de código
- Cabecera: saldo (opcional, oculta si el host la desactiva) + botón cerrar si está embebido.
- Ilustración/título "Gira y Gana" (texto según juego de la campaña, configurable).
- Campo de código: mayúsculas automáticas, sin espacios, `autofillHints` off, pegar permitido.
- Botón "Validar código" (deshabilitado vacío; spinner durante la llamada, bloquea doble toque).
- Enlace "¿Dónde encuentro mi código?" → bottom sheet con texto/imagen configurable.
- Estados de error bajo el campo (rojo + icono, `Semantics liveRegion`): inválido, ya usado, vencido, campaña inactiva, sin conexión (con "Reintentar"), demasiados intentos (con tiempo de espera).
- Modo demo: chip "DEMO" y panel plegable con códigos de prueba (solo build no productivo).

## Pantalla 2a — Ruleta
- Ruleta centrada, ocupa ≤ 88 % del ancho y ≤ 55 % del alto del viewport (responsive en iframe pequeño).
- 4–12 segmentos, colores alternos de la paleta, icono + etiqueta corta por segmento (etiqueta se abrevia si no cabe).
- Puntero fijo arriba en accent, "rebota" al pasar por cada divisoria (efecto tick).
- Botón central "GIRAR" (el código ya fue validado; el giro es acción explícita del usuario porque la redención ya ocurrió).
- Animación: 4.6 s, 5 vueltas, ease-out cubic, offset aleatorio dentro del segmento (±35 % del ancho del segmento para no quedar en bordes). Háptico/sonido opcionales y desactivables por el host.
- "Reducir movimiento" activo → giro corto (1 s) con cross-fade al segmento ganador.
- Durante el giro: botón cerrar deshabilitado con aviso "Tu premio ya está asegurado".

## Pantalla 2b — Raspa y Gana
- Tarjeta con cuadrícula 3×3 (configurable) de símbolos, cubierta por capa plateada con textura sutil y texto "RASPA AQUÍ".
- Raspado con dedo/mouse: trazo de ~28 px, la capa se borra en tiempo real.
- Barra fina de progreso (opcional) y revelado automático con fade al superar 60 %.
- Botón secundario "Revelar todo" siempre visible (accesibilidad, usuarios sin gestos finos).
- Línea/símbolos ganadores se resaltan con accent y pulso breve tras revelar.
- Reducir movimiento → sin pulso, resaltado estático.

## Pantalla 3 — Resultado
- Premio: tarjeta blanca, icono por tipo (dinero, cupón, puntos, giro extra), monto o descripción grande en navy, banda success. Confeti breve (omitido con reducir movimiento).
- Dinero: "$ 50.000" con formato COP; texto "Se acreditó a tu saldo", el saldo de cabecera hace count-up.
- Cupón: muestra código del cupón con botón "Copiar".
- Puntos: "+250 puntos". Giro extra: botón principal "Girar de nuevo (n restantes)".
- Sin premio: tono amable, sin rojo ("¡Esta vez no hubo suerte!") + CTA continuar.
- Acciones: "Continuar" (primario) y "Volver al inicio" (secundario), etiquetas y destinos entregados por el host.
- Reabrir código usado con `showPrevious`: misma pantalla con aviso "Este código ya fue jugado el {fecha}".

## Navegación inferior
Del artifact (Inicio / Recarga / Transacciones) pertenece a la app **host**, no al módulo. El módulo no la dibuja; solo respeta `safe area` e `inset` inferior configurable.

## Responsive y embebido
- Breakpoints: <360 compacto, 360–600 móvil, >600 centrado con ancho máx. 480.
- Sin scroll vertical obligatorio en 360×640; si el viewport es menor, el contenido escala.
- Respeta tema claro; modo oscuro queda fuera del MVP.

## Accesibilidad
- Todo control con `Semantics` y objetivos táctiles ≥ 48 dp.
- Resultado anunciado por lector de pantalla al terminar la animación.
- No depender solo del color (iconos + texto en estados).

## Decisiones de Luis (2026-10-20) — UX spec APROBADA
1. Sonido **y** háptico activados por defecto (el host puede apagarlos con `GamificationConfig.sound/haptics`). Respetar el modo silencio del dispositivo. En web, el audio se inicia tras el primer gesto del usuario (política de autoplay).
2. Confeti y efectos de celebración ligeros: sí.
3. Ruleta: botón "GIRAR" explícito tras validar.
