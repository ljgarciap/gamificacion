import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_dice/game_dice.dart';
import 'package:game_memory/game_memory.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
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

/// CA-8: el núcleo no conoce los juegos; los cuatro conviven en el mismo registro.
GameRegistry _registry() => GameRegistry()
  ..register(RouletteModule(feedbackFactory: (_) => _Silent()))
  ..register(ScratchModule(feedbackFactory: (_) => _Silent()))
  ..register(MemoryModule(feedbackFactory: (_) => _Silent()))
  ..register(DiceModule(feedbackFactory: (_) => _Silent()));

Future<void> _pump(WidgetTester t, MockRepository repo, GamificationEvents events) async {
  t.view.physicalSize = const Size(800, 1600);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: GamificationFlow(repository: repo, registry: _registry(), events: events),
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
  testWidgets('Concéntrese real: código -> parejas -> resultado con el premio y el saldo nuevo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, MockRepository(initialBalance: 120000), events);

    await _validate(t, 'CONCENTRESE-GANA');
    expect(find.byKey(const ValueKey('memory-board')), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo no adelanta el premio');
    expect(find.text('Tu premio ya está asegurado'), findsNothing, reason: 'aún no empezó');

    // Una pareja de relleno (0 y 9) y luego la pareja del premio (1 y 7), tocando de verdad las cartas.
    for (final i in [0, 9, 1, 7]) {
      await t.tap(find.byKey(ValueKey('memory-card-$i')));
      await t.pump();
      await t.pump(const Duration(milliseconds: 400));
      if (i == 0) expect(find.text('Tu premio ya está asegurado'), findsOneWidget);
    }
    await _advance(t, 2000);
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 10.000'), findsWidgets);
    expect(find.text(r'$ 130.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('Concéntrese sin premio: "Revelar todo" lleva al resultado sin cambiar el saldo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, MockRepository(initialBalance: 120000), events);

    await _validate(t, 'CONCENTRESE-NADA');
    await t.tap(find.byKey(const ValueKey('memory-reveal-all')));
    await _advance(t, 2000);
    await t.pumpAndSettle();

    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget);
    expect(seen, isNot(contains(PrizeWonEvent)));
  });

  testWidgets('Dados real: código -> tirar -> resultado con el premio y el saldo nuevo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, MockRepository(initialBalance: 120000), events);

    await _validate(t, 'DADOS-GANA');
    expect(find.byKey(const ValueKey('dice-roll-button')), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('dice-roll-button')));
    await t.pump();
    expect(find.text('Tu premio ya está asegurado'), findsOneWidget, reason: 'el canje ya ocurrió antes de tirar');
    await _advance(t, 4000);
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 50.000'), findsWidgets);
    expect(find.text(r'$ 170.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('Dados sin premio termina en el resultado "sin suerte"', (t) async {
    await _pump(t, MockRepository(), GamificationEvents());
    await _validate(t, 'DADOS-NADA');
    await t.tap(find.byKey(const ValueKey('dice-roll-button')));
    await _advance(t, 4000);
    await t.pumpAndSettle();
    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
  });

  testWidgets('los códigos nuevos figuran en los códigos de prueba del modo simulado', (t) async {
    for (final code in ['CONCENTRESE-GANA', 'CONCENTRESE-NADA', 'DADOS-GANA', 'DADOS-NADA']) {
      expect(MockRepository.demoCodes, contains(code));
    }
  });
}
