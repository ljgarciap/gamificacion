import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_standalone/main.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

String _example(String name) {
  for (final base in ['../../docs/contract/examples', 'docs/contract/examples']) {
    final f = File('$base/$name.json');
    if (f.existsSync()) return f.readAsStringSync();
  }
  fail('No se encuentra el ejemplo $name');
}

http.Response _json(int status, String body) =>
    http.Response.bytes(utf8.encode(body), status, headers: {'content-type': 'application/json'});

void main() {
  testWidgets('sin URL de backend usa el simulado y lo indica', (tester) async {
    await tester.pumpWidget(const GamificationApp(apiUrl: ''));
    await tester.pumpAndSettle();
    expect(find.text('Validar código'), findsOneWidget);
    expect(find.text('Códigos de prueba'), findsOneWidget);
    expect(find.text('Modo demostración (backend simulado)'), findsOneWidget);
  });

  group('con backend real (simulado a nivel HTTP)', () {
    late List<http.Request> requests;

    MockClient server({List<Map<String, String>> demoCodes = const []}) {
      requests = [];
      return MockClient((r) async {
        requests.add(r);
        switch (r.url.path) {
          case '/v1/dev/token':
            return _json(200, jsonEncode({'token': 'jwt-dev', 'expiresIn': 300, 'userId': 'standalone-dev:demo-user'}));
          case '/v1/dev/demo-codes':
            return _json(200, jsonEncode({'codes': demoCodes}));
          case '/v1/balance':
            return _json(200, jsonEncode({'amount': 120000, 'currency': 'COP'}));
          case '/v1/codes/redeem':
            return _json(200, _example('redemption-roulette-win'));
        }
        return http.Response('', 404);
      });
    }

    Future<void> pump(WidgetTester t, MockClient client) async {
      t.view.physicalSize = const Size(800, 1600);
      t.view.devicePixelRatio = 2;
      addTearDown(t.view.reset);
      await t.pumpWidget(GamificationApp(apiUrl: 'http://api.test:8000', devUser: 'maria', httpClient: client));
      await t.pumpAndSettle();
    }

    testWidgets('indica el backend y el usuario', (t) async {
      await pump(t, server());
      expect(find.text('Backend: http://api.test:8000 · usuario maria'), findsOneWidget);
    });

    testWidgets('pide el token de desarrollo para el usuario configurado y lo usa en las demás llamadas', (t) async {
      await pump(t, server());
      final tokenRequest = requests.firstWhere((r) => r.url.path == '/v1/dev/token');
      expect(jsonDecode(tokenRequest.body), {'userId': 'maria'});

      final balance = requests.firstWhere((r) => r.url.path == '/v1/balance');
      expect(balance.headers['Authorization'], 'Bearer jwt-dev');
      expect(find.text(r'$ 120.000'), findsOneWidget, reason: 'el saldo sale del servidor');
    });

    testWidgets('el panel de códigos de prueba sale del servidor, no del simulador', (t) async {
      await pump(
        t,
        server(
          demoCodes: [
            {'code': 'RULETA-AAAA-BBBB-CCC', 'game': 'roulette', 'description': 'Ruleta: \$ 50.000'},
          ],
        ),
      );
      await t.tap(find.text('Códigos de prueba'));
      await t.pumpAndSettle();
      expect(find.text('RULETA-AAAA-BBBB-CCC'), findsOneWidget);
      expect(find.text('RULETA-50K'), findsNothing, reason: 'los códigos del simulador no aparecen en modo backend');
    });

    testWidgets('si el servidor no tiene códigos de prueba (producción), no hay panel', (t) async {
      await pump(t, server());
      expect(find.text('Códigos de prueba'), findsNothing);
    });

    testWidgets('un canje va al servidor con la clave de idempotencia y el código escrito', (t) async {
      await pump(t, server());
      await t.enterText(find.byType(TextField), 'RULETA-AAAA-BBBB-CCC');
      await t.pump();
      await t.tap(find.text('Validar código'));
      await t.pumpAndSettle();

      final redeem = requests.firstWhere((r) => r.url.path == '/v1/codes/redeem');
      expect(redeem.method, 'POST');
      expect(redeem.headers['Idempotency-Key'], isNotEmpty);
      expect(redeem.headers['Authorization'], 'Bearer jwt-dev');
      expect(jsonDecode(redeem.body), {'code': 'RULETA-AAAA-BBBB-CCC'});
      expect(find.text('GIRAR'), findsOneWidget);
    });
  });
}
