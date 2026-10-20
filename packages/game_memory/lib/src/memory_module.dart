import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'memory_game.dart';

/// Módulo "memory": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class MemoryModule implements GameModule {
  MemoryModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'memory';

  @override
  Widget build(BuildContext context, GameSession session) =>
      MemoryGame(session: session, feedbackFactory: feedbackFactory);
}
