import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_poker/game_poker.dart';
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
  Size size = const Size(400, 1000),
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
            child: PokerGame(
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

String _msg(WidgetTester t) => t.widget<Text>(find.byKey(const ValueKey('poker-message'))).data!;

void main() {
  testWidgets('estado inicial: tus cartas a la vista, la mesa tapada, ABRIR, tabla de premios y nada notificado', (
    t,
  ) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h);
    expect(find.bySemanticsLabel('Rey de corazones'), findsOneWidget);
    expect(find.bySemanticsLabel('Rey de diamantes'), findsOneWidget);
    expect(find.bySemanticsLabel('La mesa'), findsOneWidget);
    expect(find.bySemanticsLabel('Carta 1 de 5, boca abajo'), findsOneWidget);
    expect(find.bySemanticsLabel('Carta 5 de 5, boca abajo'), findsOneWidget);
    expect(find.text('Rey de picas'), findsNothing);
    expect(find.byKey(const ValueKey('poker-start')), findsOneWidget);
    expect(find.byKey(const ValueKey('poker-next')), findsNothing);
    expect(find.text('Full'), findsOneWidget);
    expect(find.text(r'$ 50.000'), findsOneWidget);
    expect(_msg(t), 'Tus cartas están listas. Toca ABRIR y el crupier abre las de la mesa');
    expect((h.started, h.finished), (0, 0));
  });

  testWidgets('ABRIR: avisa el inicio una vez; el crupier abre el flop y se ve la mejor mano que se tiene', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h);
    await t.tap(find.byKey(const ValueKey('poker-start')));
    await t.pump();
    expect(h.started, 1);
    await _advance(t, 250);
    expect(_msg(t), 'Tienes: Trío', reason: 'tras el flop ya hay trío de reyes');
    expect(find.bySemanticsLabel('Dos de picas'), findsOneWidget);
    expect(find.bySemanticsLabel('Dos de tréboles'), findsNothing, reason: 'el turn todavía no se abrió');
  });

  testWidgets('con premio: tras el river dice Full, resalta la mano y la fila del premio, y termina', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h);
    await t.tap(find.byKey(const ValueKey('poker-start')));
    await _advance(t, 3 * 200 + 100);
    expect(_msg(t), '¡Full! \$ 50.000');
    expect(find.byKey(const ValueKey('poker-legend-win')), findsOneWidget);
    // Las 5 cartas de la mejor mano quedan resaltadas (KH KD KC 2S 2C); las otras dos, atenuadas.
    expect(find.byKey(const ValueKey('poker-board-0-best')), findsOneWidget); // 2S
    expect(find.byKey(const ValueKey('poker-hole-0-best')), findsOneWidget); // KH
    expect(find.byKey(const ValueKey('poker-board-1-best')), findsNothing); // 9D no es de la mano
    expect(h.feedback.wins, 1);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('sin premio: dice cuál fue su mejor mano y que no paga', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-NADA'), h);
    await t.tap(find.byKey(const ValueKey('poker-start')));
    await _advance(t, 3 * 200 + 100);
    expect(_msg(t), 'Tu mejor mano: Carta alta. No paga premio');
    expect(find.byKey(const ValueKey('poker-legend-win')), findsNothing);
    expect(h.feedback.loses, 1);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('"Abrir siguiente" abre una etapa por toque y pausa el automático; "Automático" lo reanuda', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h);
    await t.tap(find.byKey(const ValueKey('poker-start')));
    await t.pump();
    await t.tap(find.byKey(const ValueKey('poker-next')));
    await t.pump();
    expect(_msg(t), 'Tienes: Trío');
    await _advance(t, 1000);
    expect(_msg(t), 'Tienes: Trío', reason: 'el toque pausó el automático');
    await t.tap(find.byKey(const ValueKey('poker-auto')));
    await _advance(t, 450);
    expect(_msg(t), '¡Full! \$ 50.000');
  });

  testWidgets('"Revelar todo" abre todo de inmediato con el mismo resultado', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h);
    await t.tap(find.byKey(const ValueKey('poker-reveal-all')));
    await t.pump();
    expect(h.started, 1);
    expect(_msg(t), '¡Full! \$ 50.000');
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('con "reducir movimiento" y en pantalla chica no hay errores ni desbordes', (t) async {
    final h = _Harness();
    await _pump(t, await _redeem('POKER-GANA'), h, reduceMotion: true, size: const Size(360, 640));
    await t.ensureVisible(find.byKey(const ValueKey('poker-reveal-all')));
    await t.tap(find.byKey(const ValueKey('poker-reveal-all')));
    await t.pump();
    expect(t.takeException(), isNull);
    await _advance(t, 700);
    expect(h.finished, 1);
  });

  testWidgets('si el servidor manda algo que contradice el premio, muestra el aviso y deja continuar', (t) async {
    final h = _Harness();
    final r = await _redeem('POKER-GANA');
    final bad = Redemption(
      redemptionId: 'r',
      campaign: r.campaign,
      outcome: Outcome(prize: r.outcome.prize, gameResult: {...r.outcome.gameResult, 'hand': null}),
    );
    await _pump(t, bad, h);
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
    await t.tap(find.text('Continuar'));
    expect(h.finished, 1);
  });
}
