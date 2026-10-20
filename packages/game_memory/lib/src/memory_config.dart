import 'package:gamification_core/gamification_core.dart';

/// Configuración de Concéntrese, leída de `Campaign.gameConfig` (tamaño, tiempo de volteo) y de
/// `Outcome.gameResult` (la disposición de las cartas que decidió el servidor).
class MemoryConfig {
  const MemoryConfig({required this.pairs, required this.columns, required this.flipBack, required this.cards});

  static const minPairs = 3;
  static const maxPairs = 8;
  static const minColumns = 2;
  static const maxColumns = 6;

  final int pairs;
  final int columns;

  /// Cuánto tiempo se ven dos cartas distintas antes de volver a taparse.
  final Duration flipBack;

  /// Símbolo de cada carta, fila por fila. Cada símbolo aparece exactamente dos veces.
  final List<String> cards;

  int get rows => (cards.length / columns).ceil();

  /// Lanza [FormatException] si la campaña no trae un Concéntrese usable.
  factory MemoryConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final pairs = (cfg['pairs'] as num?)?.toInt() ?? 6;
    final columns = (cfg['columns'] as num?)?.toInt() ?? 4;
    final flipBackMs = ((cfg['flipBackMs'] as num?)?.toInt() ?? 900).clamp(300, 3000);
    if (pairs < minPairs || pairs > maxPairs) {
      throw FormatException('Concéntrese admite de $minPairs a $maxPairs parejas: $pairs');
    }
    if (columns < minColumns || columns > maxColumns) {
      throw FormatException('Concéntrese admite de $minColumns a $maxColumns columnas: $columns');
    }

    final raw = redemption.outcome.gameResult['cards'];
    if (raw is! List || raw.length != pairs * 2 || raw.any((s) => s is! String || s.isEmpty)) {
      throw FormatException('gameResult.cards debe tener ${pairs * 2} símbolos');
    }
    final cards = raw.cast<String>();
    final counts = <String, int>{};
    for (final c in cards) {
      counts[c] = (counts[c] ?? 0) + 1;
    }
    if (counts.values.any((n) => n != 2)) {
      throw const FormatException('cada símbolo debe aparecer exactamente dos veces');
    }

    return MemoryConfig(
      pairs: pairs,
      columns: columns,
      flipBack: Duration(milliseconds: flipBackMs),
      cards: cards,
    );
  }

  /// Posiciones de la pareja del premio (vacío si no hay premio).
  List<int> prizeCards(Prize prize) => !prize.isWin
      ? const []
      : [
          for (var i = 0; i < cards.length; i++)
            if (cards[i] == prize.id) i,
        ];
}
