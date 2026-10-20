import 'package:gamification_core/gamification_core.dart';

enum FlowStage { enterCode, validating, playing, result }

/// Estado inmutable del flujo común. Cada transición construye un estado nuevo, así no quedan
/// restos (errores, bloqueos) de una etapa anterior.
class FlowState {
  const FlowState({
    this.stage = FlowStage.enterCode,
    this.redemption,
    this.failure,
    this.retryAfter,
    this.blockedUntil,
    this.unsupportedGame = false,
    this.animating = false,
    this.balance,
  });

  final FlowStage stage;
  final Redemption? redemption;

  /// Último error al validar el código (solo en [FlowStage.enterCode]).
  final FailureReason? failure;
  final Duration? retryAfter;

  /// Mientras sea futuro, el botón de validar queda bloqueado (rate limit del servidor).
  final DateTime? blockedUntil;

  /// El código se redimió pero el host no registró el juego de la campaña.
  final bool unsupportedGame;

  /// El usuario ya inició la animación: no se puede cerrar hasta que termine.
  final bool animating;

  /// Saldo visible en la cabecera; null si aún no cargó (o el host no lo usa).
  final Balance? balance;

  bool get isBlocked => blockedUntil != null && DateTime.now().isBefore(blockedUntil!);

  FlowState copyWith({Balance? balance, bool? animating}) => FlowState(
        stage: stage,
        redemption: redemption,
        failure: failure,
        retryAfter: retryAfter,
        blockedUntil: blockedUntil,
        unsupportedGame: unsupportedGame,
        animating: animating ?? this.animating,
        balance: balance ?? this.balance,
      );
}
