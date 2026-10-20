import '../models/money.dart';
import '../models/redemption.dart';

/// Contrato con el backend. Implementaciones: MockRepository (dev/tests), HttpRepository
/// (backend propio) o un adaptador que escribe cada app host.
abstract interface class GamificationRepository {
  /// Redime el código: el servidor valida, marca como usado y decide el resultado en una sola
  /// transacción. Con la misma [idempotencyKey] devuelve siempre la misma redención, de modo que
  /// reintentar tras un corte de red o un cierre de la app no juega dos veces.
  ///
  /// Lanza [GamificationException] ante cualquier fallo esperado.
  Future<Redemption> redeem(String code, {required String idempotencyKey});

  Future<Balance> getBalance();
}
