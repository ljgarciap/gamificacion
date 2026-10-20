import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';

void main() {
  group('GamificationStrings', () {
    test('es es el idioma por defecto para locales no soportados', () {
      expect(GamificationStrings.of(const Locale('fr')), isA<EsStrings>());
      expect(GamificationStrings.of(const Locale('en', 'US')), isA<EnStrings>());
    });

    test('todas las razones de fallo tienen texto en ambos idiomas', () {
      for (final s in [const EsStrings(), const EnStrings()]) {
        for (final r in FailureReason.values) {
          expect(s.failure(r), isNotEmpty, reason: '${s.runtimeType} / $r');
        }
      }
    });

    test('rateLimited incluye los segundos', () {
      expect(const EsStrings().failure(FailureReason.rateLimited, retryAfter: const Duration(seconds: 30)),
          contains('30 s'));
      expect(const EnStrings().failure(FailureReason.rateLimited, retryAfter: const Duration(seconds: 7)),
          contains('7 s'));
    });

    test('descripción del premio: dinero en COP, puntos y etiqueta', () {
      const s = EsStrings();
      expect(s.prizeDescription(const Prize(id: 'a', type: PrizeType.money, label: 'x', amount: 50000)),
          r'$ 50.000');
      expect(s.prizeDescription(const Prize(id: 'b', type: PrizeType.points, label: 'x', amount: 250)),
          '+250 puntos');
      expect(s.prizeDescription(const Prize(id: 'c', type: PrizeType.coupon, label: 'Cupón 20%')),
          'Cupón 20%');
    });

    test('fecha de juego previo con formato local', () {
      expect(const EsStrings().previouslyPlayed(DateTime(2026, 10, 5)), contains('05/10/2026'));
      expect(const EnStrings().previouslyPlayed(DateTime(2026, 10, 5)), contains('2026-10-05'));
    });
  });

  group('GamificationScope', () {
    Widget host({required Widget child, bool disableAnimations = false}) => MaterialApp(
          home: MediaQuery(
            data: MediaQueryData(disableAnimations: disableAnimations),
            child: child,
          ),
        );

    testWidgets('entrega tema y textos a los descendientes', (t) async {
      late GamificationScope found;
      await t.pumpWidget(host(
        child: GamificationScope(
          theme: const GamificationTheme(),
          strings: const EnStrings(),
          preferences: const GamePreferences(),
          child: Builder(builder: (c) {
            found = GamificationScope.of(c);
            return const SizedBox();
          }),
        ),
      ));
      expect(found.strings, isA<EnStrings>());
      expect(found.theme, const GamificationTheme());
    });

    testWidgets('reduceMotion: sistema o preferencia del host', (t) async {
      Future<bool> read({required bool system, required bool pref}) async {
        late bool result;
        await t.pumpWidget(host(
          disableAnimations: system,
          child: GamificationScope(
            theme: const GamificationTheme(),
            strings: const EsStrings(),
            preferences: GamePreferences(reduceMotion: pref),
            child: Builder(builder: (c) {
              result = GamificationScope.reduceMotion(c);
              return const SizedBox();
            }),
          ),
        ));
        return result;
      }

      expect(await read(system: false, pref: false), isFalse);
      expect(await read(system: true, pref: false), isTrue);
      expect(await read(system: false, pref: true), isTrue);
    });
  });

  group('toThemeData', () {
    test('mapea los tokens al ColorScheme', () {
      const theme = GamificationTheme(primary: Color(0xFF112233), accent: Color(0xFFAABBCC));
      final data = theme.toThemeData();
      expect(data.colorScheme.primary, const Color(0xFF112233));
      expect(data.colorScheme.secondary, const Color(0xFFAABBCC));
      expect(data.scaffoldBackgroundColor, theme.background);
    });
  });

  test('igualdad de tema y preferencias (evita rebuilds innecesarios del scope)', () {
    expect(const GamificationTheme(), const GamificationTheme());
    expect(const GamePreferences(), const GamePreferences());
    expect(const GamePreferences(sound: false), isNot(const GamePreferences()));
  });
}
