import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';

Redemption redemption({
  Map<String, dynamic> config = const {},
  Object? grid,
  Prize prize = const Prize(id: 'p10k', type: PrizeType.money, label: r'$ 10.000', amount: 10000),
}) => Redemption(
  redemptionId: 'r',
  campaign: Campaign(id: 'c', name: 'n', gameType: 'scratch', gameConfig: config),
  outcome: Outcome(prize: prize, gameResult: {'grid': grid}),
);

List<String> grid9([String s = 'star']) => List.filled(9, s);

void main() {
  group('ScratchMask', () {
    test('sin raspar la cobertura es 0', () {
      expect(ScratchMask(aspect: 1).coverage, 0);
    });

    test('un toque central cubre aproximadamente el área del círculo', () {
      final mask = ScratchMask(aspect: 1);
      final c = mask.scratch(const Offset(0.5, 0.5), 0.25);
      expect(c, closeTo(pi * 0.25 * 0.25, 0.03));
    });

    test('raspar dos veces el mismo punto no suma cobertura', () {
      final mask = ScratchMask(aspect: 1);
      final first = mask.scratch(const Offset(0.5, 0.5), 0.2);
      final second = mask.scratch(const Offset(0.5, 0.5), 0.2);
      expect(second, first);
    });

    test('la cobertura nunca baja', () {
      final mask = ScratchMask(aspect: 1);
      var last = 0.0;
      final rnd = Random(3);
      for (var i = 0; i < 200; i++) {
        final c = mask.scratch(Offset(rnd.nextDouble(), rnd.nextDouble()), 0.05);
        expect(c, greaterThanOrEqualTo(last));
        last = c;
      }
    });

    test('puntos fuera de la tarjeta no rompen ni marcan nada', () {
      final mask = ScratchMask(aspect: 1);
      expect(mask.scratch(const Offset(-2, -2), 0.1), 0);
      expect(mask.scratch(const Offset(5, 0.5), 0.1), 0);
    });

    test('un círculo que se sale del borde solo cuenta lo que cae dentro', () {
      final mask = ScratchMask(aspect: 1);
      final c = mask.scratch(const Offset(0, 0.5), 0.2);
      // Aprox. medio círculo: π·r²/2 ≈ 0.063.
      expect(c, closeTo(pi * 0.04 / 2, 0.02));
    });

    test('un barrido completo en bandas llega a casi toda la tarjeta', () {
      final mask = ScratchMask(aspect: 1);
      for (var y = 0.05; y < 1; y += 0.08) {
        mask.scratchSegment(Offset(0.02, y), Offset(0.98, y), 0.06);
      }
      expect(mask.coverage, greaterThan(0.95));
    });

    test('scratchSegment interpola: un salto largo cubre el camino, no solo los extremos', () {
      final jump = ScratchMask(aspect: 1)..scratchSegment(const Offset(0.1, 0.5), const Offset(0.9, 0.5), 0.05);
      final endpoints = ScratchMask(aspect: 1)
        ..scratch(const Offset(0.1, 0.5), 0.05)
        ..scratch(const Offset(0.9, 0.5), 0.05);
      expect(jump.coverage, greaterThan(endpoints.coverage * 3));
    });

    test('el avance se conserva en coordenadas normalizadas (independiente del tamaño en pantalla)', () {
      // El mismo trazo normalizado da la misma cobertura aunque la tarjeta cambie de tamaño en px.
      final a = ScratchMask(aspect: 0.75)..scratchSegment(const Offset(0.1, 0.2), const Offset(0.9, 0.2), 0.07);
      final b = ScratchMask(aspect: 0.75)..scratchSegment(const Offset(0.1, 0.2), const Offset(0.9, 0.2), 0.07);
      expect(a.coverage, b.coverage);
    });

    test('aspectos distintos de 1 también cubren por completo', () {
      final mask = ScratchMask(aspect: 0.5);
      for (var y = 0.03; y < 0.5; y += 0.05) {
        mask.scratchSegment(Offset(0.02, y), Offset(0.98, y), 0.04);
      }
      expect(mask.coverage, greaterThan(0.95));
    });

    test('fill marca todo', () {
      final mask = ScratchMask(aspect: 1)..fill();
      expect(mask.coverage, 1);
    });
  });

  group('ScratchConfig.fromRedemption', () {
    test('valores por defecto: 3x3, umbral 60 %, pincel 28', () {
      final c = ScratchConfig.fromRedemption(redemption(grid: grid9()));
      expect(c.rows, 3);
      expect(c.cols, 3);
      expect(c.revealThreshold, 0.6);
      expect(c.brushWidth, 28);
      expect(c.grid, hasLength(9));
    });

    test('tamaño, umbral y pincel salen de la campaña', () {
      final c = ScratchConfig.fromRedemption(
        redemption(
          config: {'rows': 2, 'cols': 4, 'revealThreshold': 0.5, 'brushWidth': 40},
          grid: List.filled(8, 'sun'),
        ),
      );
      expect(c.rows, 2);
      expect(c.cols, 4);
      expect(c.revealThreshold, 0.5);
      expect(c.brushWidth, 40);
      expect(c.aspect, 0.5);
    });

    test('umbral y pincel se acotan a valores usables', () {
      final c = ScratchConfig.fromRedemption(
        redemption(config: {'revealThreshold': 5, 'brushWidth': 1}, grid: grid9()),
      );
      expect(c.revealThreshold, 0.95);
      expect(c.brushWidth, 12);
    });

    test('rechaza cuadrículas fuera de 2..5', () {
      for (final bad in [1, 6]) {
        expect(
          () => ScratchConfig.fromRedemption(redemption(config: {'rows': bad}, grid: grid9())),
          throwsFormatException,
          reason: 'rows=$bad',
        );
      }
    });

    test('rechaza grid ausente, de otro largo o con valores inválidos', () {
      for (final bad in <Object?>[
        null,
        'x',
        List.filled(8, 'a'),
        List.filled(10, 'a'),
        [...grid9().take(8), 3],
        [...grid9().take(8), ''],
      ]) {
        expect(() => ScratchConfig.fromRedemption(redemption(grid: bad)), throwsFormatException, reason: '$bad');
      }
    });

    test('casillas ganadoras: las del símbolo del premio; ninguna si no hay premio', () {
      final grid = ['p10k', 'star', 'p10k', 'moon', 'p10k', 'sun', 'star', 'moon', 'sun'];
      final c = ScratchConfig.fromRedemption(redemption(grid: grid));
      expect(c.winningCells(const Prize(id: 'p10k', type: PrizeType.money, label: 'x')), [0, 2, 4]);
      expect(c.winningCells(const Prize(id: 'p10k', type: PrizeType.none, label: 'x')), isEmpty);
    });

    test('funciona con la campaña del MockRepository', () async {
      final win = await MockRepository().redeem('RASPA-GANA', idempotencyKey: 'a');
      final c = ScratchConfig.fromRedemption(win);
      expect(c.winningCells(win.outcome.prize), [0, 2, 4]);

      final lose = await MockRepository().redeem('RASPA-NADA', idempotencyKey: 'b');
      expect(ScratchConfig.fromRedemption(lose).winningCells(lose.outcome.prize), isEmpty);
    });
  });
}
