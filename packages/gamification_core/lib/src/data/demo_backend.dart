/// Contrato de la muestra real (SPEC-006): sesión de un usuario simulado y compra en línea simulada. Solo lo usa el recorrido de la muestra;
/// el flujo de canje de códigos sigue siendo `GamificationRepository`.
library;

/// Producto del catálogo de la muestra (lo configura el panel).
class DemoProduct {
  const DemoProduct({required this.id, required this.name, required this.price, this.participates = true});
  final String id;
  final String name;

  /// Pesos colombianos, sin decimales.
  final int price;

  /// Solo en el modo por planes (SPEC-007): si el producto cuenta para la ruleta. Los que no participan se compran pero no dan turno.
  final bool participates;
}

/// Reglas de carga de la muestra. El servidor las aplica; el cliente las usa solo para mostrar una vista previa.
class DemoRules {
  const DemoRules({
    required this.balancePercent,
    required this.minPurchase,
    required this.codeStepAmount,
    required this.maxCodesPerPurchase,
    required this.maxCodesPerSession,
  });
  final int balancePercent;
  final int minPurchase;
  final int codeStepAmount;
  final int maxCodesPerPurchase;
  final int maxCodesPerSession;

  /// Lo que recibiría quien compra [total] pesos (misma aritmética entera que el servidor). Es una vista previa: manda la respuesta de la compra.
  DemoPreview preview(int total) {
    if (total < minPurchase || total < 1) return const DemoPreview(allowed: false, balance: 0, codes: 0);
    final codes = total ~/ codeStepAmount;
    return DemoPreview(
      allowed: true,
      balance: total * balancePercent ~/ 100,
      codes: codes < maxCodesPerPurchase ? codes : maxCodesPerPurchase,
    );
  }
}

class DemoPreview {
  const DemoPreview({required this.allowed, required this.balance, required this.codes});
  final bool allowed;
  final int balance;

  /// Códigos que daría la compra. En el modo por planes solo importa si hay turno (> 0): cuántos y de qué plan lo decide el servidor.
  final int codes;
}

/// Por qué la cuenta recibió un turno al ingresar (SPEC-007).
enum DemoGrantKind { welcome, reactivation }

/// Turno otorgado por el ingreso (bienvenida o regreso tras días sin comprar). [plan] es el nombre visible del plan.
class DemoGrant {
  const DemoGrant({required this.plan, required this.kind});
  final String plan;
  final DemoGrantKind kind;
}

/// Sesión de la cuenta de la muestra (SPEC-006A): la persona entró con su correo y un código de un solo uso.
class DemoSession {
  const DemoSession({
    required this.displayName,
    required this.balance,
    required this.products,
    required this.rules,
    required this.resumed,
    this.isNew = false,
    this.byPlans = false,
    this.grant,
  });
  final String displayName;
  final int balance;
  final List<DemoProduct> products;
  final DemoRules rules;
  final bool resumed;

  /// La cuenta se creó con este ingreso (la primera vez de ese correo).
  final bool isNew;

  /// La muestra reparte los turnos por planes (SPEC-007): el plan sale del monto de la compra y no de un tramo fijo.
  final bool byPlans;

  /// Turno que este ingreso otorgó, si lo hubo.
  final DemoGrant? grant;

  /// Lo que recibiría quien compra [total] pesos, de los cuales [eligible] pertenecen a productos que participan. Vista previa: manda el servidor.
  DemoPreview preview(int total, int eligible) {
    if (!byPlans) return rules.preview(total);
    if (total < 1) return const DemoPreview(allowed: false, balance: 0, codes: 0);
    return DemoPreview(
      allowed: true,
      balance: total * rules.balancePercent ~/ 100,
      codes: eligible >= rules.minPurchase && eligible > 0 ? 1 : 0,
    );
  }
}

/// Respuesta a «pídeme un código»: el servidor SIEMPRE dice que lo envió (no revela si el correo ya tenía cuenta); [retryAfter] es la espera
/// antes de poder pedir otro.
class DemoCodeRequest {
  const DemoCodeRequest({required this.retryAfter});
  final Duration retryAfter;
}

/// Código de la compra de una cuenta, con su estado.
class DemoOwnedCode {
  const DemoOwnedCode({required this.code, required this.game, required this.played, this.plan});
  final String code;
  final String game;

  /// Nombre visible del plan que otorgó el turno (solo en el modo por planes).
  final String? plan;

  /// Ya se canjeó (el resultado ya se vio).
  final bool played;
}

/// Lo que la cuenta conserva entre visitas: saldo acumulado y los códigos que compró.
class DemoAccount {
  const DemoAccount({required this.balance, required this.codes});
  final int balance;
  final List<DemoOwnedCode> codes;
}

/// Código entregado por una compra. El resultado ya está decidido en el servidor; el cliente solo lo canjea.
class DemoDeliveredCode {
  const DemoDeliveredCode({required this.code, required this.game, this.plan});
  final String code;

  /// Nombre visible del plan del turno (solo en el modo por planes).
  final String? plan;

  /// Tipo de juego (`roulette`, `scratch`, …).
  final String game;
}

class DemoOrder {
  const DemoOrder({
    required this.orderId,
    required this.total,
    required this.credited,
    required this.codes,
    required this.capReached,
    required this.balance,
    required this.replayed,
  });
  final String orderId;
  final int total;
  final int credited;
  final List<DemoDeliveredCode> codes;

  /// Se llegó al tope de códigos por sesión: se entregaron menos de los que correspondían.
  final bool capReached;
  final int balance;
  final bool replayed;
}

class DemoLine {
  const DemoLine(this.productId, this.quantity);
  final String productId;
  final int quantity;
}

enum DemoFailure {
  /// La muestra está apagada (o caducó).
  off,

  /// La sesión no existe, venció y no pudo renovarse, o la cuenta se borró.
  sessionInvalid,

  /// El correo escrito no es válido.
  invalidEmail,

  /// El código es erróneo, venció, ya se usó o se agotaron los intentos (el servidor no distingue la causa a propósito).
  invalidCode,

  /// El servidor no pudo enviar el correo: nada se gastó, se puede reintentar.
  mailUnavailable,

  /// La muestra llegó a su tope de cuentas.
  accountsFull,

  /// El pool de códigos se agotó.
  poolExhausted,

  /// La compra no cumple las reglas (mínimo, producto, cantidades). [DemoException.message] trae el motivo.
  invalidPurchase,
  rateLimited,
  network,
  unknown,
}

class DemoException implements Exception {
  const DemoException(this.reason, {this.message});
  final DemoFailure reason;

  /// Texto del servidor (solo para `invalidPurchase`; el resto se traduce en la interfaz).
  final String? message;

  @override
  String toString() => 'DemoException($reason)';
}

abstract interface class DemoBackend {
  /// ¿Está encendida la muestra? Un fallo de red cuenta como apagada.
  Future<bool> status();

  /// Pide que se envíe un código de ingreso de un solo uso a [email]. Responde igual exista o no la cuenta.
  Future<DemoCodeRequest> requestCode(String email);

  /// Canjea el código de 6 dígitos: entra a la cuenta de ese correo (se crea si es nueva) con su saldo acumulado.
  Future<DemoSession> verifyCode(String email, String code);

  /// Vuelve a pedir el catálogo y las reglas VIGENTES de la cuenta ya ingresada (el superadmin puede cambiarlos mientras la persona está
  /// dentro). No otorga turnos ni cambia el saldo. Falla con `sessionInvalid` si la cuenta ya no existe.
  Future<DemoSession> refresh();

  /// Saldo y códigos de la cuenta ya ingresada (para mostrarlos al volver a entrar).
  Future<DemoAccount> account();

  /// Olvida la sesión de este dispositivo (la cuenta y su saldo siguen en el servidor).
  void signOut();

  /// Compra en línea simulada. Con la misma [idempotencyKey] devuelve siempre la misma orden (reintentar tras un corte no cobra dos veces).
  Future<DemoOrder> purchase(List<DemoLine> lines, {required String idempotencyKey});
}
