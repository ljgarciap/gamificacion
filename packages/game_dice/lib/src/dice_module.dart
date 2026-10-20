import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'dice_game.dart';

/// Módulo "dice": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class DiceModule implements GameModule {
  DiceModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'dice';

  @override
  Widget build(BuildContext context, GameSession session) =>
      DiceGame(session: session, feedbackFactory: feedbackFactory);
}
