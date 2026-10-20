import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';

class _Game implements GameModule {
  @override
  String get id => 'roulette';
  @override
  Widget build(BuildContext context, GameSession session) =>
      ElevatedButton(onPressed: session.onFinished, child: const Text('terminar'));
}

Future<void> _pump(WidgetTester t, {Future<Map<String, String>> Function()? loader, Map<String, String>? fixed}) async {
  t.view.physicalSize = const Size(800, 1600);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: GamificationFlow(
          repository: MockRepository(),
          registry: GameRegistry()..register(_Game()),
          events: GamificationEvents(),
          demoCodesLoader: loader,
          demoCodes: fixed,
        ),
      ),
    ),
  );
  await t.pumpAndSettle();
}

void main() {
  testWidgets('carga los códigos al aparecer la pantalla y los muestra', (t) async {
    var calls = 0;
    await _pump(
      t,
      loader: () async {
        calls++;
        return {'RULETA-AAAA-BBBB-CCC': 'Ruleta: \$ 50.000'};
      },
    );
    expect(calls, 1);
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    expect(find.text('RULETA-AAAA-BBBB-CCC'), findsOneWidget);
    expect(find.text('Ruleta: \$ 50.000'), findsOneWidget);
  });

  testWidgets('se vuelven a pedir al regresar de un resultado (los ya usados desaparecen)', (t) async {
    var calls = 0;
    await _pump(
      t,
      loader: () async {
        calls++;
        return calls == 1 ? {'RULETA-50K': 'uno', 'RULETA-NADA': 'dos'} : {'RULETA-NADA': 'dos'};
      },
    );
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    expect(find.text('RULETA-50K'), findsOneWidget);

    await t.enterText(find.byType(TextField), 'RULETA-50K');
    await t.pump();
    await t.tap(find.text('Validar código'));
    await t.pumpAndSettle();
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.text('Continuar'));
    await t.pumpAndSettle();

    expect(calls, 2, reason: 'se recargó al volver a la pantalla de código');
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    expect(find.text('RULETA-50K'), findsNothing, reason: 'el código usado ya no aparece');
    expect(find.text('RULETA-NADA'), findsOneWidget);
  });

  testWidgets('un error al cargar no rompe nada y no muestra el panel', (t) async {
    await _pump(t, loader: () async => throw StateError('sin backend'));
    expect(find.text('Códigos de prueba'), findsNothing);
    expect(find.text('Validar código'), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('una lista vacía no muestra el panel', (t) async {
    await _pump(t, loader: () async => {});
    expect(find.text('Códigos de prueba'), findsNothing);
  });

  testWidgets('mientras carga no hay panel y al terminar aparece', (t) async {
    final completer = Completer<Map<String, String>>();
    await _pump(t, loader: () => completer.future);
    expect(find.text('Códigos de prueba'), findsNothing);
    completer.complete({'A-1': 'x'});
    await t.pumpAndSettle();
    expect(find.text('Códigos de prueba'), findsOneWidget);
  });

  testWidgets('si se desmonta antes de que termine la carga, no lanza', (t) async {
    final completer = Completer<Map<String, String>>();
    await _pump(t, loader: () => completer.future);
    await t.pumpWidget(const SizedBox());
    completer.complete({'A-1': 'x'});
    await t.pump();
    expect(t.takeException(), isNull);
  });

  testWidgets('sin cargador se usa el mapa fijo', (t) async {
    await _pump(t, fixed: {'FIJO-1': 'desc'});
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    expect(find.text('FIJO-1'), findsOneWidget);
  });

  testWidgets('el cargador manda sobre el mapa fijo', (t) async {
    await _pump(t, fixed: {'FIJO-1': 'desc'}, loader: () async => {'CARGADO-1': 'otra'});
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    expect(find.text('CARGADO-1'), findsOneWidget);
    expect(find.text('FIJO-1'), findsNothing);
  });
}
