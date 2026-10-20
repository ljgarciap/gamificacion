import 'dart:async';

import 'models/failure.dart';
import 'models/money.dart';
import 'models/prize.dart';

/// Eventos que el módulo emite hacia la app host (RF-10).
sealed class GamificationEvent {
  const GamificationEvent();
}

class ReadyEvent extends GamificationEvent {
  const ReadyEvent();
}

class CodeValidatedEvent extends GamificationEvent {
  const CodeValidatedEvent({required this.redemptionId, required this.gameType});
  final String redemptionId;
  final String gameType;
}

class GameStartedEvent extends GamificationEvent {
  const GameStartedEvent({required this.redemptionId, required this.gameType});
  final String redemptionId;
  final String gameType;
}

class PrizeWonEvent extends GamificationEvent {
  const PrizeWonEvent({required this.redemptionId, required this.prize});
  final String redemptionId;
  final Prize prize;
}

class BalanceChangedEvent extends GamificationEvent {
  const BalanceChangedEvent(this.balance);
  final Balance balance;
}

class ClosedEvent extends GamificationEvent {
  const ClosedEvent();
}

class ErrorEvent extends GamificationEvent {
  const ErrorEvent(this.reason);
  final FailureReason reason;
}

/// Bus de eventos. Los puentes del host (callbacks, postMessage, MethodChannel) se suscriben aquí.
class GamificationEvents {
  final _controller = StreamController<GamificationEvent>.broadcast();

  Stream<GamificationEvent> get stream => _controller.stream;

  void emit(GamificationEvent event) {
    if (!_controller.isClosed) _controller.add(event);
  }

  Future<void> dispose() => _controller.close();
}
