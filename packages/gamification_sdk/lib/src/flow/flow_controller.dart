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

    state = FlowState(stage: FlowStage.result, redemption: r, balance: state.balance);
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

  /// Vuelve a la pantalla de código.
  void reset() => state = FlowState(balance: state.balance);
}
