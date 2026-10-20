import 'theme.dart';

/// Qué hacer cuando alguien reutiliza un código ya jugado (configurable por campaña).
enum UsedCodeBehavior {
  showPrevious,
  rejectOnly;

  static UsedCodeBehavior parse(String? raw) => raw == 'showPrevious' ? showPrevious : rejectOnly;
}

class Campaign {
  const Campaign({
    required this.id,
    required this.name,
    required this.gameType,
    this.theme = const GamificationTheme(),
    this.usedCodeBehavior = UsedCodeBehavior.rejectOnly,
    this.title,
    this.helpText,
    this.gameConfig = const {},
  });

  factory Campaign.fromJson(Map<String, dynamic> json) => Campaign(
    id: json['id'] as String,
    name: json['name'] as String,
    gameType: json['gameType'] as String,
    theme: GamificationTheme.fromJson((json['theme'] as Map?)?.cast<String, dynamic>() ?? const {}),
    usedCodeBehavior: UsedCodeBehavior.parse(json['usedCodeBehavior'] as String?),
    title: json['title'] as String?,
    helpText: json['helpText'] as String?,
    gameConfig: (json['gameConfig'] as Map?)?.cast<String, dynamic>() ?? const {},
  );

  final String id;
  final String name;

  /// Id del [GameModule] que juega esta campaña ('roulette', 'scratch', ...).
  final String gameType;
  final GamificationTheme theme;
  final UsedCodeBehavior usedCodeBehavior;
  final String? title;

  /// Texto del enlace "¿Dónde encuentro mi código?".
  final String? helpText;

  /// Configuración propia del juego (segmentos, grilla, umbral...). Núcleo no la interpreta.
  final Map<String, dynamic> gameConfig;
}
