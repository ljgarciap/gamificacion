import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Contrato entre lenguajes de la MUESTRA: `docs/contract/examples/demo-*.json` son respuestas REALES del backend (las regenera y compara
/// `DemoContractExamplesTest`); aquí `DemoApi` las interpreta. Si el servidor cambia un campo, falla allá; si el cliente no lo entiende, aquí.
String _example(String name) {
  for (final base in ['../../docs/contract/examples', 'docs/contract/examples']) {
    final f = File('$base/$name.json');
    if (f.existsSync()) return f.readAsStringSync();
  }
  fail('No se encuentra el ejemplo $name (corra la prueba desde el directorio del paquete).');
}

http.Response _ok(String body) =>
    http.Response.bytes(utf8.encode(body), 200, headers: {'content-type': 'application/json'});

void main() {
  test('la sesión real de la muestra se interpreta completa', () async {
    final api = DemoApi(baseUrl: 'http://api.test', client: MockClient((_) async => _ok(_example('demo-session'))));
    final s = await api.openSession();
    expect(s.displayName, isNotEmpty);
    expect(s.balance, 5000);
    expect(s.products, isNotEmpty);
    expect(s.products.first.price, greaterThan(0));
    expect(s.rules.codeStepAmount, greaterThan(0));
    expect(await api.token(), 'eyJ.demo.token', reason: 'el token de la sesión sirve como token del repositorio');
  });

  test('la orden real de la compra se interpreta completa', () async {
    final api = DemoApi(
      baseUrl: 'http://api.test',
      client: MockClient(
        (r) async => r.url.path == '/v1/demo/session' ? _ok(_example('demo-session')) : _ok(_example('demo-order')),
      ),
    );
    final o = await api.purchase([const DemoLine('recarga-20', 2)], idempotencyKey: 'clave-0001');
    expect((o.total, o.credited, o.balance, o.capReached, o.replayed), (40000, 4000, 9000, false, false));
    expect(o.codes, hasLength(2));
    expect(o.codes.first.game, 'roulette');
  });

  test('el estado real de la muestra se interpreta', () async {
    final api = DemoApi(baseUrl: 'http://api.test', client: MockClient((_) async => _ok(_example('demo-status'))));
    expect(await api.status(), isTrue);
  });
}
