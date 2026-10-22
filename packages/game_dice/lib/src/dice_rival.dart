import 'dart:math';

import 'dice_config.dart';

/// Rival simulado de Dados: elige la tirada que MUESTRA la máquina. Es SOLO ANIMACIÓN: el premio ya está decidido por el servidor y la máquina
/// no influye en él. La tirada es coherente con la tabla de premios que ve el jugador:
///  - si el jugador gana, la máquina muestra una combinación que NO paga (así «le ganó»);
///  - si el jugador no gana, la máquina muestra una combinación que SÍ paga.
class DiceRival {
  DiceRival({Random? random}) : _random = random ?? Random();

  final Random _random;

  /// La tirada de la máquina, o null si la tabla no permite una tirada coherente (entonces no hay rival).
  (int, int)? pick({required bool playerWins, required List<DiceLegendEntry> legend}) {
    final paying = [
      for (final e in legend)
        if (e.prize.isWin) ...e.rolls,
    ];
    final losing = [
      for (final e in legend)
        if (!e.prize.isWin) ...e.rolls,
    ];
    final from = playerWins ? losing : paying;
    if (from.isEmpty) return null;
    final (a, b) = from[_random.nextInt(from.length)];
    return _random.nextBool() ? (a, b) : (b, a);
  }
}
