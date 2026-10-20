import 'package:flutter_test/flutter_test.dart';
import 'package:game_memory/game_memory.dart';
import 'package:gamification_core/gamification_core.dart';

Redemption _redemption(Object? cards, {Map<String, dynamic> config = const {}, Prize? prize}) => Redemption(
  redemptionId: 'r-1',
  campaign: Campaign(id: 'c', name: 'Concéntrese', gameType: 'memory', gameConfig: config),
  outcome: Outcome(
    prize: prize ?? const Prize(id: 'none', type: PrizeType.none, label: 'Sin premio'),
    gameResult: {'cards': cards},
  ),
);

const _six = ['a', 'b', 'c', 'a', 'b', 'c', 'd', 'e', 'f', 'd', 'e', 'f'];

void main() {
  group('MemoryBoard', () {
    test('la primera carta destapa y la segunda igual forma pareja', () {
      final b = MemoryBoard(const ['a', 'b', 'a', 'b']);
      expect(b.flip(0), FlipOutcome.first);
      expect(b.stateAt(0), CardState.up);
      expect(b.flip(2), FlipOutcome.match);
      expect(b.stateAt(0), CardState.matched);
      expect(b.stateAt(2), CardState.matched);
      expect(b.lastMatch, 'a');
      expect(b.foundPairs, 1);
      expect(b.allResolved, isFalse);
    });

    test('dos cartas distintas quedan a la vista y bloquean hasta taparlas', () {
      final b = MemoryBoard(const ['a', 'b', 'a', 'b']);
      b.flip(0);
      expect(b.flip(1), FlipOutcome.mismatch);
      expect(b.hasPending, isTrue);
      expect(b.flip(2), FlipOutcome.ignored, reason: 'mientras se resuelve no se aceptan más toques');
      b.hidePending();
      expect(b.hasPending, isFalse);
      expect(b.stateAt(0), CardState.down);
      expect(b.stateAt(1), CardState.down);
      expect(b.flip(2), FlipOutcome.first);
    });

    test('tocar una carta ya destapada o resuelta no hace nada', () {
      final b = MemoryBoard(const ['a', 'b', 'a', 'b']);
      b.flip(0);
      expect(b.flip(0), FlipOutcome.ignored);
      b.flip(2);
      expect(b.flip(0), FlipOutcome.ignored);
      expect(b.foundPairs, 1);
    });

    test('al hallar todas las parejas el tablero queda resuelto', () {
      final b = MemoryBoard(const ['a', 'b', 'a', 'b']);
      b.flip(0);
      b.flip(2);
      b.flip(1);
      expect(b.flip(3), FlipOutcome.match);
      expect(b.allResolved, isTrue);
      expect(b.foundPairs, 2);
    });

    test('revealAll destapa todo y limpia lo pendiente', () {
      final b = MemoryBoard(const ['a', 'b', 'a', 'b']);
      b.flip(0);
      b.flip(1);
      b.revealAll();
      expect(b.allResolved, isTrue);
      expect(b.hasPending, isFalse);
      expect(b.foundPairs, 2);
    });
  });

  group('MemoryConfig', () {
    test('lee la configuración y la disposición del servidor', () {
      final c = MemoryConfig.fromRedemption(_redemption(_six, config: {'pairs': 6, 'columns': 4, 'flipBackMs': 1200}));
      expect(c.pairs, 6);
      expect(c.columns, 4);
      expect(c.rows, 3);
      expect(c.flipBack, const Duration(milliseconds: 1200));
      expect(c.cards, _six);
    });

    test('valores por defecto y límites del tiempo de volteo', () {
      final d = MemoryConfig.fromRedemption(_redemption(_six));
      expect((d.pairs, d.columns, d.flipBack), (6, 4, const Duration(milliseconds: 900)));
      expect(MemoryConfig.fromRedemption(_redemption(_six, config: {'flipBackMs': 5})).flipBack.inMilliseconds, 300);
      expect(
        MemoryConfig.fromRedemption(_redemption(_six, config: {'flipBackMs': 99999})).flipBack.inMilliseconds,
        3000,
      );
    });

    test('rechaza parámetros fuera de rango', () {
      for (final cfg in [
        {'pairs': 2},
        {'pairs': 9},
        {'columns': 1},
        {'columns': 7},
      ]) {
        expect(
          () => MemoryConfig.fromRedemption(_redemption(_six, config: cfg)),
          throwsFormatException,
          reason: '$cfg',
        );
      }
    });

    test('rechaza disposiciones mal formadas', () {
      for (final cards in [
        null,
        'texto',
        <String>['a', 'a'], // cantidad que no cuadra con 6 parejas
        ['a', 'a', 'a', 'b', 'b', 'c', 'c', 'd', 'd', 'e', 'e', 'f'], // un símbolo tres veces
        ['a', 'a', 'b', 'b', 'c', 'c', 'd', 'd', 'e', 'e', 'f', 'g'], // símbolos sueltos
        ['a', '', 'b', 'b', 'c', 'c', 'd', 'd', 'e', 'e', 'f', 'f'],
        ['a', 1, 'b', 'b', 'c', 'c', 'd', 'd', 'e', 'e', 'f', 'f'],
      ]) {
        expect(() => MemoryConfig.fromRedemption(_redemption(cards)), throwsFormatException, reason: '$cards');
      }
    });

    test('prizeCards devuelve las dos posiciones del premio, y nada sin premio', () {
      const prize = Prize(id: 'p10k', type: PrizeType.money, label: r'$ 10.000', amount: 10000);
      final cards = ['p10k', 'b', 'c', 'p10k', 'b', 'c', 'd', 'e', 'f', 'd', 'e', 'f'];
      final c = MemoryConfig.fromRedemption(_redemption(cards, prize: prize));
      expect(c.prizeCards(prize), [0, 3]);
      expect(c.prizeCards(const Prize(id: 'none', type: PrizeType.none, label: 'x')), isEmpty);
    });

    test('el MockRepository entrega tableros válidos para ganar y para no ganar', () async {
      for (final code in ['CONCENTRESE-GANA', 'CONCENTRESE-NADA']) {
        final r = await MockRepository().redeem(code, idempotencyKey: 'k-$code');
        final c = MemoryConfig.fromRedemption(r);
        expect(c.prizeCards(r.outcome.prize).length, r.outcome.prize.isWin ? 2 : 0, reason: code);
      }
    });
  });
}
