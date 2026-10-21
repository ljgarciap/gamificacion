import 'package:gamification_core/gamification_core.dart';

enum FlowStage { enterCode, validating, playing, result }

/// Giros extra que una redención concedió y todavía no se jugaron. El cliente solo los cuenta: cada resultado lo decide el servidor.
class PendingSpin {
  const PendingSpin({required this.parentId, required this.remaining, this.played = 0});

  final String parentId;
  final int remaining;

  /// Giros ya jugados de este padre: con [parentId] forma la `Idempotency-Key` de cada giro (estable ante reintentos).
  final int played;

  /// Clave del PRÓXIMO giro de este padre. Reintentar tras un corte de red la reutiliza y el servidor no consume otra casilla.
  String get nextKey => '$parentId:${played + 1}';

  PendingSpin afterPlaying() => PendingSpin(parentId: parentId, remaining: remaining - 1, played: played + 1);
}

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
    this.spinQueue = const [],
    this.spinning = false,
    this.spinFailure,
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

  /// Giros extra por jugar (pila: el último agregado se juega primero). Solo se ofrecen en la pantalla de resultado.
  final List<PendingSpin> spinQueue;

  /// Hay un giro en curso (la petición al servidor).
  final bool spinning;

  /// Último fallo al pedir un giro (se muestra en la pantalla de resultado).
  final FailureReason? spinFailure;

  int get pendingSpins => spinQueue.fold(0, (sum, s) => sum + s.remaining);

  bool get isBlocked => blockedUntil != null && DateTime.now().isBefore(blockedUntil!);

  FlowState copyWith({
    Balance? balance,
    bool? animating,
    bool? spinning,
    FailureReason? spinFailure,
    bool clearSpinFailure = false,
  }) => FlowState(
    stage: stage,
    redemption: redemption,
    failure: failure,
    retryAfter: retryAfter,
    blockedUntil: blockedUntil,
    unsupportedGame: unsupportedGame,
    animating: animating ?? this.animating,
    balance: balance ?? this.balance,
    spinQueue: spinQueue,
    spinning: spinning ?? this.spinning,
    spinFailure: clearSpinFailure ? null : (spinFailure ?? this.spinFailure),
  );
}
