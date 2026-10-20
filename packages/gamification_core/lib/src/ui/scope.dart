import 'package:flutter/material.dart';

import '../games/game_module.dart';
import '../models/theme.dart';
import 'strings.dart';

/// Entrega tema, textos y preferencias a todo el módulo (flujo común y juegos).
class GamificationScope extends InheritedWidget {
  const GamificationScope({
    super.key,
    required this.theme,
    required this.strings,
    required this.preferences,
    required super.child,
  });

  final GamificationTheme theme;
  final GamificationStrings strings;
  final GamePreferences preferences;

  static GamificationScope of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<GamificationScope>();
    assert(scope != null, 'No hay un GamificationScope sobre este contexto');
    return scope!;
  }

  /// "Reducir movimiento" del sistema o forzado por el host (RNF-05).
  static bool reduceMotion(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) == true || of(context).preferences.reduceMotion;

  @override
  bool updateShouldNotify(GamificationScope old) =>
      theme != old.theme || strings != old.strings || preferences != old.preferences;
}

/// Convierte los tokens en un [ThemeData] de Material para los widgets estándar.
extension GamificationThemeData on GamificationTheme {
  ThemeData toThemeData() {
    final scheme = ColorScheme.light(
      primary: primary,
      onPrimary: Colors.white,
      secondary: accent,
      onSecondary: navy,
      surface: surface,
      onSurface: navy,
      error: error,
    );
    return ThemeData(
      useMaterial3: true,
      colorScheme: scheme,
      scaffoldBackgroundColor: background,
      fontFamily: fontFamily,
      cardTheme: CardThemeData(
        color: surface,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          foregroundColor: navy,
          side: BorderSide(color: navy.withValues(alpha: 0.3)),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      ),
    );
  }
}
