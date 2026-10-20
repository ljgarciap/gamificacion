import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
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

/// CA-8: el núcleo no conoce los juegos; ruleta y raspa conviven en el mismo registro.
GameRegistry _registry() => GameRegistry()
  ..register(RouletteModule(feedbackFactory: (_) => _Silent()))
  ..register(ScratchModule(feedbackFactory: (_) => _Silent()));

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

/// Revela y deja correr el fundido, el pulso y la pausa final.
Future<void> _revealAll(WidgetTester t) async {
  await t.tap(find.text('Revelar todo'));
  for (var i = 0; i < 40; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
  await t.pumpAndSettle();
}

void main() {
  testWidgets('raspa real: código -> raspar -> resultado con el premio y el saldo nuevo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, MockRepository(initialBalance: 120000), events);

    await _validate(t, 'RASPA-GANA');
    expect(find.byKey(const ValueKey('scratch-card')), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo no adelanta el premio');

    // Raspado real con el dedo, sin "Revelar todo": barridos en bandas sobre la tarjeta.
    final rect = t.getRect(find.byKey(const ValueKey('scratch-card')));
    final g = await t.startGesture(rect.topLeft + const Offset(6, 14));
    for (var y = 14.0; y < rect.height; y += 18) {
      await g.moveTo(Offset(rect.left + 6, rect.top + y));
      await g.moveTo(Offset(rect.right - 6, rect.top + y));
    }
    await g.up();
    for (var i = 0; i < 40; i++) {
      await t.pump(const Duration(milliseconds: 100));
    }
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 10.000'), findsWidgets);
    expect(find.text(r'$ 130.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('raspa sin premio termina en la pantalla "sin suerte"', (t) async {
    await _pump(t, MockRepository(), GamificationEvents());
    await _validate(t, 'RASPA-NADA');
    await _revealAll(t);
    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
  });

  testWidgets('ruleta y raspa en la misma sesión: cada código abre su juego', (t) async {
    await _pump(t, MockRepository(), GamificationEvents());

    await _validate(t, 'RASPA-GANA');
    expect(find.byKey(const ValueKey('scratch-card')), findsOneWidget);
    expect(find.text('GIRAR'), findsNothing);
    await _revealAll(t);
    await t.tap(find.text('Continuar'));
    await t.pumpAndSettle();

    await _validate(t, 'RULETA-50K');
    expect(find.text('GIRAR'), findsOneWidget);
    expect(find.byKey(const ValueKey('scratch-card')), findsNothing);
  });

  testWidgets('cerrar queda bloqueado mientras se raspa y vuelve al terminar', (t) async {
    t.view.physicalSize = const Size(800, 1600);
    t.view.devicePixelRatio = 2;
    addTearDown(t.view.reset);
    await t.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: GamificationFlow(
            repository: MockRepository(),
            registry: _registry(),
            events: GamificationEvents(),
            onClose: () {},
          ),
        ),
      ),
    );
    await t.pumpAndSettle();
    await _validate(t, 'RASPA-GANA');
    expect(find.byTooltip('Cerrar'), findsOneWidget);

    await t.tapAt(t.getRect(find.byKey(const ValueKey('scratch-card'))).center);
    await t.pump();
    expect(find.byTooltip('Cerrar'), findsNothing);
    expect(find.text('Tu premio ya está asegurado'), findsOneWidget);

    await _revealAll(t);
    expect(find.byTooltip('Cerrar'), findsOneWidget);
  });
}
