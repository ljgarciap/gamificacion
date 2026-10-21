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

  /// Juega UN giro de los que concedió una redención con premio «giro extra» (`outcome.extraSpins`). El servidor decide el resultado y
  /// devuelve una redención hija (con [Redemption.parentRedemptionId]), que puede conceder más giros.
  ///
  /// La [idempotencyKey] debe ser ESTABLE por giro (p. ej. `<padre>:<n>`): reintentar con la misma clave devuelve el mismo resultado sin
  /// consumir otra casilla; una clave nueva es un giro nuevo. Los giros pendientes caducan cuando el lote se cierra.
  ///
  /// Lanza [GamificationException] ([FailureReason.noSpinsLeft], [FailureReason.spinReserveExhausted], [FailureReason.expired]…).
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey});

  Future<Balance> getBalance();
}
