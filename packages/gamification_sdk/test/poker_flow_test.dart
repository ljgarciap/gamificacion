import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_poker/game_poker.dart';
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
  t.view.physicalSize = const Size(800, 1800);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: GamificationFlow(
          repository: MockRepository(initialBalance: 120000),
          registry: GameRegistry()..register(PokerModule(feedbackFactory: (_) => _Silent())),
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
  testWidgets('Póker real: código -> mesa -> full -> resultado con el premio y el saldo nuevo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, events);

    await _validate(t, 'POKER-GANA');
    expect(find.byKey(const ValueKey('poker-board')), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo no adelanta el premio');

    await t.tap(find.byKey(const ValueKey('poker-reveal-all')));
    await _advance(t, 3000);
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 170.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('Póker sin premio: el saldo no cambia', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, events);

    await _validate(t, 'POKER-NADA');
    await t.tap(find.byKey(const ValueKey('poker-reveal-all')));
    await _advance(t, 3000);
    await t.pumpAndSettle();

    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget);
    expect(seen, isNot(contains(PrizeWonEvent)));
  });
}
