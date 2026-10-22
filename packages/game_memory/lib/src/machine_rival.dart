import 'dart:math';

import 'memory_board.dart';

/// Rival simulado de Concéntrese: elige las dos cartas de su turno. Es SOLO ANIMACIÓN: el premio ya está decidido por el servidor y la
/// máquina no influye en él. Para que el espectáculo sea coherente con ese resultado:
///  - nunca voltea una carta de la pareja premio (así el jugador es quien la encuentra);
///  - a veces acierta una pareja de relleno, para parecer competente, y a veces falla.
class MachineRival {
  MachineRival({Random? random, this.matchChance = 0.35}) : _random = random ?? Random();

  final Random _random;

  /// Probabilidad de que, pudiendo, acierte una pareja de relleno.
  final double matchChance;

  /// Las dos posiciones que volteará, o null si no puede jugar sin tocar una carta prohibida (pasa el turno).
  (int, int)? pickTurn(MemoryBoard board, Set<int> forbidden) {
    final open = [
      for (var i = 0; i < board.cards.length; i++)
        if (board.stateAt(i) == CardState.down && !forbidden.contains(i)) i,
    ];
    if (open.length < 2) return null;

    // Parejas de relleno que aún están tapadas completas.
    final pairs = <(int, int)>[];
    for (var a = 0; a < open.length; a++) {
      for (var b = a + 1; b < open.length; b++) {
        if (board.cards[open[a]] == board.cards[open[b]]) pairs.add((open[a], open[b]));
      }
    }
    if (pairs.isNotEmpty && _random.nextDouble() < matchChance) {
      final p = pairs[_random.nextInt(pairs.length)];
      return _random.nextBool() ? p : (p.$2, p.$1);
    }

    // Un intento fallido: dos cartas de símbolos distintos.
    final shuffled = [...open]..shuffle(_random);
    for (final a in shuffled) {
      for (final b in shuffled) {
        if (a != b && board.cards[a] != board.cards[b]) return (a, b);
      }
    }
    // Solo quedan dos cartas iguales: no se puede fallar, acierta.
    return pairs.isEmpty ? null : pairs.first;
  }
}
