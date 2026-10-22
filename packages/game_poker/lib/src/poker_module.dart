import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'poker_game.dart';

/// Módulo "poker": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class PokerModule implements GameModule {
  PokerModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'poker';

  @override
  Widget build(BuildContext context, GameSession session) =>
      PokerGame(session: session, feedbackFactory: feedbackFactory);
}
