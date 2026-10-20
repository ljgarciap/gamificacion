import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:gamification_sdk/gamification_sdk.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Flujo completo (pantalla de código -> juego -> resultado) con el cliente HTTP REAL y, como "servidor", las respuestas reales del backend
/// (docs/contract/examples). Es lo más cerca de la app real que se puede estar sin levantar el backend.
String _example(String name) {
  for (final base in ['../../docs/contract/examples', 'docs/contract/examples']) {
    final f = File('$base/$name.json');
    if (f.existsSync()) return f.readAsStringSync();
  }
  fail('No se encuentra el ejemplo $name (docs/contract/examples).');
}

class _Silent implements GameFeedback {
  @override
  void tick() {}
  @override
  void win() {}
  @override
  void lose() {}
}

class _Tokens implements TokenProvider {
  final calls = <bool>[];
  @override
  Future<String> token({bool forceRefresh = false}) async {
    calls.add(forceRefresh);
    return 'token-${calls.length}';
  }
}

http.Response _json(int status, String body, {Map<String, String> headers = const {}}) =>
    http.Response.bytes(utf8.encode(body), status, headers: {'content-type': 'application/json', ...headers});

/// Servidor simulado: [redeem] responde a cada POST de canje; el saldo cambia entre la primera y las siguientes consultas.
class _Server {
  _Server(this.redeem);
  final http.Response Function(http.Request) redeem;
  final redeems = <http.Request>[];
  int balanceCalls = 0;

  Future<http.Response> handle(http.Request r) async {
    if (r.url.path == '/v1/balance') {
      balanceCalls++;
      return _json(200, jsonEncode({'amount': balanceCalls == 1 ? 120000 : 170000, 'currency': 'COP'}));
    }
    redeems.add(r);
    return redeem(r);
  }
}

Future<void> _pump(WidgetTester t, _Server server, {TokenProvider? tokens, VoidCallback? onClose}) async {
  t.view.physicalSize = const Size(800, 1600);
  t.view.devicePixelRatio = 2;
  addTearDown(t.view.reset);
  final repo = HttpRepository(
    baseUrl: 'http://api.test:8000',
    tokenProvider: tokens ?? _Tokens(),
    client: MockClient(server.handle),
  );
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: GamificationFlow(
          repository: repo,
          registry: GameRegistry()
            ..register(RouletteModule(feedbackFactory: (_) => _Silent()))
            ..register(ScratchModule(feedbackFactory: (_) => _Silent())),
          events: GamificationEvents(),
          onClose: onClose,
        ),
      ),
    ),
  );
  await t.pumpAndSettle();
}

Future<void> _submit(WidgetTester t, [String code = 'RULETA-AAAA-BBBB-CCC']) async {
  await t.enterText(find.byType(TextField), code);
  await t.pump();
  await t.tap(find.text('Validar código'));
  await t.pumpAndSettle();
}

Future<void> _finishGame(WidgetTester t) async {
  await t.pumpAndSettle();
  await t.pump(const Duration(seconds: 2));
  await t.pumpAndSettle();
}

void main() {
  group('con respuestas reales del backend', () {
    testWidgets('ruleta: código -> giro -> resultado, y el saldo sale del servidor', (t) async {
      final server = _Server((_) => _json(200, _example('redemption-roulette-win')));
      await _pump(t, server);
      expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo viene de GET /v1/balance');

      await _submit(t);
      expect(find.text('GIRAR'), findsOneWidget);
      await t.tap(find.text('GIRAR'));
      await _finishGame(t);

      expect(find.text('¡Ganaste!'), findsOneWidget);
      expect(find.text(r'$ 50.000'), findsWidgets);
      expect(
        find.text(r'$ 170.000'),
        findsOneWidget,
        reason: 'tras ganar dinero, el saldo se vuelve a pedir al servidor',
      );
      expect(server.balanceCalls, 2);
    });

    testWidgets('raspa: código -> revelar -> resultado', (t) async {
      await _pump(t, _Server((_) => _json(200, _example('redemption-scratch-win'))));
      await _submit(t, 'RASPA-AAAA-BBBB-CCC');
      expect(find.byKey(const ValueKey('scratch-card')), findsOneWidget);

      await t.tap(find.text('Revelar todo'));
      await _finishGame(t);
      expect(find.text('¡Ganaste!'), findsOneWidget);
      expect(find.text(r'$ 10.000'), findsWidgets);
    });

    testWidgets('un sin premio de la ruleta llega al resultado amable', (t) async {
      await _pump(t, _Server((_) => _json(200, _example('redemption-roulette-none'))));
      await _submit(t);
      await t.tap(find.text('GIRAR'));
      await _finishGame(t);
      expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
    });

    testWidgets('cupón: muestra el código del cupón que mandó el servidor', (t) async {
      await _pump(t, _Server((_) => _json(200, _example('redemption-roulette-coupon'))));
      await _submit(t);
      await t.tap(find.text('GIRAR'));
      await _finishGame(t);
      expect(find.textContaining('GIRA20'), findsOneWidget);
    });

    testWidgets('un código ya jugado (showPrevious) va directo al resultado, con el aviso, sin volver a jugar', (
      t,
    ) async {
      await _pump(t, _Server((_) => _json(200, _example('redemption-scratch-already-played'))));
      await _submit(t, 'RASPA-AAAA-BBBB-CCC');
      expect(find.byKey(const ValueKey('scratch-card')), findsNothing);
      expect(find.textContaining('ya fue jugado el'), findsOneWidget);
      expect(find.text('¡Ganaste!'), findsOneWidget);
    });
  });

  group('cada error real del backend', () {
    final cases = <String, (int, String)>{
      'error-invalid-code': (422, 'no es válido'),
      'error-already-used': (409, 'ya fue utilizado'),
      'error-expired': (410, 'está vencido'),
      'error-campaign-inactive': (403, 'no está activa'),
      'error-unauthenticated': (401, 'sesión no es válida'),
      'error-server-error': (500, 'error inesperado'),
    };
    cases.forEach((example, expected) {
      testWidgets('$example -> «${expected.$2}»', (t) async {
        final server = _Server(
          (_) => _json(
            expected.$1,
            _example(example),
            headers: example == 'error-unauthenticated' ? {'www-authenticate': 'Bearer'} : {},
          ),
        );
        await _pump(t, server);
        await _submit(t);
        expect(find.textContaining(expected.$2), findsOneWidget);
        expect(find.byType(TextField), findsOneWidget, reason: 'sigue en la pantalla de código');
      });
    });

    testWidgets('rateLimited: muestra los segundos y bloquea el botón', (t) async {
      final server = _Server((_) => _json(429, _example('error-rate-limited'), headers: {'retry-after': '60'}));
      await _pump(t, server);
      await _submit(t);
      expect(find.textContaining('60 s'), findsOneWidget);
      expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
      await t.pumpWidget(const SizedBox()); // descarta el temporizador de la cuenta regresiva
    });
  });

  group('idempotencia y red', () {
    testWidgets('tras un corte de red, el reintento del MISMO código usa la MISMA Idempotency-Key', (t) async {
      var attempts = 0;
      final server = _Server((_) {
        if (++attempts == 1) throw http.ClientException('sin red');
        return _json(200, _example('redemption-roulette-win'));
      });
      await _pump(t, server);
      await _submit(t);
      expect(find.textContaining('Sin conexión'), findsOneWidget);

      await t.tap(find.text('Validar código'));
      await t.pumpAndSettle();
      expect(find.text('GIRAR'), findsOneWidget);

      final keys = server.redeems.map((r) => r.headers['Idempotency-Key']).toList();
      expect(keys, hasLength(2));
      expect(
        keys[0],
        keys[1],
        reason:
            'si el primer intento sí llegó al servidor, este reintento devuelve el mismo resultado sin jugar dos veces',
      );
      expect(
        keys[0],
        matches(RegExp(r'^[0-9a-f]{32}$')),
        reason: 'el formato que acepta el servidor (8-128 de [A-Za-z0-9._-:])',
      );
    });

    testWidgets('un código NUEVO usa una clave NUEVA', (t) async {
      final server = _Server((_) => _json(200, _example('redemption-scratch-already-played')));
      await _pump(t, server);
      await _submit(t, 'RASPA-AAAA-BBBB-CCC');
      await t.tap(find.text('Continuar'));
      await t.pumpAndSettle();
      await _submit(t, 'RASPA-DDDD-EEEE-FFF');

      final keys = server.redeems.map((r) => r.headers['Idempotency-Key']).toSet();
      expect(keys, hasLength(2));
    });

    testWidgets('un 401 se resuelve con un token nuevo sin que el usuario se entere', (t) async {
      final tokens = _Tokens();
      var first = true;
      final server = _Server((_) {
        if (first) {
          first = false;
          return _json(401, _example('error-unauthenticated'));
        }
        return _json(200, _example('redemption-roulette-win'));
      });
      await _pump(t, server, tokens: tokens);
      await _submit(t);

      expect(find.text('GIRAR'), findsOneWidget, reason: 'el usuario no vio ningún error');
      expect(tokens.calls.contains(true), isTrue, reason: 'se pidió un token nuevo');
      expect(
        server.redeems.map((r) => r.headers['Idempotency-Key']).toSet(),
        hasLength(1),
        reason: 'con la misma clave',
      );
    });

    testWidgets('todas las peticiones llevan el token en Authorization', (t) async {
      final server = _Server((_) => _json(200, _example('redemption-roulette-win')));
      await _pump(t, server);
      await _submit(t);
      expect(server.redeems.single.headers['Authorization'], startsWith('Bearer token-'));
    });
  });
}
