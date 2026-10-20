import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_roulette/game_roulette.dart';
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

/// Flujo real con la ruleta real (solo el sonido/háptico está simulado).
Future<void> _pump(WidgetTester t, MockRepository repo, GamificationEvents events) async {
  t.view.physicalSize = const Size(800, 1600);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(MaterialApp(
    home: Scaffold(
      body: GamificationFlow(
        repository: repo,
        registry: GameRegistry()..register(RouletteModule(feedbackFactory: (_) => _Silent())),
        events: events,
      ),
    ),
  ));
  await t.pumpAndSettle();
}

Future<void> _play(WidgetTester t, String code) async {
  await t.enterText(find.byType(TextField), code);
  await t.pump();
  await t.tap(find.text('Validar código'));
  await t.pumpAndSettle();
  await t.tap(find.text('GIRAR'));
  await t.pumpAndSettle();
  await t.pump(const Duration(seconds: 1)); // pausa final con el segmento resaltado
  await t.pumpAndSettle();
}

void main() {
  testWidgets('ruleta real: código -> giro -> resultado con el premio del servidor y saldo nuevo', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, MockRepository(initialBalance: 120000), events);

    await t.enterText(find.byType(TextField), 'RULETA-50K');
    await t.pump();
    await t.tap(find.text('Validar código'));
    await t.pumpAndSettle();

    // La ruleta muestra los 8 segmentos de la campaña y todavía no se movió.
    final painter = t
        .widgetList<CustomPaint>(find.byType(CustomPaint))
        .map((c) => c.painter)
        .whereType<RoulettePainter>()
        .single;
    expect(painter.segments, hasLength(8));
    expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo no adelanta el premio');

    await t.tap(find.text('GIRAR'));
    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 1));
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 50.000'), findsWidgets);
    expect(find.text(r'$ 170.000'), findsOneWidget);
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('segmento sin premio termina en la pantalla "sin suerte"', (t) async {
    await _pump(t, MockRepository(), GamificationEvents());
    await _play(t, 'RULETA-NADA');
    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
  });

  testWidgets('cada tipo de premio de la ruleta llega al resultado correcto', (t) async {
    const cases = {
      'RULETA-PUNTOS': '+250 puntos',
      'RULETA-CUPON': 'Cupón 20%',
      'RULETA-EXTRA': 'Gira de nuevo',
    };
    for (final entry in cases.entries) {
      await _pump(t, MockRepository(), GamificationEvents());
      await _play(t, entry.key);
      expect(find.text(entry.value), findsWidgets, reason: entry.key);
      await t.pumpWidget(const SizedBox());
    }
  });

  testWidgets('cerrar queda bloqueado mientras gira y vuelve al terminar', (t) async {
    var closed = 0;
    t.view.physicalSize = const Size(800, 1600);
    t.view.devicePixelRatio = 2;
    addTearDown(t.view.reset);
    await t.pumpWidget(MaterialApp(
      home: Scaffold(
        body: GamificationFlow(
          repository: MockRepository(),
          registry: GameRegistry()..register(RouletteModule(feedbackFactory: (_) => _Silent())),
          events: GamificationEvents(),
          onClose: () => closed++,
        ),
      ),
    ));
    await t.pumpAndSettle();
    await t.enterText(find.byType(TextField), 'RULETA-50K');
    await t.pump();
    await t.tap(find.text('Validar código'));
    await t.pumpAndSettle();

    expect(find.byTooltip('Cerrar'), findsOneWidget);
    await t.tap(find.text('GIRAR'));
    await t.pump(const Duration(seconds: 1));
    expect(find.byTooltip('Cerrar'), findsNothing, reason: 'girando: el premio ya está asegurado');
    expect(find.text('Tu premio ya está asegurado'), findsOneWidget);

    await t.pumpAndSettle();
    await t.pump(const Duration(seconds: 1));
    await t.pumpAndSettle();
    expect(find.byTooltip('Cerrar'), findsOneWidget);
    expect(closed, 0);
  });
}
