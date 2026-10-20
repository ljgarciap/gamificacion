import 'dart:math';

import 'package:flutter/painting.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_roulette/src/roulette_painter.dart';
import 'package:gamification_core/gamification_core.dart';

Redemption redemption({
  required List<Prize> segments,
  Object? segmentIndex = 0,
  Map<String, dynamic> extraConfig = const {},
}) => Redemption(
  redemptionId: 'r',
  campaign: Campaign(
    id: 'c',
    name: 'n',
    gameType: 'roulette',
    gameConfig: {
      'segments': [for (final p in segments) p.toJson()],
      ...extraConfig,
    },
  ),
  outcome: Outcome(
    prize: const Prize(id: 'x', type: PrizeType.none, label: 'x'),
    gameResult: {'segmentIndex': segmentIndex},
  ),
);

List<Prize> prizes(int n) => [
  for (var i = 0; i < n; i++) Prize(id: 'p$i', type: PrizeType.money, label: '$i', amount: 1000),
];

void main() {
  group('RouletteMath', () {
    test('sin girar, el segmento 0 está bajo el puntero', () {
      expect(RouletteMath.segmentAt(0, 8), 0);
    });

    test('CA-1: la rotación final deja EXACTAMENTE el segmento del servidor bajo el puntero', () {
      // Todas las cantidades válidas, todos los segmentos y offsets hasta el máximo configurable.
      for (var n = RouletteConfig.minSegments; n <= RouletteConfig.maxSegments; n++) {
        for (var i = 0; i < n; i++) {
          for (final off in [-0.45, -0.35, -0.1, 0.0, 0.1, 0.35, 0.45]) {
            final rotation = RouletteMath.finalRotation(index: i, segments: n, fullTurns: 5, offsetFraction: off);
            expect(RouletteMath.segmentAt(rotation, n), i, reason: 'n=$n i=$i offset=$off');
          }
        }
      }
    });

    test('da al menos las vueltas completas pedidas', () {
      for (var i = 0; i < 8; i++) {
        final r = RouletteMath.finalRotation(index: i, segments: 8, fullTurns: 5);
        expect(r, greaterThanOrEqualTo(5 * 2 * pi));
        expect(r, lessThan(6 * 2 * pi + 1e-9));
      }
    });

    test('sin vueltas extra (reducir movimiento) recorre menos de una vuelta', () {
      for (var i = 0; i < 8; i++) {
        final r = RouletteMath.finalRotation(index: i, segments: 8, fullTurns: 0);
        expect(r, lessThanOrEqualTo(2 * pi));
        expect(RouletteMath.segmentAt(r, 8), i);
      }
    });

    test('el offset mueve el punto de parada dentro del mismo segmento', () {
      final a = RouletteMath.finalRotation(index: 3, segments: 8, fullTurns: 5, offsetFraction: -0.3);
      final b = RouletteMath.finalRotation(index: 3, segments: 8, fullTurns: 5, offsetFraction: 0.3);
      expect(a, isNot(b));
      expect(RouletteMath.segmentAt(a, 8), 3);
      expect(RouletteMath.segmentAt(b, 8), 3);
    });

    test('segmentAt recorre los segmentos en orden mientras gira', () {
      const n = 6;
      final step = RouletteMath.step(n);
      // Girando en sentido horario, bajo el puntero pasan los segmentos n-1, n-2, ... (el 0 se aleja).
      expect(RouletteMath.segmentAt(step * 0.5, n), 5);
      expect(RouletteMath.segmentAt(step * 1.5, n), 4);
      expect(RouletteMath.segmentAt(step * 5.5, n), 0);
    });
  });

  group('RouletteConfig.fromRedemption', () {
    test('lee segmentos, índice y valores por defecto', () {
      final c = RouletteConfig.fromRedemption(redemption(segments: prizes(8), segmentIndex: 3));
      expect(c.segments, hasLength(8));
      expect(c.targetIndex, 3);
      expect(c.duration, const Duration(milliseconds: 4600));
      expect(c.fullTurns, 5);
    });

    test('duración y vueltas salen de la campaña', () {
      final c = RouletteConfig.fromRedemption(
        redemption(segments: prizes(6), extraConfig: {'durationMs': 3000, 'fullTurns': 3}),
      );
      expect(c.duration, const Duration(seconds: 3));
      expect(c.fullTurns, 3);
    });

    test('el offset nunca llega a la divisoria (máx. 0.45)', () {
      final c = RouletteConfig.fromRedemption(redemption(segments: prizes(6), extraConfig: {'offsetSpread': 0.9}));
      expect(c.offsetSpread, 0.45);
    });

    test('RF-03: rechaza menos de 4 y más de 12 segmentos', () {
      expect(() => RouletteConfig.fromRedemption(redemption(segments: prizes(3))), throwsFormatException);
      expect(() => RouletteConfig.fromRedemption(redemption(segments: prizes(13))), throwsFormatException);
      expect(RouletteConfig.fromRedemption(redemption(segments: prizes(4))).segments, hasLength(4));
      expect(RouletteConfig.fromRedemption(redemption(segments: prizes(12))).segments, hasLength(12));
    });

    test('rechaza índice fuera de rango, negativo o de tipo inválido', () {
      for (final bad in [8, -1, '3', null, 2.5]) {
        expect(
          () => RouletteConfig.fromRedemption(redemption(segments: prizes(8), segmentIndex: bad)),
          throwsFormatException,
          reason: 'segmentIndex=$bad',
        );
      }
    });

    test('rechaza campaña sin segmentos', () {
      final r = Redemption(
        redemptionId: 'r',
        campaign: const Campaign(id: 'c', name: 'n', gameType: 'roulette'),
        outcome: const Outcome(
          prize: Prize(id: 'x', type: PrizeType.none, label: 'x'),
        ),
      );
      expect(() => RouletteConfig.fromRedemption(r), throwsFormatException);
    });

    test('funciona con la campaña del MockRepository', () async {
      final r = await MockRepository().redeem('RULETA-50K', idempotencyKey: 'k');
      final c = RouletteConfig.fromRedemption(r);
      expect(c.segments, hasLength(8));
      expect(c.targetIndex, 0);
    });
  });

  group('segmentFills', () {
    const palette = [Color(0xFF0000FF), Color(0xFF00FFFF), Color(0xFF000080), Color(0xFFFFC800)];

    test('segmentos contiguos nunca comparten color, incluida la unión último-primero', () {
      for (var n = RouletteConfig.minSegments; n <= RouletteConfig.maxSegments; n++) {
        final fills = segmentFills(palette, n);
        expect(fills, hasLength(n));
        for (var i = 0; i < n; i++) {
          expect(fills[i], isNot(fills[(i + 1) % n]), reason: 'n=$n posiciones $i y ${(i + 1) % n}');
        }
      }
    });
  });
}
