import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_roulette/game_roulette.dart';
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
  WidgetTester tester,
  Redemption redemption,
  _Harness h, {
  bool reduceMotion = false,
  Size size = const Size(400, 800),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: reduceMotion, size: size),
        child: Scaffold(
          body: GamificationScope(
            theme: const GamificationTheme(),
            strings: const EsStrings(),
            preferences: const GamePreferences(),
            child: RouletteGame(
              session: GameSession(
                redemption: redemption,
                preferences: const GamePreferences(),
                onStarted: () => h.started++,
                onFinished: () => h.finished++,
              ),
              feedbackFactory: (_) => h.feedback,
              random: Random(1),
            ),
          ),
        ),
      ),
    ),
  );
}

RoulettePainter _painter(WidgetTester t) =>
    t.widgetList<CustomPaint>(find.byType(CustomPaint)).map((c) => c.painter).whereType<RoulettePainter>().single;

/// Gira a 60 fps (frames de 16 ms) como en un dispositivo real. `pumpAndSettle` avanza de a
/// 100 ms y saltaría divisorias, falseando el conteo de tics.
Future<void> _spinAtSixtyFps(WidgetTester t) async {
  await t.tap(find.text('GIRAR'));
  await t.pump();
  for (var i = 0; i < 300; i++) {
    await t.pump(const Duration(milliseconds: 16));
  }
  await t.pump(const Duration(seconds: 1));
}

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

void main() {
  testWidgets('antes de girar: botón GIRAR visible, sin resaltar, nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RULETA-50K'), h);
    expect(find.text('GIRAR'), findsOneWidget);
    expect(_painter(t).rotation, 0);
    expect(_painter(t).highlight, isNull);
    expect(h.started, 0);
    expect(h.finished, 0);
  });

  testWidgets('CA-1: tras girar se detiene en el segmento que decidió el servidor', (t) async {
    // Un código por segmento del mock: dinero, nada, puntos, cupón y giro extra.
    const expected = {
      'RULETA-50K': 0,
      'RULETA-NADA': 1,
      'RULETA-REPITE': 2,
      'RULETA-PUNTOS': 3,
      'RULETA-CUPON': 5,
      'RULETA-EXTRA': 6,
    };
    for (final entry in expected.entries) {
      final h = _Harness();
      await _pump(t, await _redeem(entry.key), h);
      await t.tap(find.text('GIRAR'));
      await t.pumpAndSettle();

      final painter = _painter(t);
      expect(RouletteMath.segmentAt(painter.rotation, painter.segments.length), entry.value, reason: entry.key);
      expect(painter.highlight, entry.value, reason: entry.key);
      await t.pump(const Duration(seconds: 1));
      await t.pumpWidget(const SizedBox());
    }
  });

  testWidgets('onStarted una vez al girar y onFinished una vez al terminar', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RULETA-50K'), h);

    await t.tap(find.text('GIRAR'));
    await t.pump();
    expect(h.started, 1);
    expect(h.finished, 0, reason: 'todavía está girando');

    await t.pumpAndSettle();
    expect(h.finished, 0, reason: 'espera la pausa con el segmento resaltado');
    await t.pump(const Duration(seconds: 1));
    expect(h.finished, 1);
  });

  testWidgets('doble toque no gira dos veces', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RULETA-50K'), h);
    await t.tap(find.text('GIRAR'));
    await t.pump(const Duration(milliseconds: 100));
    await t.tap(find.text('GIRAR'), warnIfMissed: false);
    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 1));
    expect(h.started, 1);
    expect(h.finished, 1);
    expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
  });

  testWidgets('dura lo que dice la campaña (4,6 s por defecto)', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RULETA-50K'), h);
    await t.tap(find.text('GIRAR'));
    await t.pump();
    await t.pump(const Duration(milliseconds: 4500));
    expect(_painter(t).highlight, isNull, reason: 'a 4,5 s aún gira');
    await t.pump(const Duration(milliseconds: 200));
    expect(_painter(t).highlight, 0, reason: 'a 4,7 s ya se detuvo');
    await t.pump(const Duration(seconds: 1));
  });

  testWidgets('gira mientras avanza y frena (ease-out)', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RULETA-50K'), h);
    await t.tap(find.text('GIRAR'));
    await t.pump();
    await t.pump(const Duration(milliseconds: 500));
    final r1 = _painter(t).rotation;
    await t.pump(const Duration(milliseconds: 500));
    final r2 = _painter(t).rotation;
    await t.pump(const Duration(milliseconds: 3000));
    final r3 = _painter(t).rotation;
    await t.pump(const Duration(milliseconds: 600));
    final r4 = _painter(t).rotation;
    expect(r1, greaterThan(0));
    expect(r2, greaterThan(r1));
    // Más velocidad al inicio que al final: el avance del primer tramo supera al del último.
    expect(r2 - r1, greaterThan(r4 - r3));
    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 1)); // deja correr la pausa final antes de cerrar el test
  });

  group('feedback', () {
    testWidgets('tic en cada divisoria y vibración de victoria', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RULETA-50K'), h);
      await _spinAtSixtyFps(t);
      // 5 vueltas de 8 segmentos: del orden de 40 divisorias.
      expect(h.feedback.ticks, inInclusiveRange(30, 60));
      expect(h.feedback.wins, 1);
      expect(h.feedback.loses, 0);
    });

    testWidgets('sin premio dispara lose, no win', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RULETA-NADA'), h);
      await t.tap(find.text('GIRAR'));
      await t.pumpAndSettle();
      await t.pump(const Duration(seconds: 1));
      expect(h.feedback.wins, 0);
      expect(h.feedback.loses, 1);
    });
  });

  group('reducir movimiento', () {
    testWidgets('giro corto sin vueltas extra ni tics, y llega al segmento correcto', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RULETA-CUPON'), h, reduceMotion: true);
      await t.tap(find.text('GIRAR'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 700));

      final painter = _painter(t);
      expect(painter.rotation, lessThanOrEqualTo(2 * pi));
      expect(RouletteMath.segmentAt(painter.rotation, painter.segments.length), 5);
      expect(painter.highlight, 5);
      expect(h.feedback.ticks, 0);
      await t.pump(const Duration(seconds: 1));
      expect(h.finished, 1);
    });
  });

  group('robustez', () {
    testWidgets('desmontar a mitad del giro no lanza ni notifica fin', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RULETA-50K'), h);
      await t.tap(find.text('GIRAR'));
      await t.pump(const Duration(seconds: 1));
      await t.pumpWidget(const SizedBox());
      await t.pump(const Duration(seconds: 6));
      expect(h.started, 1);
      expect(h.finished, 0);
      expect(t.takeException(), isNull);
    });

    testWidgets('config inválida: muestra aviso y deja continuar al resultado', (t) async {
      final h = _Harness();
      final bad = Redemption(
        redemptionId: 'r',
        campaign: const Campaign(id: 'c', name: 'n', gameType: 'roulette'),
        outcome: const Outcome(
          prize: Prize(id: 'x', type: PrizeType.money, label: 'x', amount: 1),
        ),
      );
      await _pump(t, bad, h);
      expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
      expect(find.text('GIRAR'), findsNothing);

      await t.tap(find.text('Continuar'));
      expect(h.finished, 1);
    });

    testWidgets('se dibuja sin desbordes con 4 y 12 segmentos y en pantallas pequeñas', (t) async {
      for (final n in [4, 12]) {
        final h = _Harness();
        final r = Redemption(
          redemptionId: 'r$n',
          campaign: Campaign(
            id: 'c',
            name: 'n',
            gameType: 'roulette',
            gameConfig: {
              'segments': [
                for (var i = 0; i < n; i++)
                  Prize(id: 'p$i', type: PrizeType.values[i % 4], label: 'Premio largo $i', amount: 5).toJson(),
              ],
            },
          ),
          outcome: const Outcome(
            prize: Prize(id: 'x', type: PrizeType.none, label: 'x'),
            gameResult: {'segmentIndex': 0},
          ),
        );
        await _pump(t, r, h, size: const Size(320, 480));
        await t.tap(find.text('GIRAR'));
        await t.pumpAndSettle();
        await t.pump(const Duration(seconds: 1));
        expect(t.takeException(), isNull, reason: 'n=$n');
        await t.pumpWidget(const SizedBox());
      }
    });

    testWidgets('la ruleta es accesible: etiqueta con la cantidad de premios', (t) async {
      final h = _Harness();
      final handle = t.ensureSemantics();
      await _pump(t, await _redeem('RULETA-50K'), h);
      expect(find.bySemanticsLabel('Ruleta con 8 premios posibles'), findsOneWidget);
      handle.dispose();
    });
  });
}
