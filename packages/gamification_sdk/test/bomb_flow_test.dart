import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_bomb/game_bomb.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';

class _Silent implements GameFeedback {
  @override
  void tick() {}
  @override
  void win() {}
  @override
  void lose() {}
}

Future<void> _pump(WidgetTester t, GamificationEvents events) async {
  t.view.physicalSize = const Size(800, 1600);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: GamificationFlow(
          repository: MockRepository(initialBalance: 120000),
          registry: GameRegistry()..register(BombModule(feedbackFactory: (_) => _Silent())),
          events: events,
        ),
      ),
    ),
  );
  await t.pumpAndSettle();
}

Future<void> _validate(WidgetTester t, String code) async {
  await t.enterText(find.byType(TextField), code);
  await t.pump();
  await t.tap(find.text('Validar código'));
  await t.pumpAndSettle();
}

Future<void> _advance(WidgetTester t, int ms) async {
  for (var i = 0; i < ms ~/ 100; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets('Bomba 8 real: código -> cartas en orden -> tercer 8 -> resultado con el premio y el saldo nuevo', (
    t,
  ) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, events);

    await _validate(t, 'BOMBA-GANA');
    expect(find.byKey(const ValueKey('bomb-board')), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo no adelanta el premio');

    // El jugador toca 11 cartas como quiera; el tercer 8 sale en el volteo número 11.
    for (final i in [11, 0, 7, 3, 9, 1, 5, 2, 10, 4, 6]) {
      await t.tap(find.byKey(ValueKey('bomb-card-$i')));
      await t.pump();
      await t.pump(const Duration(milliseconds: 400));
    }
    await _advance(t, 2500);
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 170.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('Bomba 8 sin premio: la mecha se acaba y el saldo no cambia', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, events);

    await _validate(t, 'BOMBA-NADA');
    await t.tap(find.byKey(const ValueKey('bomb-reveal-all')));
    await _advance(t, 3000);
    await t.pumpAndSettle();

    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget);
    expect(seen, isNot(contains(PrizeWonEvent)));
  });
}
