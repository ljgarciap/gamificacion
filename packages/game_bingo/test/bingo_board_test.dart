import 'package:flutter_test/flutter_test.dart';
import 'package:game_bingo/game_bingo.dart';
import 'package:gamification_core/gamification_core.dart';

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

void main() {
  group('BingoConfig', () {
    test('lee el cartón, las bolas, los eventos y la tabla de premios', () async {
      final c = BingoConfig.fromRedemption(await _redeem('BINGO-GANA'));
      expect(c.card[2][2], isNull, reason: 'centro libre');
      expect(c.balls, hasLength(24));
      expect(c.winAt, 8);
      expect(c.pattern, 'line');
      expect(c.endAt, 24);
      expect(c.legend.map((l) => l.pattern), ['corners', 'line']);
    });

    test('la letra de cada número sigue las columnas B-I-N-G-O', () {
      expect([1, 15, 16, 30, 31, 45, 46, 60, 61, 75].map(BingoConfig.letterFor).join(), 'BBIINNGGOO');
    });

    test('rechaza lo que contradice el premio o la forma del juego', () async {
      final r = await _redeem('BINGO-GANA');
      Redemption with_(Map<String, Object?> result, {Map<String, Object?>? cfg}) => Redemption(
        redemptionId: r.redemptionId,
        outcome: Outcome(prize: r.outcome.prize, gameResult: {...r.outcome.gameResult, ...result}),
        campaign: Campaign(id: 'c', name: 'n', gameType: 'bingo', gameConfig: {...r.campaign.gameConfig, ...?cfg}),
      );
      expect(
        () => BingoConfig.fromRedemption(with_({'winAt': null, 'pattern': null, 'cells': null})),
        throwsFormatException,
        reason: 'premio sin patrón',
      );
      expect(
        () => BingoConfig.fromRedemption(with_({'pattern': 'corners'})),
        throwsFormatException,
        reason: 'patrón de otro premio',
      );
      expect(
        () => BingoConfig.fromRedemption(
          with_({
            'balls': [1, 2, 3],
          }),
        ),
        throwsFormatException,
        reason: 'faltan bolas',
      );
      expect(
        () => BingoConfig.fromRedemption(with_({}, cfg: {'draws': 10})),
        throwsFormatException,
        reason: 'draws fuera de rango',
      );
      final dup = [...(r.outcome.gameResult['balls'] as List)]..[1] = (r.outcome.gameResult['balls'] as List)[0];
      expect(() => BingoConfig.fromRedemption(with_({'balls': dup})), throwsFormatException, reason: 'bola repetida');
      final badCenter = [
        for (final row in r.outcome.gameResult['card'] as List) [...row as List],
      ];
      badCenter[2][2] = 5;
      expect(
        () => BingoConfig.fromRedemption(with_({'card': badCenter})),
        throwsFormatException,
        reason: 'el centro es libre',
      );
    });
  });

  group('BingoBoard', () {
    test('las bolas salen en el orden del servidor y se marcan solas en el cartón', () async {
      final b = BingoBoard(BingoConfig.fromRedemption(await _redeem('BINGO-GANA')));
      expect(b.drawn, 0);
      expect(b.isMarked(2, 2), isTrue, reason: 'el centro ya está marcado');
      final d1 = b.next()!; // 7 está en el cartón, fila 2 columna 0
      expect(d1.number, 7);
      expect(d1.cell, (2, 0));
      expect(b.isMarked(2, 0), isTrue);
      final d2 = b.next()!; // 70 no está en el cartón
      expect(d2.number, 70);
      expect(d2.cell, isNull);
      expect(b.recent(), [70, 7]);
    });

    test('los eventos aparecen en la bola que el servidor dijo y no antes', () async {
      final b = BingoBoard(BingoConfig.fromRedemption(await _redeem('BINGO-GANA')));
      for (var i = 1; i < 8; i++) {
        expect(b.next()!.events, isEmpty, reason: 'bola $i');
      }
      final d = b.next()!; // bola 8
      expect(d.events.single.pattern, 'line');
      expect(b.happened, hasLength(1));
      expect(b.isOver, isFalse, reason: 'la línea no es el patrón mayor: la partida sigue hasta endAt');
    });

    test('la partida termina en endAt y no saca más bolas', () async {
      final b = BingoBoard(BingoConfig.fromRedemption(await _redeem('BINGO-GANA')));
      var n = 0;
      while (b.next() != null) {
        n++;
      }
      expect(n, 24);
      expect(b.isOver, isTrue);
      expect(b.next(), isNull);
    });

    test('"Revelar todo" llega al final con el mismo resultado', () async {
      final w = BingoBoard(BingoConfig.fromRedemption(await _redeem('BINGO-GANA')))..revealAll();
      expect((w.isOver, w.drawn, w.happened.length), (true, 24, 1));
      final n = BingoBoard(BingoConfig.fromRedemption(await _redeem('BINGO-NADA')))..revealAll();
      expect((n.isOver, n.drawn), (true, 24));
      expect(n.happened, isEmpty);
    });
  });
}
