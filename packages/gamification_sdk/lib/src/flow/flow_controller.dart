import 'dart:math';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gamification_core/gamification_core.dart';

import 'flow_state.dart';

/// Los providers se sobrescriben en [GamificationFlow]; leerlos sin override es un error de uso.
final repositoryProvider = Provider<GamificationRepository>((ref) => throw UnimplementedError());
final registryProvider = Provider<GameRegistry>((ref) => throw UnimplementedError());
final eventsProvider = Provider<GamificationEvents>((ref) => throw UnimplementedError());

/// Generador de Idempotency-Key. Inyectable para tests.
final idempotencyKeyProvider = Provider<String Function()>((ref) => _randomKey);

final flowControllerProvider = NotifierProvider<FlowController, FlowState>(FlowController.new);

/// Mayúsculas y sin espacios: el código se compara así en cliente y servidor.
String normalizeCode(String raw) => raw.replaceAll(RegExp(r'\s+'), '').toUpperCase();

String _randomKey() {
  final rnd = Random.secure();
  final bytes = List<int>.generate(16, (_) => rnd.nextInt(256));
  return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
}

class FlowController extends Notifier<FlowState> {
  // Si la red falla a mitad de la redención, el reintento del MISMO código reutiliza la clave:
  // el servidor devuelve la redención ya hecha en vez de rechazarla como "código usado".
  String? _pendingKey;
  String? _pendingCode;

  @override
  FlowState build() => const FlowState();

  GamificationRepository get _repo => ref.read(repositoryProvider);
  GamificationEvents get _events => ref.read(eventsProvider);

  Future<void> loadBalance() async {
    try {
      state = state.copyWith(balance: await _repo.getBalance());
    } catch (_) {
      // El saldo es informativo: si falla, la cabecera simplemente no lo muestra.
    }
  }

  Future<void> submit(String rawCode) async {
    if (state.stage == FlowStage.validating) return;
    final code = normalizeCode(rawCode);
    if (code.isEmpty || state.isBlocked) return;

    final balance = state.balance;
    state = FlowState(stage: FlowStage.validating, balance: balance);

    if (_pendingCode != code || _pendingKey == null) {
      _pendingCode = code;
      _pendingKey = ref.read(idempotencyKeyProvider)();
    }

    try {
      final redemption = await _repo.redeem(code, idempotencyKey: _pendingKey!);
      _pendingKey = null;
      _pendingCode = null;
      _events.emit(CodeValidatedEvent(redemptionId: redemption.redemptionId, gameType: redemption.gameType));

      if (!ref.read(registryProvider).has(redemption.gameType)) {
        _events.emit(const ErrorEvent(FailureReason.unknown));
        state = FlowState(unsupportedGame: true, balance: balance);
        return;
      }
      // Código ya jugado con showPrevious: se muestra el resultado, sin volver a jugar.
      state = FlowState(
        stage: redemption.alreadyPlayed ? FlowStage.result : FlowStage.playing,
        redemption: redemption,
        balance: balance,
        // Un código ya jugado (showPrevious) no concede giros nuevos: solo los que el servidor dice que aún quedan por jugar.
        spinQueue: redemption.alreadyPlayed
            ? [
                for (final s in redemption.pendingSpins)
                  PendingSpin(parentId: s.redemptionId, remaining: s.remaining, played: s.played),
              ]
            : _entitlement(const [], redemption),
      );
    } on GamificationException catch (e) {
      if (e.reason != FailureReason.network) {
        _pendingKey = null;
        _pendingCode = null;
      }
      _fail(e.reason, e.retryAfter, balance);
    } catch (_) {
      _fail(FailureReason.unknown, null, balance);
    }
  }

  void _fail(FailureReason reason, Duration? retryAfter, Balance? balance) {
    _events.emit(ErrorEvent(reason));
    state = FlowState(
      failure: reason,
      retryAfter: retryAfter,
      blockedUntil: retryAfter == null ? null : DateTime.now().add(retryAfter),
      balance: balance,
    );
  }

  /// El juego informa que el usuario inició la animación.
  void gameStarted() {
    final r = state.redemption;
    if (state.stage != FlowStage.playing || state.animating || r == null) return;
    state = state.copyWith(animating: true);
    _events.emit(GameStartedEvent(redemptionId: r.redemptionId, gameType: r.gameType));
  }

  /// El juego informa que el resultado ya es visible.
  Future<void> gameFinished() async {
    final r = state.redemption;
    if (state.stage != FlowStage.playing || r == null) return;
    final prize = r.outcome.prize;

    state = FlowState(stage: FlowStage.result, redemption: r, balance: state.balance, spinQueue: state.spinQueue);
    if (prize.isWin) _events.emit(PrizeWonEvent(redemptionId: r.redemptionId, prize: prize));

    // El saldo se refresca recién ahora (no al validar) para no adelantar el resultado en pantalla.
    if (prize.type == PrizeType.money) {
      try {
        final balance = await _repo.getBalance();
        state = state.copyWith(balance: balance);
        _events.emit(BalanceChangedEvent(balance));
      } catch (_) {
        // Sin saldo nuevo: el premio ya está acreditado en el servidor.
      }
    }
  }

  /// Agrega a la pila los giros que concede [r] (si concede alguno).
  static List<PendingSpin> _entitlement(List<PendingSpin> queue, Redemption r) {
    final granted = r.outcome.extraSpins;
    return granted > 0 ? [...queue, PendingSpin(parentId: r.redemptionId, remaining: granted)] : queue;
  }

  /// Juega el siguiente giro extra: el servidor decide el resultado (la animación usa lo que devuelva). Solo desde la pantalla de resultado.
  Future<void> spinNext() async {
    if (state.stage != FlowStage.result || state.spinning || state.spinQueue.isEmpty) return;
    final queue = state.spinQueue;
    final top = queue.last;
    final before = state;
    state = state.copyWith(spinning: true, clearSpinFailure: true);

    try {
      final child = await _repo.spin(top.parentId, idempotencyKey: top.nextKey);
      final rest = [...queue.sublist(0, queue.length - 1), if (top.remaining > 1) top.afterPlaying()];
      _events.emit(CodeValidatedEvent(redemptionId: child.redemptionId, gameType: child.gameType));
      state = FlowState(
        stage: FlowStage.playing,
        redemption: child,
        balance: before.balance,
        spinQueue: _entitlement(rest, child),
      );
    } on GamificationException catch (e) {
      // Sin red: se queda en el resultado y el reintento reutiliza la misma clave. Con noSpinsLeft ese padre ya no tiene giros
      // (o caducaron al cerrar el lote); con cualquier otro fallo se conserva la pila para poder reintentar.
      final drop =
          e.reason == FailureReason.noSpinsLeft ||
          e.reason == FailureReason.expired ||
          e.reason == FailureReason.spinReserveExhausted;
      _events.emit(ErrorEvent(e.reason));
      state = FlowState(
        stage: FlowStage.result,
        redemption: before.redemption,
        balance: before.balance,
        spinQueue: drop ? const [] : queue,
        spinFailure: e.reason,
      );
    } catch (_) {
      _events.emit(const ErrorEvent(FailureReason.unknown));
      state = FlowState(
        stage: FlowStage.result,
        redemption: before.redemption,
        balance: before.balance,
        spinQueue: queue,
        spinFailure: FailureReason.unknown,
      );
    }
  }

  /// Vuelve a la pantalla de código.
  void reset() => state = FlowState(balance: state.balance);
}
