import 'package:flutter/widgets.dart';

import '../models/redemption.dart';

/// Preferencias de presentación que decide el host.
class GamePreferences {
  const GamePreferences({
    this.sound = true,
    this.haptics = true,
    this.reduceMotion = false,
  });

  final bool sound;
  final bool haptics;
  final bool reduceMotion;
}

/// Lo que un juego recibe para representar una redención ya resuelta.
class GameSession {
  const GameSession({
    required this.redemption,
    required this.preferences,
    required this.onStarted,
    required this.onFinished,
  });

  final Redemption redemption;
  final GamePreferences preferences;

  /// El usuario inició la animación (giro, primer raspado...).
  final VoidCallback onStarted;

  /// La animación terminó y el resultado ya es visible; el flujo común pasa a la pantalla de resultado.
  final VoidCallback onFinished;
}

/// Un juego enchufable. Agregar uno nuevo = implementar esto y registrarlo; el núcleo no cambia.
abstract interface class GameModule {
  /// Coincide con `Campaign.gameType`.
  String get id;

  Widget build(BuildContext context, GameSession session);
}
