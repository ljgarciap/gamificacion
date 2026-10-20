import 'dart:math';

import 'package:flutter/widgets.dart';
import 'package:gamification_core/gamification_core.dart';

import 'roulette_game.dart';

/// Módulo "roulette": se registra en el [GameRegistry] y la campaña lo elige con `gameType`.
class RouletteModule implements GameModule {
  RouletteModule({this.feedbackFactory = SystemFeedback.new, this.random});

  final GameFeedback Function(GamePreferences) feedbackFactory;
  final Random? random;

  @override
  String get id => 'roulette';

  @override
  Widget build(BuildContext context, GameSession session) =>
      RouletteGame(session: session, feedbackFactory: feedbackFactory, random: random);
}
