import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/src/flow/demo_codes.dart';
import 'package:gamification_sdk/src/flow/demo_codes_panel.dart';

Map<String, String> _many() => {
  for (var i = 1; i <= 9; i++) 'RULETA-W$i': 'Ruleta: \$ ${i * 10}.000',
  for (var i = 1; i <= 3; i++) 'RULETA-N$i': 'Ruleta: Sin premio',
  'BOMBA-A': 'Bomba 8: \$ 50.000',
  'BOMBA-B': 'Bomba 8: \$ 10.000',
  'BOMBA-C': 'Bomba 8: sin premio (la mecha se acaba)',
  'VENCIDO': 'Error: código vencido',
};

Future<List<String>> _pump(WidgetTester t, Map<String, String> codes) async {
  final picked = <String>[];
  t.view.physicalSize = const Size(800, 2400);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: DemoCodesPanel(
            codes: codes,
            onPick: picked.add,
            strings: const EsStrings(),
            theme: const GamificationTheme(),
          ),
        ),
      ),
    ),
  );
  return picked;
}

void main() {
  group('groupDemoCodes', () {
    test('un grupo por juego, en el orden en que aparecen, con y sin premio separados', () {
      final g = groupDemoCodes(_many());
      expect(g.map((e) => e.title), ['Ruleta', 'Bomba 8', 'Error']);
      expect((g[0].withPrize.length, g[0].withoutPrize.length), (9, 3));
      expect((g[1].withPrize.length, g[1].withoutPrize.length), (2, 1));
      expect(g[2].other.map((c) => c.code), ['VENCIDO'], reason: 'los errores simulados no tienen premio');
    });

    test('con premio va de menor a mayor monto y lo que no trae número queda al final', () {
      final g = groupDemoCodes({
        'X-1': 'Juego: \$ 50.000',
        'X-2': 'Juego: \$ 10.000',
        'X-3': 'Juego: cupón',
        'X-4': 'Juego: \$ 100.000',
      });
      expect(g.single.withPrize.map((c) => c.code), ['X-2', 'X-1', 'X-4', 'X-3']);
    });

    test('el monto sale del \$ o de «puntos», no de otros números de la descripción', () {
      expect(const DemoCode('A', 'Ruleta: gana \$ 50.000 (segmento 0)').amount, 50000);
      expect(const DemoCode('A', 'Ruleta: 250 puntos (segmento 3)').amount, 250);
      expect(const DemoCode('A', 'Ruleta: cupón (segmento 5)').amount, isNull);
    });

    test('«Sin premio» se reconoce en cualquier parte de la descripción y sin importar mayúsculas', () {
      expect(const DemoCode('A', 'Bomba 8: sin premio (la mecha se acaba)').isNoPrize, isTrue);
      expect(const DemoCode('A', 'Póker: carta alta, SIN PREMIO').isNoPrize, isTrue);
      expect(const DemoCode('A', 'Dados: dobles seis, gana \$ 50.000').isNoPrize, isFalse);
    });

    test('un código sin «Juego:» va a «Otros»', () {
      expect(groupDemoCodes({'ZZ': 'algo raro'}).single.title, 'Otros');
    });
  });

  group('DemoCodesPanel', () {
    testWidgets('con pocos códigos los muestra en una sola lista, sin bloques', (t) async {
      await _pump(t, {'RULETA-A': 'Ruleta: \$ 10.000', 'RULETA-B': 'Ruleta: Sin premio'});
      expect(find.text('RULETA-A'), findsOneWidget);
      expect(find.byType(ExpansionTile), findsNothing);
    });

    testWidgets('con muchos: bloques por juego, cerrados, con el conteo de cada tipo', (t) async {
      await _pump(t, _many());
      expect(find.byKey(const ValueKey('demo-group-Ruleta')), findsOneWidget);
      expect(find.byKey(const ValueKey('demo-group-Bomba 8')), findsOneWidget);
      expect(find.text('Con premio (9) · Sin premio (3)'), findsOneWidget);
      expect(find.text('Con premio (2) · Sin premio (1)'), findsOneWidget);
      expect(find.text('RULETA-W1'), findsNothing, reason: 'cerrado hasta que se abre el juego');
    });

    testWidgets('abrir un juego y un tipo muestra los primeros códigos (de menor a mayor) y deja ver el resto', (
      t,
    ) async {
      final picked = await _pump(t, _many());
      await t.tap(find.byKey(const ValueKey('demo-group-Ruleta')));
      await t.pumpAndSettle();
      expect(find.text('RULETA-N1'), findsNothing, reason: 'el otro tipo sigue cerrado');
      await t.tap(find.byKey(const ValueKey('demo-block-Ruleta-win')));
      await t.pumpAndSettle();
      for (final c in ['RULETA-W1', 'RULETA-W2', 'RULETA-W3', 'RULETA-W4']) {
        expect(find.text(c), findsOneWidget);
      }
      expect(find.text('RULETA-W5'), findsNothing, reason: 'solo los primeros 4');
      await t.tap(find.byKey(const ValueKey('demo-more-Ruleta-win')));
      await t.pumpAndSettle();
      expect(find.text('RULETA-W9'), findsOneWidget);
      expect(find.text('Ver menos'), findsOneWidget);
      await t.tap(find.text('RULETA-W9'));
      expect(picked, ['RULETA-W9']);
    });

    testWidgets('sin premio tiene su propio bloque y no mezcla premios', (t) async {
      final picked = await _pump(t, _many());
      await t.tap(find.byKey(const ValueKey('demo-group-Bomba 8')));
      await t.pumpAndSettle();
      await t.tap(find.byKey(const ValueKey('demo-block-Bomba 8-none')));
      await t.pumpAndSettle();
      expect(find.text('BOMBA-C'), findsOneWidget);
      expect(find.text('BOMBA-A'), findsNothing);
      await t.tap(find.text('BOMBA-C'));
      expect(picked, ['BOMBA-C']);
    });

    testWidgets('los errores simulados quedan en su bloque, sin con/sin premio', (t) async {
      final picked = await _pump(t, _many());
      await t.tap(find.byKey(const ValueKey('demo-group-Error')));
      await t.pumpAndSettle();
      expect(
        find.textContaining('Con premio'),
        findsNWidgets(2),
        reason: 'solo los subtítulos de Ruleta y Bomba, no de Error',
      );
      await t.tap(find.text('VENCIDO'));
      expect(picked, ['VENCIDO']);
    });
  });
}
