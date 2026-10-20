import 'package:flutter_test/flutter_test.dart';
import 'package:game_dice/game_dice.dart';
import 'package:gamification_core/gamification_core.dart';

const _p50k = {'id': 'p50k', 'type': 'money', 'label': r'$ 50.000', 'amount': 50000};

Redemption _redemption(Object? dice, {Map<String, dynamic> config = const {}}) => Redemption(
  redemptionId: 'r-1',
  campaign: Campaign(id: 'c', name: 'Dados', gameType: 'dice', gameConfig: config),
  outcome: Outcome(
    prize: const Prize(id: 'none', type: PrizeType.none, label: 'Sin premio'),
    gameResult: {'dice': dice},
  ),
);

void main() {
  group('DiceConfig', () {
    test('lee los dados, la duración y la tabla de premios', () {
      final c = DiceConfig.fromRedemption(
        _redemption(
          [4, 2],
          config: {
            'diceCount': 2,
            'rollDurationMs': 2500,
            'legend': [
              {
                'prize': _p50k,
                'rolls': [
                  [6, 1],
                  [3, 3],
                ],
              },
            ],
          },
        ),
      );
      expect(c.dice, (4, 2));
      expect(c.rollDuration, const Duration(milliseconds: 2500));
      expect(c.legend, hasLength(1));
      expect(c.legend.single.prize.id, 'p50k');
      expect(c.legend.single.rolls, [(1, 6), (3, 3)], reason: 'combinaciones ordenadas a ≤ b');
    });

    test('valores por defecto y límites de la duración', () {
      final d = DiceConfig.fromRedemption(_redemption([1, 1]));
      expect(d.rollDuration, const Duration(milliseconds: 1800));
      expect(d.legend, isEmpty);
      expect(
        DiceConfig.fromRedemption(_redemption([1, 1], config: {'rollDurationMs': 10})).rollDuration.inMilliseconds,
        500,
      );
      expect(
        DiceConfig.fromRedemption(_redemption([1, 1], config: {'rollDurationMs': 99999})).rollDuration.inMilliseconds,
        5000,
      );
    });

    test('rechaza dados mal formados', () {
      for (final dice in [
        null,
        'x',
        [1],
        [1, 2, 3],
        [0, 3],
        [3, 7],
        ['3', 4],
        [2.5, 4],
      ]) {
        expect(() => DiceConfig.fromRedemption(_redemption(dice)), throwsFormatException, reason: '$dice');
      }
    });

    test('solo se juega con 2 dados', () {
      expect(() => DiceConfig.fromRedemption(_redemption([1, 2], config: {'diceCount': 3})), throwsFormatException);
    });

    test('una tabla rota no impide jugar: se omiten solo las entradas inválidas', () {
      final c = DiceConfig.fromRedemption(
        _redemption(
          [1, 1],
          config: {
            'legend': [
              'basura',
              {'prize': _p50k},
              {
                'prize': _p50k,
                'rolls': [
                  [1, 1],
                  [9, 9],
                  [2],
                ],
              },
            ],
          },
        ),
      );
      expect(c.legend, hasLength(1));
      expect(c.legend.single.rolls, [(1, 1)]);
      expect(DiceConfig.fromRedemption(_redemption([1, 1], config: {'legend': 'x'})).legend, isEmpty);
    });

    test('el MockRepository entrega dados válidos para ganar y para no ganar', () async {
      for (final code in ['DADOS-GANA', 'DADOS-NADA']) {
        final r = await MockRepository().redeem(code, idempotencyKey: 'k-$code');
        final c = DiceConfig.fromRedemption(r);
        final rolls = c.legend.firstWhere((e) => e.prize.id == r.outcome.prize.id).rolls;
        final (a, b) = c.dice;
        expect(rolls, contains(a < b ? (a, b) : (b, a)), reason: '$code: los dados pertenecen al premio');
      }
    });
  });
}
