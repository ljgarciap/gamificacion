import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'scratch_game.dart';

/// Módulo "scratch": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class ScratchModule implements GameModule {
  ScratchModule({this.feedbackFactory = SystemFeedback.new});

  final GameFeedback Function(GamePreferences) feedbackFactory;

  @override
  String get id => 'scratch';

  @override
  Widget build(BuildContext context, GameSession session) =>
      ScratchGame(session: session, feedbackFactory: feedbackFactory);
}
