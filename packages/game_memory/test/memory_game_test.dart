import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_memory/game_memory.dart';
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
            child: MemoryGame(
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
    ),
  );
}

Future<Redemption> _redeem(String code) => MockRepository().redeem(code, idempotencyKey: 'k-$code');

Finder _card(int i) => find.byKey(ValueKey('memory-card-$i'));

/// Pasos cortos: los temporizadores (volteo, pausa final) se crean cuando ocurre cada evento.
Future<void> _advance(WidgetTester t, [int ms = 1500]) async {
  for (var i = 0; i < ms ~/ 100; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

/// Un `pump()` para que la carta se reconstruya (ahí arranca su giro) y otro para avanzar el tiempo de la animación.
Future<void> _tap(WidgetTester t, int i) async {
  await t.tap(_card(i));
  await t.pump();
  await t.pump(const Duration(milliseconds: 400));
}

String _progress(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('memory-progress'))).data!;

// Tablero de CONCENTRESE-GANA: ['star','p10k','moon','sun','coin','gem','moon','p10k','sun','star','gem','coin']
// La pareja del premio está en 1 y 7. Pares de relleno: (0,9) (2,6) (3,8) (4,11) (5,10).
// Tablero de CONCENTRESE-NADA: la misma disposición con 'bell' en lugar del premio (1 y 7).

void main() {
  testWidgets('estado inicial: 12 cartas tapadas, progreso en 0 y nada notificado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    for (var i = 0; i < 12; i++) {
      expect(_card(i), findsOneWidget);
    }
    expect(find.text('Encuentra las parejas'), findsOneWidget);
    expect(_progress(t), 'Parejas: 0 de 6');
    expect(find.text('Revelar todo'), findsOneWidget);
    expect(find.byKey(const ValueKey('memory-prize-card')), findsNothing);
    expect((h.started, h.finished), (0, 0));
  });

  testWidgets('el primer toque notifica el inicio, una sola vez', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await _tap(t, 0);
    await _tap(t, 9);
    expect(h.started, 1);
    expect(h.feedback.ticks, 2);
  });

  testWidgets('una pareja de relleno se queda destapada y suma al progreso, sin terminar', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await _tap(t, 0);
    await _tap(t, 9); // star + star
    expect(_progress(t), 'Parejas: 1 de 6');
    await _advance(t);
    expect(h.finished, 0, reason: 'una pareja que no es la del premio no termina el juego');
    expect(h.feedback.wins, 0);
  });

  testWidgets('dos cartas distintas se ven y se vuelven a tapar; mientras tanto se ignoran los toques', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await _tap(t, 0); // star
    await _tap(t, 2); // moon -> no coinciden
    await _tap(t, 3); // ignorado mientras se resuelve
    expect(h.feedback.ticks, 2, reason: 'el toque durante la resolución no cuenta');
    await _advance(t); // pasa flipBack (900 ms)
    expect(_progress(t), 'Parejas: 0 de 6');
    await _tap(t, 0);
    await _tap(t, 9); // ahora sí cuenta: la carta 0 volvió a estar tapada
    expect(_progress(t), 'Parejas: 1 de 6');
  });

  testWidgets('hallar la pareja del premio termina el juego, resalta y celebra', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await _tap(t, 1);
    await _tap(t, 7); // p10k + p10k
    expect(find.byKey(const ValueKey('memory-prize-card')), findsNWidgets(2));
    expect(find.text(r'$ 10.000'), findsWidgets);
    expect(h.feedback.wins, 1);
    expect(h.finished, 0, reason: 'primero la pausa con lo ganador resaltado');
    await _advance(t);
    expect(h.finished, 1);
  });

  testWidgets('sin premio: termina al hallar todas las parejas y no hay nada resaltado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-NADA'), h);
    for (final pair in [
      [0, 9],
      [1, 7],
      [2, 6],
      [3, 8],
      [4, 11],
    ]) {
      await _tap(t, pair[0]);
      await _tap(t, pair[1]);
      expect(h.finished, 0);
    }
    await _tap(t, 5);
    await _tap(t, 10);
    expect(_progress(t), 'Parejas: 6 de 6');
    expect(find.byKey(const ValueKey('memory-prize-card')), findsNothing);
    expect(h.feedback.loses, 1);
    await _advance(t);
    expect(h.finished, 1);
  });

  testWidgets('"Revelar todo" destapa el tablero, resalta el premio y termina', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await t.tap(find.byKey(const ValueKey('memory-reveal-all')));
    await t.pump();
    await t.pump(const Duration(milliseconds: 400));
    expect(h.started, 1);
    expect(_progress(t), 'Parejas: 6 de 6');
    expect(find.byKey(const ValueKey('memory-prize-card')), findsNWidgets(2));
    expect(h.feedback.wins, 1);
    await _advance(t);
    expect(h.finished, 1);
    final button = t.widget<OutlinedButton>(find.byKey(const ValueKey('memory-reveal-all')));
    expect(button.onPressed, isNull, reason: 'ya terminó');
  });

  testWidgets('terminar es una sola vez aunque se toque más después', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await _tap(t, 1);
    await _tap(t, 7);
    await _tap(t, 0);
    await _tap(t, 9);
    await _advance(t, 3000);
    expect(h.finished, 1);
    expect(h.feedback.wins, 1);
  });

  testWidgets('con "reducir movimiento" las cartas cambian al instante', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h, reduceMotion: true);
    await t.tap(_card(1));
    await t.pump(); // un solo frame, sin avanzar el tiempo
    await t.tap(_card(7));
    await t.pump();
    expect(find.byKey(const ValueKey('memory-prize-card')), findsNWidgets(2));
  });

  testWidgets('cada carta es un botón accesible con su posición y estado', (t) async {
    final handle = t.ensureSemantics();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), _Harness());
    expect(find.bySemanticsLabel('Tablero de Concéntrese con 6 parejas'), findsOneWidget);
    expect(find.bySemanticsLabel('Carta 1 de 12, boca abajo'), findsOneWidget);
    await _tap(t, 0);
    expect(find.bySemanticsLabel('Carta 1 de 12: star'), findsOneWidget);
    await _tap(t, 9);
    await _tap(t, 1);
    await _tap(t, 7);
    expect(find.bySemanticsLabel(RegExp(r'Carta 2 de 12: .*10\.000')), findsOneWidget);
    expect(find.bySemanticsLabel('Parejas: 2 de 6'), findsOneWidget);
    handle.dispose();
  });

  testWidgets('el teclado también voltea cartas (Enter sobre la carta con foco)', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('CONCENTRESE-GANA'), h);
    await t.sendKeyEvent(LogicalKeyboardKey.tab);
    await t.pump();
    await t.sendKeyEvent(LogicalKeyboardKey.enter);
    await t.pump(const Duration(milliseconds: 400));
    expect(h.started, 1, reason: 'la primera carta con foco se voltea con Enter');
  });

  testWidgets('configuración inválida: avisa y deja continuar al resultado', (t) async {
    final h = _Harness();
    final bad = Redemption(
      redemptionId: 'r',
      campaign: Campaign(id: 'c', name: 'x', gameType: 'memory', gameConfig: const {'pairs': 99}),
      outcome: const Outcome(
        prize: Prize(id: 'none', type: PrizeType.none, label: 'Sin premio'),
        gameResult: {'cards': <String>[]},
      ),
    );
    await _pump(t, bad, h);
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
    await t.tap(find.text('Continuar'));
    expect(h.finished, 1);
  });

  testWidgets('cabe sin desbordar en un viewport pequeño', (t) async {
    await _pump(t, await _redeem('CONCENTRESE-GANA'), _Harness(), size: const Size(320, 480));
    expect(t.takeException(), isNull);
    final board = t.getRect(find.byKey(const ValueKey('memory-board')));
    expect(board.right, lessThanOrEqualTo(320));
    expect(board.bottom, lessThanOrEqualTo(480));
  });
}
