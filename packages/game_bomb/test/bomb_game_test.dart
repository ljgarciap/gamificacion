import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_bomb/game_bomb.dart';
import 'package:gamification_core/gamification_core.dart';

class _FakeFeedback implements GameFeedback {
  int ticks = 0, wins = 0, loses = 0;
  @override
  void tick() => ticks++;
  @override
  void win() => wins++;
  @override
  void lose() => loses++;
}

class _Harness {
  int started = 0, finished = 0;
  final feedback = _FakeFeedback();
}

Future<void> _pump(
  WidgetTester t,
  Redemption r,
  _Harness h, {
  bool reduceMotion = false,
  Size size = const Size(400, 800),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: reduceMotion, size: size),
        child: Scaffold(
          body: GamificationScope(
            theme: const GamificationTheme(),
            strings: const EsStrings(),
            preferences: const GamePreferences(),
            child: BombGame(
              session: GameSession(
                redemption: r,
                preferences: const GamePreferences(),
                onStarted: () => h.started++,
                onFinished: () => h.finished++,
              ),
              feedbackFactory: (_) => h.feedback,
            ),
          ),
        ),
      ),
    ),
  );
}

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

Finder _card(int i) => find.byKey(ValueKey('bomb-card-$i'));

Future<void> _tap(WidgetTester t, int i) async {
  await t.tap(_card(i));
  await t.pump();
  await t.pump(const Duration(milliseconds: 400));
}

Future<void> _advance(WidgetTester t, [int ms = 2000]) async {
  for (var i = 0; i < ms ~/ 100; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

String _status(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('bomb-status'))).data!;

void main() {
  testWidgets('estado inicial: 12 cartas tapadas, mecha entera y nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BOMBA-GANA'), h);
    for (var i = 0; i < 12; i++) {
      expect(find.bySemanticsLabel('Carta ${i + 1} de 12, boca abajo'), findsOneWidget);
    }
    expect(_status(t), 'Ochos: 0 de 3');
    expect(find.text('Mecha: 12 de 12'), findsOneWidget);
    expect(find.text('Las cartas se destapan en orden; tu resultado ya está decidido.'), findsOneWidget);
    expect((h.started, h.finished), (0, 0));
  });

  testWidgets(
    'con premio: tocar cartas en CUALQUIER orden destapa las caras en el orden del servidor, y al tercer 8 se desactiva',
    (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('BOMBA-GANA'), h);
      // Caras del servidor: star, 8, moon, coin, 8, gem, sun, bell, heart, flash, 8, star
      final order = [11, 3, 9, 0, 5, 7, 1, 10, 2, 8, 4]; // el jugador toca como quiera
      for (var k = 0; k < 10; k++) {
        await _tap(t, order[k]);
      }
      expect(h.started, 1, reason: 'el inicio se avisa una sola vez');
      expect(_status(t), 'Ochos: 2 de 3');
      expect(find.text('Mecha: 2 de 12'), findsOneWidget);
      expect(find.byKey(const ValueKey('bomb-eight-card')), findsNWidgets(2));
      expect(h.finished, 0);
      await _tap(t, order[10]); // 11.º volteo: el tercer 8
      expect(_status(t), '¡Bomba desactivada!');
      expect(find.byKey(const ValueKey('bomb-eight-card')), findsNWidgets(3));
      expect(find.text(r'$ 50.000'), findsOneWidget, reason: 'se ve el premio');
      expect(h.feedback.wins, 1);
      await _advance(t, 1500);
      expect(h.finished, 1);
    },
  );

  testWidgets('sin premio: al voltear todas sin tres 8 la bomba estalla y termina', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BOMBA-NADA'), h);
    for (var i = 0; i < 12; i++) {
      await _tap(t, i);
    }
    expect(_status(t), '¡Se acabó la mecha!');
    expect(find.byKey(const ValueKey('bomb-eight-card')), findsNWidgets(2));
    expect(h.feedback.loses, 1);
    await _advance(t, 2500);
    expect(h.finished, 1);
  });

  testWidgets('"Revelar todo" termina de inmediato con el mismo resultado (con premio y sin él)', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BOMBA-GANA'), h);
    await t.tap(find.byKey(const ValueKey('bomb-reveal-all')));
    await t.pump();
    expect(_status(t), '¡Bomba desactivada!');
    expect(h.started, 1);
    await _advance(t, 1500);
    expect(h.finished, 1);

    final h2 = _Harness();
    await t.pumpWidget(const SizedBox()); // otro juego nuevo, no el mismo estado
    await _pump(t, await _redeem('BOMBA-NADA'), h2);
    await t.tap(find.byKey(const ValueKey('bomb-reveal-all')));
    await t.pump();
    expect(_status(t), '¡Se acabó la mecha!');
    await _advance(t, 2500);
    expect(h2.finished, 1);
  });

  testWidgets('con "reducir movimiento" el final se ve sin animar y sin errores', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BOMBA-NADA'), h, reduceMotion: true);
    await t.tap(find.byKey(const ValueKey('bomb-reveal-all')));
    await t.pump();
    expect(_status(t), '¡Se acabó la mecha!');
    expect(t.takeException(), isNull);
    await _advance(t, 2500);
    expect(h.finished, 1);
  });

  testWidgets('una carta ya destapada no hace nada al tocarla otra vez', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BOMBA-GANA'), h);
    await _tap(t, 4);
    await t.tap(_card(4), warnIfMissed: false);
    await t.pump(const Duration(milliseconds: 400));
    expect(find.text('Mecha: 11 de 12'), findsOneWidget);
    expect(h.feedback.ticks, 1);
  });

  testWidgets('si el servidor manda caras que contradicen el premio, muestra el aviso y deja continuar', (t) async {
    final h = _Harness();
    final bad = Redemption(
      redemptionId: 'r',
      campaign: Campaign(id: 'c', name: 'n', gameType: 'bomb', gameConfig: const {'cards': 9, 'columns': 3}),
      outcome: Outcome(
        prize: const Prize(id: 'p50k', type: PrizeType.money, label: r'$ 50.000', amount: 50000),
        gameResult: const {
          'faces': ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'],
        }, // premio sin tres 8
      ),
    );
    await _pump(t, bad, h);
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
    await t.tap(find.text('Continuar'));
    expect(h.finished, 1);
  });

  testWidgets('se ve bien en pantalla chica (360×640) con 16 cartas y sin desbordes', (t) async {
    final h = _Harness();
    final r = await _redeem('BOMBA-GANA');
    final big = Redemption(
      redemptionId: r.redemptionId,
      outcome: Outcome(
        prize: r.outcome.prize,
        gameResult: {
          'faces': [
            'star',
            '8',
            'moon',
            'coin',
            '8',
            'gem',
            'sun',
            'bell',
            'heart',
            'flash',
            'coin',
            'sun',
            'gem',
            'bell',
            '8',
            'star',
          ],
        },
      ),
      campaign: Campaign(
        id: r.campaign.id,
        name: r.campaign.name,
        gameType: 'bomb',
        gameConfig: const {'cards': 16, 'columns': 4},
      ),
    );
    await _pump(t, big, h, size: const Size(360, 640));
    expect(t.takeException(), isNull);
    expect(find.byKey(const ValueKey('bomb-card-15')), findsOneWidget);
  });
}
