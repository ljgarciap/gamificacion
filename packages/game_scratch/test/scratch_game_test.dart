import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_scratch/game_scratch.dart';
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

const _card = ValueKey('scratch-card');
const _winCell = ValueKey('scratch-win-cell');

Future<void> _pump(
  WidgetTester t,
  Redemption redemption,
  _Harness h, {
  bool reduceMotion = false,
  Size size = const Size(400, 800),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(_app(redemption, h, reduceMotion: reduceMotion, size: size));
}

Widget _app(Redemption redemption, _Harness h, {bool reduceMotion = false, Size size = const Size(400, 800)}) =>
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: reduceMotion, size: size),
        child: Scaffold(
          body: GamificationScope(
            theme: const GamificationTheme(),
            strings: const EsStrings(),
            preferences: const GamePreferences(),
            child: ScratchGame(
              session: GameSession(
                redemption: redemption,
                preferences: const GamePreferences(),
                onStarted: () => h.started++,
                onFinished: () => h.finished++,
              ),
              feedbackFactory: (_) => h.feedback,
            ),
          ),
        ),
      ),
    );

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

Rect _cardRect(WidgetTester t) => t.getRect(find.byKey(_card));

/// Raspa bandas horizontales de lado a lado. `fraction` limita cuánto del alto se recorre.
Future<void> _scratchBands(WidgetTester t, {double fraction = 1}) async {
  final r = _cardRect(t);
  final g = await t.startGesture(r.topLeft + const Offset(6, 14));
  for (var y = 14.0; y < r.height * fraction; y += 18) {
    await g.moveTo(Offset(r.left + 6, r.top + y));
    await g.moveTo(Offset(r.right - 6, r.top + y));
  }
  await g.up();
}

double _progress(WidgetTester t) =>
    t.widget<LinearProgressIndicator>(find.byKey(const ValueKey('scratch-progress'))).value!;

/// Deja correr el revelado (450 ms) + pulso (260 ms) + pausa final (900 ms). Se avanza en pasos
/// cortos: los temporizadores de pausa se crean recién cuando termina el fundido, y un único
/// `pump(3 s)` terminaría el fundido en un solo frame sin darles tiempo de dispararse.
Future<void> _settle(WidgetTester t) async {
  for (var i = 0; i < 40; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets('estado inicial: tarjeta cubierta, progreso en 0 y nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    expect(find.byKey(_card), findsOneWidget);
    expect(find.text('Revelar todo'), findsOneWidget);
    expect(_progress(t), 0);
    expect(find.byKey(_winCell), findsNothing);
    expect(h.started, 0);
    expect(h.finished, 0);
  });

  testWidgets('la tarjeta tiene etiqueta accesible con su tamaño', (t) async {
    final handle = t.ensureSemantics();
    await _pump(t, await _redeem('RASPA-GANA'), _Harness());
    expect(find.bySemanticsLabel('Tarjeta de raspa y gana de 3 por 3 casillas'), findsOneWidget);
    handle.dispose();
  });

  testWidgets('el primer toque notifica el inicio, una sola vez', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await t.tapAt(_cardRect(t).center);
    await t.pump();
    expect(h.started, 1);
    await t.tapAt(_cardRect(t).center + const Offset(40, 40));
    await t.pump();
    expect(h.started, 1);
    expect(_progress(t), greaterThan(0), reason: 'un toque también raspa');
  });

  testWidgets('raspado parcial: avanza el progreso pero no revela ni termina', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await _scratchBands(t, fraction: 0.3);
    await t.pump(const Duration(seconds: 3));
    expect(_progress(t), inExclusiveRange(0, 1));
    expect(find.byKey(_winCell), findsNothing);
    expect(h.finished, 0);
  });

  testWidgets('CA-4: al superar el umbral se revela solo y termina con la grilla del servidor', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await _scratchBands(t);
    await _settle(t);

    expect(find.byKey(_winCell), findsNWidgets(3), reason: 'los 3 símbolos del premio se resaltan');
    expect(h.started, 1);
    expect(h.finished, 1);
    expect(h.feedback.wins, 1);
    expect(h.feedback.loses, 0);
  });

  testWidgets('sin premio: se revela, no se resalta nada y se notifica lose', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-NADA'), h);
    await _scratchBands(t);
    await _settle(t);
    expect(find.byKey(_winCell), findsNothing);
    expect(h.finished, 1);
    expect(h.feedback.wins, 0);
    expect(h.feedback.loses, 1);
  });

  testWidgets('"Revelar todo" funciona sin ningún gesto (alternativa accesible)', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await t.tap(find.text('Revelar todo'));
    await _settle(t);
    expect(h.started, 1);
    expect(h.finished, 1);
    expect(find.byKey(_winCell), findsNWidgets(3));
    expect(t.widget<OutlinedButton>(find.byType(OutlinedButton)).onPressed, isNull);
  });

  testWidgets('pulsar "Revelar todo" dos veces no termina dos veces', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await t.tap(find.text('Revelar todo'));
    await t.pump(const Duration(milliseconds: 100));
    await t.tap(find.text('Revelar todo'), warnIfMissed: false);
    await _settle(t);
    expect(h.started, 1);
    expect(h.finished, 1);
    expect(h.feedback.wins, 1);
  });

  testWidgets('tras revelar, raspar más ya no tiene efecto', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await t.tap(find.text('Revelar todo'));
    await _settle(t);
    await t.tapAt(_cardRect(t).center);
    await t.pump();
    expect(h.started, 1);
    expect(h.finished, 1);
  });

  testWidgets('da tics mientras se raspa', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('RASPA-GANA'), h);
    await _scratchBands(t, fraction: 0.4);
    expect(h.feedback.ticks, greaterThan(5));
    await t.pump(const Duration(seconds: 3));
  });

  group('reducir movimiento', () {
    testWidgets('revela de inmediato, sin fundido ni pulso, y termina igual', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RASPA-GANA'), h, reduceMotion: true);
      await t.tap(find.text('Revelar todo'));
      await t.pump();
      await t.pump(const Duration(milliseconds: 50));
      expect(find.byKey(_winCell), findsNWidgets(3), reason: 'sin el fundido de 450 ms');
      await t.pump(const Duration(seconds: 2));
      expect(h.finished, 1);
    });
  });

  group('robustez', () {
    testWidgets('si la tarjeta cambia de tamaño no se pierde el avance', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RASPA-GANA'), h);
      await _scratchBands(t, fraction: 0.3);
      await t.pump(); // reconstruye la barra de progreso con la cobertura nueva
      final before = _progress(t);
      expect(before, greaterThan(0));

      t.view.physicalSize = const Size(320, 560);
      await t.pump();
      await t.pump();
      expect(_progress(t), before);
      await t.pump(const Duration(seconds: 3));
    });

    testWidgets('se adapta a una pantalla pequeña sin desbordes ni scroll', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RASPA-GANA'), h, size: const Size(320, 480));
      expect(t.takeException(), isNull);
      expect(find.byType(Scrollable), findsNothing);
      final r = _cardRect(t);
      expect(r.width, lessThanOrEqualTo(320));
      expect(r.bottom, lessThan(480 - 90), reason: 'deja lugar al progreso y al botón');
    });

    testWidgets('desmontar durante el revelado no lanza ni notifica fin', (t) async {
      final h = _Harness();
      await _pump(t, await _redeem('RASPA-GANA'), h);
      await t.tap(find.text('Revelar todo'));
      await t.pump(const Duration(milliseconds: 200));
      await t.pumpWidget(const SizedBox());
      await t.pump(const Duration(seconds: 3));
      expect(h.finished, 0);
      expect(t.takeException(), isNull);
    });

    testWidgets('config inválida: muestra aviso y deja continuar al resultado', (t) async {
      final h = _Harness();
      final bad = Redemption(
        redemptionId: 'r',
        campaign: const Campaign(id: 'c', name: 'n', gameType: 'scratch'),
        outcome: const Outcome(
          prize: Prize(id: 'x', type: PrizeType.money, label: 'x', amount: 1),
        ),
      );
      await _pump(t, bad, h);
      expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
      expect(find.text('Revelar todo'), findsNothing);
      await t.tap(find.text('Continuar'));
      expect(h.finished, 1);
    });

    testWidgets('cuadrículas 2x2 y 5x5 se dibujan y se revelan', (t) async {
      for (final side in [2, 5]) {
        final h = _Harness();
        final r = Redemption(
          redemptionId: 'r$side',
          campaign: Campaign(id: 'c', name: 'n', gameType: 'scratch', gameConfig: {'rows': side, 'cols': side}),
          outcome: Outcome(
            prize: const Prize(id: 'p', type: PrizeType.money, label: 'x', amount: 5),
            gameResult: {
              'grid': [for (var i = 0; i < side * side; i++) i < 3 ? 'p' : 'star'],
            },
          ),
        );
        await _pump(t, r, h);
        await t.tap(find.text('Revelar todo'));
        await _settle(t);
        expect(t.takeException(), isNull, reason: '${side}x$side');
        expect(find.byKey(_winCell), findsNWidgets(3), reason: '${side}x$side');
        expect(h.finished, 1);
        await t.pumpWidget(const SizedBox());
      }
    });
  });
}
