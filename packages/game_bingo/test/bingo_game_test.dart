import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_bingo/game_bingo.dart';
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
  Size size = const Size(400, 900),
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
            child: BingoGame(
              session: GameSession(
                redemption: r,
                preferences: const GamePreferences(),
                onStarted: () => h.started++,
                onFinished: () => h.finished++,
              ),
              feedbackFactory: (_) => h.feedback,
              interval: const Duration(milliseconds: 200),
              settleDelay: const Duration(milliseconds: 500),
            ),
          ),
        ),
      ),
    ),
  );
}

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

Future<void> _advance(WidgetTester t, int ms) async {
  for (var i = 0; i < ms ~/ 50; i++) {
    await t.pump(const Duration(milliseconds: 50));
  }
}

String _msg(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('bingo-message'))).data!;
String _drawn(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('bingo-drawn'))).data!;

void main() {
  testWidgets('estado inicial: cartón con el centro libre, EMPEZAR, tabla de premios y nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-GANA'), h);
    expect(find.byKey(const ValueKey('bingo-start')), findsOneWidget);
    expect(find.byKey(const ValueKey('bingo-next')), findsNothing);
    expect(find.byKey(const ValueKey('bingo-legend')), findsOneWidget);
    expect(find.text('Cuatro esquinas'), findsOneWidget);
    expect(find.text(r'$ 10.000'), findsOneWidget);
    expect(_drawn(t), 'Bolas: 0 de 24');
    expect(find.text('Toca Empezar: las bolas salen solas y se marcan en tu cartón'), findsOneWidget);
    expect((h.started, h.finished), (0, 0));
  });

  testWidgets('EMPEZAR: avisa el inicio una vez y las bolas salen solas, marcándose en el cartón', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-GANA'), h);
    await t.tap(find.byKey(const ValueKey('bingo-start')));
    await t.pump();
    expect(h.started, 1);
    await _advance(t, 450); // 2 bolas: 7 y 70
    expect(_drawn(t), 'Bolas: 2 de 24');
    expect(find.bySemanticsLabel('Bola B-7'), findsWidgets, reason: 'la bola 7 salió');
    expect(h.feedback.ticks, 2);
    expect(find.byKey(const ValueKey('bingo-next')), findsOneWidget);
  });

  testWidgets('con premio: avisa la línea en la bola 8 y sigue; al final dice qué ganó y resalta su fila', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-GANA'), h);
    await t.tap(find.byKey(const ValueKey('bingo-start')));
    await _advance(t, 8 * 200 + 50);
    expect(_msg(t), '¡Línea completa! Sigue jugando…');
    expect(h.finished, 0);
    await _advance(t, 16 * 200 + 100); // hasta la bola 24
    expect(_msg(t), '¡Bingo! Línea');
    expect(find.byKey(const ValueKey('bingo-legend-win')), findsOneWidget);
    expect(h.feedback.wins, 1);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('sin premio: no hay avisos y al terminar dice que no se completó ningún patrón', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-NADA'), h);
    await t.tap(find.byKey(const ValueKey('bingo-start')));
    await _advance(t, 24 * 200 + 100);
    expect(_msg(t), 'La partida terminó sin completar un patrón que pague');
    expect(find.byKey(const ValueKey('bingo-legend-win')), findsNothing);
    expect(h.feedback.loses, 1);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('"Siguiente bola" saca una a la vez y pausa el automático; "Automático" lo reanuda', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-NADA'), h);
    await t.tap(find.byKey(const ValueKey('bingo-start')));
    await t.pump();
    await t.tap(find.byKey(const ValueKey('bingo-next')));
    await t.pump();
    expect(_drawn(t), 'Bolas: 1 de 24');
    await _advance(t, 1000);
    expect(_drawn(t), 'Bolas: 1 de 24', reason: 'el toque pausó el automático');
    await t.tap(find.byKey(const ValueKey('bingo-auto')));
    await _advance(t, 450);
    expect(_drawn(t), 'Bolas: 3 de 24');
  });

  testWidgets('"Revelar todo" termina de inmediato con el mismo resultado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-GANA'), h);
    await t.tap(find.byKey(const ValueKey('bingo-reveal-all')));
    await t.pump();
    expect(h.started, 1);
    expect(_drawn(t), 'Bolas: 24 de 24');
    expect(_msg(t), '¡Bingo! Línea');
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('con "reducir movimiento" y en pantalla chica no hay errores ni desbordes', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('BINGO-GANA'), h, reduceMotion: true, size: const Size(360, 640));
    await t.ensureVisible(find.byKey(const ValueKey('bingo-reveal-all'))); // en pantalla chica se desplaza
    await t.tap(find.byKey(const ValueKey('bingo-reveal-all')));
    await t.pump();
    expect(t.takeException(), isNull);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('si el servidor manda algo que contradice el premio, muestra el aviso y deja continuar', (t) async {
    final h = _Harness();
    final r = await _redeem('BINGO-GANA');
    final bad = Redemption(
      redemptionId: 'r',
      campaign: r.campaign,
      outcome: Outcome(
        prize: r.outcome.prize,
        gameResult: {...r.outcome.gameResult, 'winAt': null, 'pattern': null, 'cells': null},
      ),
    );
    await _pump(t, bad, h);
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
    await t.tap(find.text('Continuar'));
    expect(h.finished, 1);
  });
}
