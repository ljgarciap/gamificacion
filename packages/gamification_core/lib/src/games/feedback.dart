import 'dart:async';

import 'package:flutter/services.dart';

import 'game_module.dart';

/// Háptico y sonido que comparten todos los juegos. Se inyecta para poder simularlo en tests
/// y para cambiar el sonido real (audio con assets) sin tocar los juegos.
abstract interface class GameFeedback {
  /// Un paso discreto (divisoria de la ruleta, trazo del raspa).
  void tick();
  void win();
  void lose();
}

/// Implementación por defecto: háptico del sistema y clic de sistema.
/// Respeta [GamePreferences]. Ojo: `SystemSound` no suena en web; el audio real queda para
/// una implementación con assets (ver CLAUDE.md del proyecto).
class SystemFeedback implements GameFeedback {
  SystemFeedback(this.preferences);

  final GamePreferences preferences;
  final _sinceTick = Stopwatch()..start();

  /// Evita ráfagas de clics cuando la ruleta gira muy rápido.
  static const _minTickGap = Duration(milliseconds: 45);

  @override
  void tick() {
    if (_sinceTick.elapsed < _minTickGap) return;
    _sinceTick.reset();
    if (preferences.haptics) _safe(HapticFeedback.selectionClick);
    if (preferences.sound) _safe(() => SystemSound.play(SystemSoundType.click));
  }

  @override
  void win() {
    if (preferences.haptics) _safe(HapticFeedback.heavyImpact);
    if (preferences.sound) _safe(() => SystemSound.play(SystemSoundType.alert));
  }

  @override
  void lose() {
    if (preferences.haptics) _safe(HapticFeedback.lightImpact);
  }

  /// El feedback es decorativo: una plataforma sin soporte nunca debe romper el juego.
  void _safe(Future<void> Function() action) {
    unawaited(action().catchError((Object _) {}));
  }
}
