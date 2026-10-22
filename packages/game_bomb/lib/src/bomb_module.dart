import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'bomb_game.dart';

/// Módulo "bomb": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class BombModule implements GameModule {
  BombModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'bomb';

  @override
  Widget build(BuildContext context, GameSession session) =>
      BombGame(session: session, feedbackFactory: feedbackFactory);
}
