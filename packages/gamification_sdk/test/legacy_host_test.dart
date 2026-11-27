// Anfitriones que embeben el SDK sin los textos de `material_ui` (SPEC-008): los que siguen con el Material antiguo del SDK de Flutter o
// no usan Material. Importar la librería deprecada es justamente lo que se prueba.
// ignore_for_file: deprecated_member_use
import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart' as legacy;
import 'package:flutter_test/flutter_test.dart';
import 'package:game_bingo/game_bingo.dart';
import 'package:game_bomb/game_bomb.dart';
import 'package:game_dice/game_dice.dart';
import 'package:game_memory/game_memory.dart';
import 'package:game_poker/game_poker.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';
import 'package:material_ui/material_ui.dart';

class _Backend implements DemoBackend {
  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

/// Textos propios de un anfitrión con `material_ui` (p. ej. traducidos).
class _HostTexts extends DefaultMaterialLocalizations {
  const _HostTexts();
}

class _HostTextsDelegate extends LocalizationsDelegate<MaterialLocalizations> {
  const _HostTextsDelegate();

  @override
  bool isSupported(Locale locale) => true;

  @override
  Future<MaterialLocalizations> load(Locale locale) => SynchronousFuture(const _HostTexts());

  @override
  bool shouldReload(_HostTextsDelegate old) => false;
}

Widget _flow() => GamificationFlow(
  repository: MockRepository(),
  registry: GameRegistry(),
  events: GamificationEvents(),
  helpText: 'Texto de ayuda',
  onClose: () {},
);

Widget _journey() => DemoJourney(
  backend: _Backend(),
  repository: MockRepository(),
  registry: GameRegistry(),
  events: GamificationEvents(),
);

final _hosts = <String, Widget Function(Widget)>{
  'con material_ui (control)': (child) => MaterialApp(home: child),
  'Material antiguo': (child) => legacy.MaterialApp(home: child),
  'sin Material, en español': (child) => WidgetsApp(
    color: const Color(0xFF000000),
    locale: const Locale('es'),
    supportedLocales: const [Locale('es')],
    pageRouteBuilder: <T>(RouteSettings settings, WidgetBuilder builder) =>
        PageRouteBuilder<T>(settings: settings, pageBuilder: (context, _, _) => builder(context)),
    home: child,
  ),
};

Future<void> _openMenu(WidgetTester t) async {
  final field = find.byType(TextField).first;
  await t.enterText(field, 'abc@ejemplo.com');
  await t.pumpAndSettle();
  final at = t.getTopLeft(find.byType(EditableText).first) + const Offset(12, 8);
  switch (defaultTargetPlatform) {
    // En escritorio el menú se abre con el botón secundario; en móvil, con una pulsación larga.
    case TargetPlatform.linux || TargetPlatform.macOS || TargetPlatform.windows:
      await t.tapAt(at, buttons: kSecondaryButton);
    case TargetPlatform.android || TargetPlatform.fuchsia || TargetPlatform.iOS:
      await t.longPressAt(at);
  }
  await t.pumpAndSettle();
}

void main() {
  for (final host in _hosts.entries) {
    group('anfitrión ${host.key}', () {
      testWidgets('el flujo abre, muestra la ayuda y la cierra', (t) async {
        await t.pumpWidget(host.value(_flow()));
        await t.pumpAndSettle();
        expect(t.takeException(), isNull);

        await t.tap(find.text('¿Dónde encuentro mi código?'));
        await t.pumpAndSettle();
        expect(t.takeException(), isNull);
        expect(find.text('Texto de ayuda'), findsOneWidget);

        await t.tap(find.text('Cerrar'));
        await t.pumpAndSettle();
        expect(t.takeException(), isNull);
        expect(find.text('Texto de ayuda'), findsNothing);
      });

      testWidgets('la ayuda también se cierra al tocar fuera', (t) async {
        await t.pumpWidget(host.value(_flow()));
        await t.pumpAndSettle();
        await t.tap(find.text('¿Dónde encuentro mi código?'));
        await t.pumpAndSettle();
        await t.tapAt(const Offset(5, 5));
        await t.pumpAndSettle();
        expect(t.takeException(), isNull);
        expect(find.text('Texto de ayuda'), findsNothing);
      });

      final games = <String, GameModule>{
        'RULETA-50K': RouletteModule(),
        'RASPA-GANA': ScratchModule(),
        'CONCENTRESE-GANA': MemoryModule(),
        'DADOS-GANA': DiceModule(),
        'BOMBA-GANA': BombModule(),
        'BINGO-GANA': BingoModule(),
        'POKER-GANA': PokerModule(),
      };
      for (final game in games.entries) {
        testWidgets('con el código ${game.key} el juego se abre sin errores', (t) async {
          t.view.physicalSize = const Size(400, 1000);
          t.view.devicePixelRatio = 1;
          addTearDown(t.view.reset);
          await t.pumpWidget(
            host.value(
              GamificationFlow(
                repository: MockRepository(),
                registry: GameRegistry()..register(game.value),
                events: GamificationEvents(),
                initialCode: game.key,
              ),
            ),
          );
          for (var i = 0; i < 20; i++) {
            await t.pump(const Duration(milliseconds: 100));
          }
          expect(t.takeException(), isNull);
          expect(find.byType(TextField), findsNothing, reason: 'ya salió de la pantalla del código');
          await t.pumpWidget(const SizedBox());
          await t.pump(const Duration(seconds: 5));
        });
      }

      testWidgets('el botón cerrar muestra su ayuda emergente', (t) async {
        await t.pumpWidget(host.value(_flow()));
        await t.pumpAndSettle();
        await t.longPress(find.byTooltip('Cerrar'));
        await t.pumpAndSettle();
        expect(t.takeException(), isNull);
      });

      for (final entry in {'el flujo': _flow, 'la muestra': _journey}.entries) {
        testWidgets('en ${entry.key} se abre el menú de selección de texto', (t) async {
          await t.pumpWidget(host.value(entry.value()));
          await t.pumpAndSettle();
          await _openMenu(t);
          expect(t.takeException(), isNull);
          expect(find.byType(AdaptiveTextSelectionToolbar), findsOneWidget);
        }, variant: TargetPlatformVariant.all());
      }
    });
  }

  testWidgets('un anfitrión con material_ui conserva sus propios textos', (t) async {
    await t.pumpWidget(MaterialApp(localizationsDelegates: const [_HostTextsDelegate()], home: _flow()));
    await t.pumpAndSettle();
    expect(MaterialLocalizations.of(t.element(find.byType(TextField))), isA<_HostTexts>());
  });
}
