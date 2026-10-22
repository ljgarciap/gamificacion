import 'package:flutter_test/flutter_test.dart';
import 'package:game_poker/game_poker.dart';
import 'package:gamification_core/gamification_core.dart';

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

void main() {
  group('PokerConfig', () {
    test('lee las 7 cartas, las etapas, la mejor mano y la tabla de premios', () async {
      final c = PokerConfig.fromRedemption(await _redeem('POKER-GANA'));
      expect(c.hole, ['KH', 'KD']);
      expect(c.board, hasLength(5));
      expect(c.stages, ['three_of_a_kind', 'three_of_a_kind', 'full_house']);
      expect(c.category, 'full_house');
      expect(c.hand, 'full_house');
      expect(c.best, hasLength(5));
      expect(c.legend.map((l) => l.hand), ['full_house', 'three_of_a_kind']);
    });

    test('rechaza lo que contradice el premio o la forma del juego', () async {
      final r = await _redeem('POKER-GANA');
      Redemption with_(Map<String, Object?> result, {Map<String, Object?>? cfg}) => Redemption(
        redemptionId: r.redemptionId,
        outcome: Outcome(prize: r.outcome.prize, gameResult: {...r.outcome.gameResult, ...result}),
        campaign: Campaign(id: 'c', name: 'n', gameType: 'poker', gameConfig: {...r.campaign.gameConfig, ...?cfg}),
      );
      expect(() => PokerConfig.fromRedemption(with_({'hand': null})), throwsFormatException, reason: 'premio sin mano');
      expect(
        () => PokerConfig.fromRedemption(with_({'hand': 'three_of_a_kind'})),
        throwsFormatException,
        reason: 'la mano no es la final',
      );
      expect(
        () => PokerConfig.fromRedemption(
          with_({
            'hole': ['KH'],
          }),
        ),
        throwsFormatException,
        reason: 'faltan cartas',
      );
      expect(
        () => PokerConfig.fromRedemption(
          with_({
            'hole': ['KH', 'ZZ'],
          }),
        ),
        throwsFormatException,
        reason: 'carta inválida',
      );
      expect(
        () => PokerConfig.fromRedemption(
          with_({
            'hole': ['KH', '2S'],
          }),
        ),
        throwsFormatException,
        reason: 'carta repetida con la mesa',
      );
      expect(
        () => PokerConfig.fromRedemption(
          with_({
            'best': ['KH', 'KD', 'KC', '2S', 'AS'],
          }),
        ),
        throwsFormatException,
        reason: 'best fuera de las 7',
      );
      expect(
        () => PokerConfig.fromRedemption(
          with_({
            'stages': ['pair', 'pair', 'pair'],
          }),
        ),
        throwsFormatException,
        reason: 'category no es la última etapa',
      );
      expect(
        () => PokerConfig.fromRedemption(with_({}, cfg: {'legend': []})),
        throwsFormatException,
        reason: 'la mano no tiene premio en la tabla',
      );
    });

    test('sin premio: no hay mano que pague', () async {
      final c = PokerConfig.fromRedemption(await _redeem('POKER-NADA'));
      expect(c.hand, isNull);
      expect(c.category, 'high_card');
    });
  });

  group('PokerTable', () {
    test(
      'el crupier abre el flop (3), el turn (4) y el river (5) y la mejor mano sigue lo que dijo el servidor',
      () async {
        final t = PokerTable(PokerConfig.fromRedemption(await _redeem('POKER-GANA')));
        expect((t.opened, t.current, t.isOver), (0, null, false));
        expect(t.isBoardOpen(0), isFalse);
        t.next();
        expect((t.opened, t.current), (3, 'three_of_a_kind'));
        expect(t.isBoardOpen(2), isTrue);
        expect(t.isBoardOpen(3), isFalse);
        t.next();
        expect((t.opened, t.current, t.isOver), (4, 'three_of_a_kind', false));
        t.next();
        expect((t.opened, t.current, t.isOver), (5, 'full_house', true));
        expect(t.next(), isFalse, reason: 'ya está todo abierto');
      },
    );

    test('"Revelar todo" abre lo que falta con el mismo resultado', () async {
      final t = PokerTable(PokerConfig.fromRedemption(await _redeem('POKER-NADA')))..revealAll();
      expect((t.opened, t.current, t.isOver), (5, 'high_card', true));
    });
  });
}
