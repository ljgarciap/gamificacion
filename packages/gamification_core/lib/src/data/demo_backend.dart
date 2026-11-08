/// Contrato de la muestra real (SPEC-006): sesión de un usuario simulado y compra en línea simulada. Solo lo usa el recorrido de la muestra;
/// el flujo de canje de códigos sigue siendo `GamificationRepository`.
library;

/// Producto del catálogo de la muestra (lo configura el panel).
class DemoProduct {
  const DemoProduct({required this.id, required this.name, required this.price});
  final String id;
  final String name;

  /// Pesos colombianos, sin decimales.
  final int price;
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
  final int codes;
}

/// Sesión del usuario simulado.
class DemoSession {
  const DemoSession({
    required this.displayName,
    required this.balance,
    required this.products,
    required this.rules,
    required this.resumed,
  });
  final String displayName;
  final int balance;
  final List<DemoProduct> products;
  final DemoRules rules;
  final bool resumed;
}

/// Código entregado por una compra. El resultado ya está decidido en el servidor; el cliente solo lo canjea.
class DemoDeliveredCode {
  const DemoDeliveredCode({required this.code, required this.game});
  final String code;

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

  /// La sesión no existe o venció y no pudo renovarse.
  sessionInvalid,

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

  /// Abre la sesión del usuario simulado; en llamadas siguientes la reanuda (mismo usuario y saldo).
  Future<DemoSession> openSession();

  /// Compra en línea simulada. Con la misma [idempotencyKey] devuelve siempre la misma orden (reintentar tras un corte no cobra dos veces).
  Future<DemoOrder> purchase(List<DemoLine> lines, {required String idempotencyKey});
}
