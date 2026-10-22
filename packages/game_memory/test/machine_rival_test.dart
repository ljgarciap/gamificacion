import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:game_memory/src/machine_rival.dart';
import 'package:game_memory/src/memory_board.dart';

// Tablero de CONCENTRESE-GANA: la pareja del premio está en 1 y 7.
const _cards = ['star', 'p10k', 'moon', 'sun', 'coin', 'gem', 'moon', 'p10k', 'sun', 'star', 'gem', 'coin'];

void main() {
  test('la máquina nunca toca una carta prohibida (la pareja premio), en miles de turnos con semillas distintas', () {
    for (var seed = 0; seed < 300; seed++) {
      final board = MemoryBoard(_cards);
      final rival = MachineRival(random: Random(seed), matchChance: 0.5);
      for (var turn = 0; turn < 30; turn++) {
        final pick = rival.pickTurn(board, {1, 7});
        if (pick == null) break;
        expect({pick.$1, pick.$2}.intersection({1, 7}), isEmpty, reason: 'semilla $seed turno $turn');
        expect(pick.$1, isNot(pick.$2));
        board.flip(pick.$1);
        if (board.flip(pick.$2) == FlipOutcome.mismatch) board.hidePending();
      }
      expect(board.stateAt(1), CardState.down);
      expect(board.stateAt(7), CardState.down);
    }
  });

  test('si no quedan dos cartas jugables sin tocar la prohibida, pasa el turno', () {
    final board = MemoryBoard(_cards);
    // Resuelve todo menos la pareja premio.
    for (final pair in [(0, 9), (2, 6), (3, 8), (4, 11), (5, 10)]) {
      board.flip(pair.$1);
      board.flip(pair.$2);
    }
    expect(MachineRival(random: Random(1)).pickTurn(board, {1, 7}), isNull);
  });

  test('sin premio puede destapar cualquier carta y, con matchChance 1, acierta una pareja cuando la hay', () {
    final board = MemoryBoard(_cards);
    final pick = MachineRival(random: Random(2), matchChance: 1).pickTurn(board, {})!;
    expect(_cards[pick.$1], _cards[pick.$2]);
  });

  test('con matchChance 0 falla siempre mientras quede donde fallar', () {
    final board = MemoryBoard(_cards);
    for (var seed = 0; seed < 100; seed++) {
      final pick = MachineRival(random: Random(seed), matchChance: 0).pickTurn(board, {1, 7})!;
      expect(_cards[pick.$1], isNot(_cards[pick.$2]), reason: 'semilla $seed');
    }
  });
}
