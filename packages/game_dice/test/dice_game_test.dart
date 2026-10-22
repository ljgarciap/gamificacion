import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_dice/game_dice.dart';
import 'package:game_dice/src/dice_rival.dart';
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
  Redemption redemption,
  _Harness h, {
  bool reduceMotion = false,
  Size size = const Size(400, 800),
  DiceRival? rival,
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
            child: DiceGame(
              session: GameSession(
                redemption: redemption,
                preferences: const GamePreferences(),
                onStarted: () => h.started++,
                onFinished: () => h.finished++,
              ),
              feedbackFactory: (_) => h.feedback,
              rival: rival,
            ),
          ),
        ),
      ),
    ),
  );
}

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

const _roll = ValueKey('dice-roll-button');

/// Pasos cortos: la tirada (1,8 s) y la pausa final (0,9 s) crean sus temporizadores al ocurrir cada evento.
Future<void> _advance(WidgetTester t, [int ms = 3000]) async {
  for (var i = 0; i < ms ~/ 100; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

(int, int) _faces(WidgetTester t) => (
  t.widget<DiceFace>(find.byKey(const ValueKey('dice-0'))).value,
  t.widget<DiceFace>(find.byKey(const ValueKey('dice-1'))).value,
);

void main() {
  testWidgets('estado inicial: dados en 1 y 1, botón activo, tabla visible y nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-GANA'), h);
    expect(find.text('Toca TIRAR para lanzar los dados'), findsOneWidget);
    expect(_faces(t), (1, 1));
    expect(t.widget<FilledButton>(find.byKey(_roll)).onPressed, isNotNull);
    expect(find.byKey(const ValueKey('dice-legend')), findsOneWidget);
    expect(find.text(r'$ 50.000'), findsOneWidget);
    expect(find.text('6 y 6'), findsOneWidget);
    expect(find.byKey(const ValueKey('dice-legend-win')), findsNothing);
    expect((h.started, h.finished), (0, 0));
  });

  testWidgets('la tabla no lista los "Sin premio"', (t) async {
    await _pump(t, await _redeem('DADOS-NADA'), _Harness());
    expect(find.text('Sin premio'), findsNothing);
    expect(find.text(r'$ 50.000'), findsOneWidget);
  });

  testWidgets('tirar: notifica el inicio una vez, deshabilita el botón y las caras ruedan', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-GANA'), h);
    await t.tap(find.byKey(_roll));
    await t.pump();
    expect(h.started, 1);
    expect(find.text('Tirando los dados…'), findsOneWidget);
    expect(t.widget<FilledButton>(find.byKey(_roll)).onPressed, isNull);
    await _advance(t, 600);
    expect(h.feedback.ticks, greaterThan(0), reason: 'las caras cambian mientras ruedan');
    expect(h.finished, 0);
  });

  testWidgets('aterriza exactamente en la combinación del servidor y resalta la fila del premio', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-GANA'), h); // el servidor decidió 6-6 = $ 50.000
    await t.tap(find.byKey(_roll));
    await _advance(t, 2000);
    expect(_faces(t), (6, 6));
    expect(find.text('Dados: 6 y 6'), findsOneWidget);
    expect(find.text('Toca TIRAR para lanzar los dados'), findsNothing, reason: 'la pista se retira al terminar');
    expect(find.byKey(const ValueKey('dice-legend-win')), findsOneWidget);
    expect(h.feedback.wins, 1);
    expect(h.finished, 0, reason: 'primero la pausa con la combinación resaltada');
    await _advance(t, 1000);
    expect(h.finished, 1);
  });

  testWidgets('sin premio: aterriza en 3 y 5, sin fila resaltada, y termina', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-NADA'), h);
    await t.tap(find.byKey(_roll));
    await _advance(t);
    expect(_faces(t), (3, 5));
    expect(find.byKey(const ValueKey('dice-legend-win')), findsNothing);
    expect(h.feedback.loses, 1);
    expect(h.finished, 1);
  });

  testWidgets('tocar TIRAR de nuevo no repite la tirada ni el aviso', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-GANA'), h);
    await t.tap(find.byKey(_roll));
    await t.tap(find.byKey(_roll), warnIfMissed: false);
    await _advance(t);
    await t.tap(find.byKey(_roll), warnIfMissed: false);
    await _advance(t, 2000);
    expect((h.started, h.finished, h.feedback.wins), (1, 1, 1));
  });

  testWidgets('con "reducir movimiento" muestra el resultado final sin animar', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('DADOS-GANA'), h, reduceMotion: true);
    await t.tap(find.byKey(_roll));
    await t.pump();
    expect(_faces(t), (6, 6));
    expect(h.feedback.ticks, 0, reason: 'no hay caras intermedias');
    expect(find.byKey(const ValueKey('dice-legend-win')), findsOneWidget);
    await _advance(t, 1000);
    expect(h.finished, 1);
  });

  testWidgets('accesibilidad: botón nativo y resultado anunciado', (t) async {
    final handle = t.ensureSemantics();
    await _pump(t, await _redeem('DADOS-GANA'), _Harness());
    expect(find.bySemanticsLabel('TIRAR'), findsOneWidget);
    await t.tap(find.byKey(_roll));
    await _advance(t, 2000);
    expect(find.bySemanticsLabel('Dados: 6 y 6'), findsWidgets);
    handle.dispose();
  });

  testWidgets('sin tabla de premios el juego funciona igual', (t) async {
    final base = await _redeem('DADOS-GANA');
    final h = _Harness();
    await _pump(
      t,
      Redemption(
        redemptionId: 'r',
        campaign: Campaign(id: 'c', name: 'x', gameType: 'dice', gameConfig: const {'rollDurationMs': 600}),
        outcome: base.outcome,
      ),
      h,
    );
    expect(find.byKey(const ValueKey('dice-legend')), findsNothing);
    await t.tap(find.byKey(_roll));
    await _advance(t);
    expect(_faces(t), (6, 6));
    expect(h.finished, 1);
  });

  testWidgets('configuración inválida: avisa y deja continuar al resultado', (t) async {
    final h = _Harness();
    final bad = Redemption(
      redemptionId: 'r',
      campaign: Campaign(id: 'c', name: 'x', gameType: 'dice'),
      outcome: const Outcome(
        prize: Prize(id: 'none', type: PrizeType.none, label: 'Sin premio'),
        gameResult: {
          'dice': [9, 9],
        },
      ),
    );
    await _pump(t, bad, h);
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
    await t.tap(find.text('Continuar'));
    expect(h.finished, 1);
  });

  testWidgets('se desmonta a mitad de la tirada sin errores', (t) async {
    await _pump(t, await _redeem('DADOS-GANA'), _Harness());
    await t.tap(find.byKey(_roll));
    await _advance(t, 400);
    await t.pumpWidget(const SizedBox());
    await _advance(t, 3000);
    expect(t.takeException(), isNull);
  });

  testWidgets('cabe sin desbordar en un viewport pequeño', (t) async {
    await _pump(t, await _redeem('DADOS-GANA'), _Harness(), size: const Size(320, 480));
    expect(t.takeException(), isNull);
    expect(find.byKey(_roll), findsOneWidget);
    expect(t.getRect(find.byKey(_roll)).bottom, lessThanOrEqualTo(480));
  });

  group('rival simulado (gameConfig.rival)', () {
    // Tabla coherente con la regla del demo: los dobles 1-1 y 6-6 pagan; el resto no.
    const legend = [
      {
        'prize': {'id': 'd50k', 'type': 'money', 'label': r'$ 50.000', 'amount': 50000},
        'rolls': [
          [1, 1],
          [6, 6],
        ],
      },
      {
        'prize': {'id': 'dnone', 'type': 'none', 'label': 'Sin premio'},
        'rolls': [
          [1, 2],
          [3, 5],
          [2, 6],
        ],
      },
    ];

    Future<Redemption> withRival(String code) async {
      final r = await _redeem(code);
      return Redemption(
        redemptionId: r.redemptionId,
        outcome: r.outcome,
        campaign: Campaign(
          id: r.campaign.id,
          name: r.campaign.name,
          gameType: r.campaign.gameType,
          gameConfig: {...r.campaign.gameConfig, 'rival': true, 'legend': legend},
        ),
      );
    }

    String status(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('dice-rival-status'))).data!;
    (int, int) machine(WidgetTester t) => (
      t.widget<DiceFace>(find.byKey(const ValueKey('machine-dice-0'))).value,
      t.widget<DiceFace>(find.byKey(const ValueKey('machine-dice-1'))).value,
    );

    testWidgets('sin la bandera no hay máquina', (t) async {
      await _pump(t, await _redeem('DADOS-GANA'), _Harness());
      expect(find.byKey(const ValueKey('dice-rival-note')), findsNothing);
      expect(find.byKey(const ValueKey('dice-machine')), findsNothing);
    });

    testWidgets('si el jugador gana, la máquina tira DESPUÉS y saca una combinación que no paga', (t) async {
      final h = _Harness();
      await _pump(t, await withRival('DADOS-GANA'), h, rival: DiceRival(random: Random(1)));
      expect(find.byKey(const ValueKey('dice-rival-note')), findsOneWidget);
      expect(status(t), 'Tu turno');

      await t.tap(find.byKey(_roll));
      await t.pump();
      await _advance(t, 1900); // el jugador se detiene en 6-6
      expect(_faces(t), (6, 6));
      expect(status(t), 'Turno de la máquina…');
      expect(h.finished, 0, reason: 'todavía falta la tirada de la máquina');

      await _advance(t, 2200);
      expect(status(t), '¡Le ganaste a la máquina!');
      final (a, b) = machine(t);
      expect(a == b, isFalse, reason: 'ni 1-1 ni 6-6: no es una combinación que pague');
      await _advance(t, 2500);
      expect(h.finished, 1);
      expect(h.feedback.wins, 1);
    });

    testWidgets('sin premio, la máquina saca una combinación que SÍ paga y dice que ganó', (t) async {
      final h = _Harness();
      await _pump(t, await withRival('DADOS-NADA'), h, rival: DiceRival(random: Random(2)));
      await t.tap(find.byKey(_roll));
      await t.pump();
      await _advance(t, 4200);
      expect(status(t), 'Esta vez ganó la máquina.');
      final (a, b) = machine(t);
      expect({a, b}.length == 1 && (a == 1 || a == 6), isTrue, reason: 'un doble que paga: $a-$b');
      expect(h.feedback.loses, 1);
    });

    test('la tirada de la máquina siempre es coherente con la tabla (miles de semillas)', () {
      final cfg = DiceConfig.fromRedemption(
        Redemption.fromJson({
          'redemptionId': 'r',
          'campaign': {
            'id': 'c',
            'name': 'n',
            'gameType': 'dice',
            'gameConfig': {'diceCount': 2, 'legend': legend},
          },
          'outcome': {
            'prize': {'id': 'dnone', 'type': 'none', 'label': 'Sin premio'},
            'extraSpins': 0,
            'gameResult': {
              'dice': [3, 5],
            },
          },
        }),
      );
      for (var seed = 0; seed < 2000; seed++) {
        final r = DiceRival(random: Random(seed));
        final win = r.pick(playerWins: false, legend: cfg.legend)!;
        expect(win.$1 == win.$2 && (win.$1 == 1 || win.$1 == 6), isTrue, reason: 'semilla $seed: $win');
        final lose = r.pick(playerWins: true, legend: cfg.legend)!;
        expect(lose.$1 == lose.$2, isFalse, reason: 'semilla $seed: $lose');
      }
      expect(DiceRival().pick(playerWins: true, legend: const []), isNull, reason: 'sin tabla no hay rival');
    });
  });
}
