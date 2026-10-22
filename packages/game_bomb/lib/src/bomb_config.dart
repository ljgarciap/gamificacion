import 'package:gamification_core/gamification_core.dart';

/// Configuración de la Bomba 8, leída de `Campaign.gameConfig` (tamaño) y de `Outcome.gameResult` (las caras EN ORDEN DE VOLTEO que decidió el
/// servidor: la n-ésima carta que se voltea muestra `faces[n]`, sea cual sea su posición).
class BombConfig {
  const BombConfig({required this.cards, required this.columns, required this.faces});

  static const minCards = 9;
  static const maxCards = 16;
  static const minColumns = 2;
  static const maxColumns = 5;

  /// La cara que cuenta para desactivar la bomba.
  static const eight = '8';

  /// Cuántos 8 desactivan la bomba.
  static const needed = 3;

  final int cards;
  final int columns;
  final List<String> faces;

  int get rows => (cards / columns).ceil();

  /// Lanza [FormatException] si la campaña no trae una Bomba usable o si lo que mandó el servidor contradice el premio.
  factory BombConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final cards = (cfg['cards'] as num?)?.toInt() ?? 12;
    final columns = (cfg['columns'] as num?)?.toInt() ?? 4;
    if (cards < minCards || cards > maxCards) {
      throw FormatException('La Bomba admite de $minCards a $maxCards cartas: $cards');
    }
    if (columns < minColumns || columns > maxColumns) {
      throw FormatException('La Bomba admite de $minColumns a $maxColumns columnas: $columns');
    }
    final raw = redemption.outcome.gameResult['faces'];
    if (raw is! List || raw.length != cards || raw.any((f) => f is! String || f.isEmpty)) {
      throw FormatException('gameResult.faces debe tener $cards caras');
    }
    final faces = raw.cast<String>();
    final eights = faces.where((f) => f == eight).length;
    final wins = redemption.outcome.prize.isWin;
    if (wins != (eights == needed) || eights > needed) {
      throw const FormatException('las caras no concuerdan con el premio (tres 8 si y solo si hay premio)');
    }
    return BombConfig(cards: cards, columns: columns, faces: faces);
  }
}
