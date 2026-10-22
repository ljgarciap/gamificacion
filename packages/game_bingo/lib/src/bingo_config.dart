import 'package:gamification_core/gamification_core.dart';

/// Un patrón que paga y su premio (la lista va del mayor al menor premio).
class BingoLegendEntry {
  const BingoLegendEntry({required this.pattern, required this.prize});

  final String pattern;
  final Prize prize;
}

/// Un patrón completado por una bola.
class BingoEvent {
  const BingoEvent({required this.at, required this.pattern, required this.cells});

  /// Número de bola (1 = la primera).
  final int at;
  final String pattern;
  final List<(int, int)> cells;
}

/// Configuración del Bingo, leída de `Campaign.gameConfig` (tamaño, tabla de premios) y de `Outcome.gameResult` (cartón, bolas y eventos que
/// decidió el servidor). Nada se decide aquí.
class BingoConfig {
  const BingoConfig({
    required this.draws,
    required this.interval,
    required this.card,
    required this.balls,
    required this.events,
    required this.winAt,
    required this.pattern,
    required this.cells,
    required this.endAt,
    required this.legend,
  });

  static const minDraws = 24;
  static const maxDraws = 60;
  static const size = 5;

  final int draws;
  final Duration interval;

  /// Cartón por filas; el centro es null (casilla libre).
  final List<List<int?>> card;
  final List<int> balls;
  final List<BingoEvent> events;
  final int? winAt;
  final String? pattern;
  final List<(int, int)>? cells;

  /// Bola en que termina la partida.
  final int endAt;
  final List<BingoLegendEntry> legend;

  /// Letra de la columna de un número (B-I-N-G-O).
  static String letterFor(int number) => 'BINGO'[((number - 1) ~/ 15).clamp(0, 4)];

  /// Lanza [FormatException] si no es un Bingo usable o si lo que mandó el servidor contradice el premio.
  factory BingoConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final res = redemption.outcome.gameResult;
    final draws = (cfg['draws'] as num?)?.toInt() ?? 30;
    if (draws < minDraws || draws > maxDraws) {
      throw FormatException('El Bingo admite de $minDraws a $maxDraws bolas: $draws');
    }
    final ms = ((cfg['drawIntervalMs'] as num?)?.toInt() ?? 1200).clamp(400, 3000);

    final rawCard = res['card'];
    if (rawCard is! List || rawCard.length != size) throw const FormatException('card debe ser de 5×5');
    final card = <List<int?>>[];
    for (var r = 0; r < size; r++) {
      final row = rawCard[r];
      if (row is! List || row.length != size) throw const FormatException('card debe ser de 5×5');
      card.add([for (var c = 0; c < size; c++) _cardCell(r, c, row[c])]);
    }
    final rawBalls = res['balls'];
    if (rawBalls is! List || rawBalls.length != draws) throw FormatException('balls debe tener $draws bolas');
    final balls = [for (final b in rawBalls) _number(b)];
    if (balls.toSet().length != balls.length) throw const FormatException('las bolas no pueden repetirse');

    final events = <BingoEvent>[];
    for (final e in (res['events'] as List?) ?? const []) {
      final m = (e as Map).cast<String, dynamic>();
      events.add(BingoEvent(at: (m['at'] as num).toInt(), pattern: m['pattern'] as String, cells: _cells(m['cells'])));
    }
    final winAt = (res['winAt'] as num?)?.toInt();
    final pattern = res['pattern'] as String?;
    final cells = res['cells'] == null ? null : _cells(res['cells']);
    final endAt = (res['endAt'] as num?)?.toInt() ?? draws;
    if (endAt < 1 || endAt > draws) throw FormatException('endAt fuera de rango: $endAt');

    final legend = <BingoLegendEntry>[];
    for (final l in (cfg['legend'] as List?) ?? const []) {
      final m = (l as Map).cast<String, dynamic>();
      legend.add(
        BingoLegendEntry(
          pattern: m['pattern'] as String,
          prize: Prize.fromJson((m['prize'] as Map).cast<String, dynamic>()),
        ),
      );
    }

    final wins = redemption.outcome.prize.isWin;
    if (wins != (winAt != null) || wins != (pattern != null) || wins != (cells != null)) {
      throw const FormatException('winAt, pattern y cells deben venir si y solo si hay premio');
    }
    if (wins) {
      final entry = legend.where((l) => l.pattern == pattern);
      if (entry.isEmpty || entry.first.prize.id != redemption.outcome.prize.id) {
        throw const FormatException('el patrón ganador no corresponde al premio');
      }
      if (winAt! < 1 || winAt > endAt) throw const FormatException('winAt fuera de la partida');
    }
    return BingoConfig(
      draws: draws,
      interval: Duration(milliseconds: ms),
      card: card,
      balls: balls,
      events: events,
      winAt: winAt,
      pattern: pattern,
      cells: cells,
      endAt: endAt,
      legend: legend,
    );
  }

  /// Una casilla del cartón: el centro debe ser libre (null) y las demás números válidos.
  static int? _cardCell(int row, int col, Object? v) {
    if (row == 2 && col == 2) {
      if (v != null) throw const FormatException('el centro del cartón debe ser libre');
      return null;
    }
    return _number(v);
  }

  static int _number(Object? v) {
    if (v is! num || v.toInt() < 1 || v.toInt() > 75) throw const FormatException('los números van de 1 a 75');
    return v.toInt();
  }

  static List<(int, int)> _cells(Object? raw) {
    if (raw is! List) throw const FormatException('cells debe ser una lista');
    return [
      for (final c in raw)
        if (c is List && c.length == 2)
          ((c[0] as num).toInt(), (c[1] as num).toInt())
        else
          throw const FormatException('cada casilla es [fila, columna]'),
    ];
  }
}
