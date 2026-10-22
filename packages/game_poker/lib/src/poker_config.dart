import 'package:gamification_core/gamification_core.dart';

/// Una mano que paga y su premio (la lista va de la mejor a la peor).
class PokerLegendEntry {
  const PokerLegendEntry({required this.hand, required this.prize});

  final String hand;
  final Prize prize;
}

/// Configuración del Póker, leída de `Campaign.gameConfig` (ritmo, tabla de premios) y de `Outcome.gameResult` (las 7 cartas y lo que se va
/// viendo, decididos por el servidor). Nada se decide ni se evalúa aquí.
class PokerConfig {
  const PokerConfig({
    required this.interval,
    required this.hole,
    required this.board,
    required this.stages,
    required this.category,
    required this.hand,
    required this.best,
    required this.legend,
  });

  static const categories = [
    'high_card',
    'pair',
    'two_pair',
    'three_of_a_kind',
    'straight',
    'flush',
    'full_house',
    'four_of_a_kind',
    'straight_flush',
  ];

  static final _card = RegExp(r'^[2-9TJQKA][SHDC]$');

  final Duration interval;

  /// Las 2 cartas del jugador.
  final List<String> hole;

  /// Las 5 cartas de la mesa, en el orden en que el crupier las abre (3, 1 y 1).
  final List<String> board;

  /// La mejor mano tras el flop, el turn y el river.
  final List<String> stages;
  final String category;

  /// La categoría si paga (null si no).
  final String? hand;

  /// Las 5 cartas de la mejor mano, para resaltarlas.
  final List<String> best;
  final List<PokerLegendEntry> legend;

  /// Lanza [FormatException] si no es un Póker usable o si lo que mandó el servidor contradice el premio.
  factory PokerConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final res = redemption.outcome.gameResult;
    final ms = ((cfg['revealIntervalMs'] as num?)?.toInt() ?? 900).clamp(400, 3000);

    List<String> cards(String key, int n) {
      final raw = res[key];
      if (raw is! List || raw.length != n || raw.any((c) => c is! String || !_card.hasMatch(c))) {
        throw FormatException('$key debe tener $n cartas válidas');
      }
      return raw.cast<String>();
    }

    final hole = cards('hole', 2);
    final board = cards('board', 5);
    final best = cards('best', 5);
    if ({...hole, ...board}.length != 7) throw const FormatException('las 7 cartas no pueden repetirse');
    if (best.any((c) => !hole.contains(c) && !board.contains(c))) {
      throw const FormatException('best debe ser de las 7 cartas');
    }

    final rawStages = res['stages'];
    if (rawStages is! List || rawStages.length != 3 || rawStages.any((c) => !categories.contains(c))) {
      throw const FormatException('stages debe ser de 3 categorías');
    }
    final stages = rawStages.cast<String>();
    final category = res['category'];
    if (category is! String || category != stages[2]) throw const FormatException('category debe ser la última etapa');
    final hand = res['hand'] as String?;

    final legend = <PokerLegendEntry>[];
    for (final l in (cfg['legend'] as List?) ?? const []) {
      final m = (l as Map).cast<String, dynamic>();
      legend.add(
        PokerLegendEntry(hand: m['hand'] as String, prize: Prize.fromJson((m['prize'] as Map).cast<String, dynamic>())),
      );
    }

    final wins = redemption.outcome.prize.isWin;
    if (wins != (hand != null)) throw const FormatException('hand debe venir si y solo si hay premio');
    if (wins) {
      if (hand != category) throw const FormatException('la mano que paga debe ser la categoría final');
      final entry = legend.where((l) => l.hand == hand);
      if (entry.isEmpty || entry.first.prize.id != redemption.outcome.prize.id) {
        throw const FormatException('la mano ganadora no corresponde al premio');
      }
    }
    return PokerConfig(
      interval: Duration(milliseconds: ms),
      hole: hole,
      board: board,
      stages: stages,
      category: category,
      hand: hand,
      best: best,
      legend: legend,
    );
  }
}
