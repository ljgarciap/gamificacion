import 'package:gamification_core/gamification_core.dart';

/// Un premio de la tabla y las combinaciones de dados que lo ganan (`a ≤ b`).
class DiceLegendEntry {
  const DiceLegendEntry({required this.prize, required this.rolls});

  final Prize prize;
  final List<(int, int)> rolls;
}

/// Configuración de Dados, leída de `Campaign.gameConfig` (duración, tabla de premios) y de
/// `Outcome.gameResult` (la combinación que decidió el servidor).
class DiceConfig {
  const DiceConfig({required this.dice, required this.rollDuration, required this.legend, this.rival = false});

  static const minDuration = 500;
  static const maxDuration = 5000;

  /// Cara de cada uno de los dos dados (1 a 6).
  final (int, int) dice;
  final Duration rollDuration;

  /// Tabla de premios para mostrar en pantalla. Opcional: si falta o viene rota, la tabla simplemente no se muestra.
  final List<DiceLegendEntry> legend;

  /// La campaña pide un rival simulado (`gameConfig.rival == true`): solo animación, no cambia el resultado.
  final bool rival;

  /// Lanza [FormatException] si la campaña no trae unos dados usables.
  factory DiceConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final count = (cfg['diceCount'] as num?)?.toInt() ?? 2;
    if (count != 2) throw FormatException('Dados se juega con 2 dados: $count');
    final ms = ((cfg['rollDurationMs'] as num?)?.toInt() ?? 1800).clamp(minDuration, maxDuration);

    final raw = redemption.outcome.gameResult['dice'];
    if (raw is! List || raw.length != 2 || raw.any((d) => d is! int || d < 1 || d > 6)) {
      throw const FormatException('gameResult.dice debe ser [a, b] con caras de 1 a 6');
    }

    return DiceConfig(
      dice: (raw[0] as int, raw[1] as int),
      rollDuration: Duration(milliseconds: ms),
      legend: _parseLegend(cfg['legend']),
      rival: cfg['rival'] == true,
    );
  }

  /// Entradas inválidas se omiten una a una: la tabla es una ayuda, no debe impedir ver el resultado.
  static List<DiceLegendEntry> _parseLegend(Object? raw) {
    if (raw is! List) return const [];
    final out = <DiceLegendEntry>[];
    for (final e in raw) {
      try {
        final map = (e as Map).cast<String, dynamic>();
        final prize = Prize.fromJson((map['prize'] as Map).cast<String, dynamic>());
        final rolls = <(int, int)>[];
        for (final r in map['rolls'] as List) {
          // Una combinación rota se omite sola: no debe llevarse consigo las demás del premio.
          if (r is! List || r.length != 2 || r[0] is! int || r[1] is! int) continue;
          final a = r[0] as int, b = r[1] as int;
          if (a < 1 || a > 6 || b < 1 || b > 6) continue;
          rolls.add((a < b ? a : b, a < b ? b : a));
        }
        out.add(DiceLegendEntry(prize: prize, rolls: rolls));
      } catch (_) {
        continue;
      }
    }
    return out;
  }
}
