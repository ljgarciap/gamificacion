# SPEC-006A — Cuentas persistentes de la muestra (ingreso con correo y código)

Estado: **BORRADOR** (pendiente de aprobación de Luis). Rol: Analista. Fecha: 2026-11-08. Amplía `spec-006-demo-desde-el-panel.md`.

## 1. Objetivo
Hoy el usuario de la muestra es efímero: vive en la memoria de la app y recargar la página abre uno nuevo. Pero la plataforma ya tiene base de datos, así que la persona debe poder **volver a ingresar y ver su saldo acumulado** (y sus códigos pendientes). El usuario deja de ser «simulado» en lo que importa: tiene cuenta, identidad verificada y saldo que perdura. Lo único simulado siguen siendo el pago y el dinero.

## 2. Decisiones de Luis (2026-11-08)
- **D25 Identidad:** correo electrónico con **código de un solo uso** (sin contraseña). Solo correo por ahora; el celular queda fuera (el SMS tiene costo y proveedor; se abrirá aparte si hace falta).
- **D26 Entrega del correo:** por **SMTP**. Mientras no estemos en el servidor real, se puede elegir un modo **simulado contra Mailpit** (bandeja local de pruebas), sin enviar nada a Internet. El modo se elige por configuración del servidor (infraestructura), no desde la app.
- **D27 «Reiniciar»:** el superadmin **elige en cada reinicio** si conserva o borra las cuentas y sus saldos.
- **D28 Retención:** parámetro del panel «días sin ingresar» (por defecto **30**); pasado ese tiempo la cuenta se borra sola.

## 3. Alcance
### 3.1 Ingreso y registro (un solo flujo)
1. La persona escribe su correo → «Enviarme un código». La respuesta es **siempre la misma** exista o no la cuenta (no revela quién está registrado).
2. Recibe un código de 6 dígitos, válido unos minutos y de un solo uso. Lo escribe.
3. Si el correo ya tenía cuenta, entra a ella (mismo saldo y códigos). Si no, se **crea la cuenta** con el saldo inicial del escenario y entra.
4. «Salir» en la app descarta la sesión del dispositivo (la cuenta y el saldo siguen en el servidor).
- La compra y el juego no cambian; se hacen con la cuenta. Ya no existe el acceso anónimo: sin cuenta no hay sesión.

### 3.2 Cuenta persistente
- Al volver a ingresar la persona ve su **saldo acumulado** y sus **códigos** (los que aún no ha jugado y los ya jugados), en «Tus códigos».
- Datos de la cuenta: correo (cifrado; además una huella para buscarlo), fechas de creación y último ingreso. Nada más de la persona.

### 3.3 Panel («Muestra»)
- Bloque **Cuentas:** total, activas en los últimos 7 días, y lista paginada con el correo **enmascarado** (`a***@dominio.com`), último ingreso y saldo; botón «Borrar cuenta».
- Parámetro **Retención** (días sin ingresar) y tope de **cuentas** (techo duro en código).
- **Reiniciar** pregunta: «Conservar cuentas y saldos» o «Borrar todas las cuentas». Queda en la bitácora con quién y qué se eligió.
- **Entrega de correos:** el panel muestra el modo en uso (SMTP o Mailpit) y un botón «Enviar correo de prueba» a una dirección que escribe el superadmin.

### 3.4 Privacidad (relacionado con D5)
La persona entrega un dato personal (su correo). Se guarda cifrado, se usa solo para el código de ingreso, se borra con la cuenta (a mano o por retención) y nunca aparece completo en el panel ni en registros. La app muestra un aviso breve antes de pedirlo (qué se guarda, para qué y cuántos días). **Redacción final del aviso: de Luis.**

## 4. Fuera de alcance
Celular/SMS, contraseñas, ingreso con redes sociales, recuperación de cuenta distinta del propio código, correos de mercadeo, múltiples cuentas por persona.

## 5. Seguridad (revisar con Cybersecurity antes de implementar)
Ver `docs/diseno-spec-006a.md` §3 (T-D11…T-D20): enumeración de cuentas, fuerza bruta del código, bombardeo de correos a terceros, reutilización y carreras del código, datos personales y registros.

## 6. Criterios de aceptación
- CA-11 Pedir un código responde igual para un correo registrado y uno nuevo (cuerpo, estado y forma), y no envía más de lo permitido (límites por correo y por IP).
- CA-12 Un código sirve una sola vez, vence, y se anula tras 5 intentos fallidos; una carrera de dos verificaciones simultáneas deja una sola ganadora.
- CA-13 Registrarse crea una cuenta con el saldo inicial; volver a ingresar con el mismo correo entra a la **misma** cuenta (mismo saldo y códigos), también tras recargar la página y desde otro navegador.
- CA-14 El saldo acumulado y los códigos pendientes se ven al volver; un código ya jugado figura como jugado y no se puede volver a jugar.
- CA-15 Sin sesión de cuenta no hay compra ni juego (no hay acceso anónimo).
- CA-16 Reiniciar con «conservar» deja cuentas y saldos intactos (los códigos viejos quedan retirados); con «borrar» no queda ninguna cuenta ni forma de llegar a sus saldos.
- CA-17 La retención borra solas las cuentas sin ingreso en N días (N del panel, techo duro) y lo deja en la bitácora.
- CA-18 El correo nunca aparece completo en el panel, la bitácora ni los registros; en la base está cifrado.
- CA-19 Con `Mailpit` el recorrido completo funciona sin enviar nada a Internet; con SMTP el correo sale por el servidor configurado.
- CA-20 E2E con Playwright: registrarse (leyendo el código de Mailpit), comprar, jugar, salir, **volver a ingresar y ver el saldo y los códigos**, y reiniciar eligiendo.

## 7. Decisiones abiertas
- Redacción del aviso de privacidad (Luis). Hasta entonces se usa un texto provisional.
- ¿Se agrega después el celular? (no en este alcance).
