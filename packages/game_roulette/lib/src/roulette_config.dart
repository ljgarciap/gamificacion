import 'package:gamification_core/gamification_core.dart';

/// Configuración de la ruleta, leída de `Campaign.gameConfig` y `Outcome.gameResult`.
/// Nada de esto está fijo en el código: lo entrega la campaña (regla de parametrización).
class RouletteConfig {
  const RouletteConfig({
    required this.segments,
    required this.targetIndex,
    this.duration = const Duration(milliseconds: 4600),
    this.fullTurns = 5,
    this.offsetSpread = 0.35,
  });

  static const minSegments = 4;
  static const maxSegments = 12;

  final List<Prize> segments;

  /// Segmento ganador decidido por el servidor (`gameResult.segmentIndex`).
  final int targetIndex;
  final Duration duration;
  final int fullTurns;

  /// Máximo desplazamiento aleatorio dentro del segmento, como fracción de su ancho a cada lado.
  final double offsetSpread;

  /// Lanza [FormatException] si la campaña no trae una ruleta usable (RF-03: 4 a 12 segmentos).
  factory RouletteConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final raw = cfg['segments'];
    if (raw is! List) throw const FormatException('gameConfig.segments ausente');
    final segments = [for (final s in raw) Prize.fromJson((s as Map).cast<String, dynamic>())];
    if (segments.length < minSegments || segments.length > maxSegments) {
      throw FormatException('la ruleta admite $minSegments a $maxSegments segmentos, llegaron ${segments.length}');
    }

    final index = redemption.outcome.gameResult['segmentIndex'];
    if (index is! int || index < 0 || index >= segments.length) {
      throw FormatException('gameResult.segmentIndex inválido: $index');
    }

    final spread = (cfg['offsetSpread'] as num?)?.toDouble() ?? 0.35;
    return RouletteConfig(
      segments: segments,
      targetIndex: index,
      duration: Duration(milliseconds: (cfg['durationMs'] as num?)?.toInt() ?? 4600),
      fullTurns: (cfg['fullTurns'] as num?)?.toInt() ?? 5,
      // < 0.5 para no parar nunca sobre la divisoria entre dos segmentos.
      offsetSpread: spread.clamp(0.0, 0.45),
    );
  }
}
