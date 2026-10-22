import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'bingo_game.dart';

/// Módulo "bingo": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class BingoModule implements GameModule {
  BingoModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'bingo';

  @override
  Widget build(BuildContext context, GameSession session) =>
      BingoGame(session: session, feedbackFactory: feedbackFactory);
}
