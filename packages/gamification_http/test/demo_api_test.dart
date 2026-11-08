import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(int status, Object body) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

Map<String, Object?> _session({
  String token = 'jwt-1',
  int expiresIn = 600,
  String subject = 'muestra.abc',
  bool resumed = false,
}) => {
  'token': token,
  'expiresIn': expiresIn,
  'userId': 'standalone-dev:muestra.abc',
  'subject': subject,
  'resumed': resumed,
  'displayName': 'Cliente de muestra',
  'currency': 'COP',
  'balance': 5000,
  'products': [
    {'id': 'recarga-20', 'name': 'Recarga \$20.000', 'price': 20000},
  ],
  'rules': {
    'balancePercent': 10,
    'minPurchase': 10000,
    'codeStepAmount': 20000,
    'maxCodesPerPurchase': 3,
    'maxCodesPerSession': 10,
  },
};

Map<String, Object?> _order({bool replayed = false}) => {
  'orderId': '3f2c6f8e-0000-4000-8000-000000000001',
  'total': 40000,
  'credited': 4000,
  'codes': [
    {'code': 'ABC-1', 'game': 'roulette'},
    {'code': 'ABC-2', 'game': 'dice'},
  ],
  'capReached': false,
  'balance': 9000,
  'replayed': replayed,
};

void main() {
  group('DemoApi', () {
    test('status: true solo con {enabled:true}; cualquier fallo cuenta como apagada', () async {
      Future<bool> status(http.Response Function() r) =>
          DemoApi(baseUrl: 'http://api.test', client: MockClient((_) async => r())).status();
      expect(await status(() => _json(200, {'enabled': true})), isTrue);
      expect(await status(() => _json(200, {'enabled': false})), isFalse);
      expect(
        await status(
          () => _json(404, {
            'error': {'code': 'notFound', 'message': 'x'},
          }),
        ),
        isFalse,
      );
      expect(await status(() => http.Response('<html>', 200)), isFalse);
      final down = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((_) async => throw http.ClientException('x')),
      );
      expect(await down.status(), isFalse);
    });

    test('status reintenta los fallos transitorios (red, 5xx) pero no las respuestas definitivas', () async {
      Future<(bool, int)> run(http.Response Function(int call) h) async {
        var calls = 0;
        final api = DemoApi(
          baseUrl: 'http://api.test',
          statusRetryDelay: Duration.zero,
          client: MockClient((_) async => h(++calls)),
        );
        return (await api.status(), calls);
      }

      // Cae dos veces y responde: no se confunde un corte momentáneo con «apagada».
      expect(await run((n) => n < 3 ? http.Response('x', 503) : _json(200, {'enabled': true})), (true, 3));
      // Un 404 y un 200 son definitivos: una sola llamada.
      expect(
        await run(
          (_) => _json(404, {
            'error': {'code': 'notFound', 'message': 'x'},
          }),
        ),
        (false, 1),
      );
      expect(await run((_) => _json(200, {'enabled': false})), (false, 1));
      // Si siempre cae, se rinde tras los reintentos configurados (1 + 2).
      expect(await run((_) => http.Response('x', 502)), (false, 3));
      var netCalls = 0;
      final down = DemoApi(
        baseUrl: 'http://api.test',
        statusRetryDelay: Duration.zero,
        client: MockClient((_) async {
          netCalls++;
          throw http.ClientException('x');
        }),
      );
      expect(await down.status(), isFalse);
      expect(netCalls, 3);
    });

    test('openSession interpreta catálogo, reglas y saldo, y la siguiente apertura reanuda con el subject', () async {
      final bodies = <Map<String, dynamic>>[];
      final api = DemoApi(
        baseUrl: 'http://api.test:8000',
        client: MockClient((r) async {
          expect(r.url.toString(), 'http://api.test:8000/v1/demo/session');
          bodies.add(jsonDecode(r.body) as Map<String, dynamic>);
          return _json(200, _session(resumed: bodies.length > 1));
        }),
      );
      final s = await api.openSession();
      expect((s.displayName, s.balance, s.resumed), ('Cliente de muestra', 5000, false));
      expect(s.products.single.price, 20000);
      expect(s.rules.preview(40000).codes, 2);

      await api.openSession();
      expect(bodies, [
        <String, dynamic>{},
        {'resume': 'muestra.abc'},
      ]);
    });

    test('purchase manda solo ids y cantidades con la clave de idempotencia y el token de la sesión', () async {
      late http.Request seen;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/session') return _json(200, _session());
          seen = r;
          return _json(200, _order());
        }),
      );
      final order = await api.purchase([const DemoLine('recarga-20', 2)], idempotencyKey: 'clave-0001');
      expect(seen.headers['Authorization'], 'Bearer jwt-1');
      expect(seen.headers['Idempotency-Key'], 'clave-0001');
      expect(jsonDecode(seen.body), {
        'items': [
          {'productId': 'recarga-20', 'quantity': 2},
        ],
      });
      expect((order.credited, order.balance, order.codes.length, order.codes.first.game), (4000, 9000, 2, 'roulette'));
    });

    test('el token se renueva solo, reanudando la sesión, cuando está por vencer', () async {
      var now = DateTime(2026, 11, 8, 10);
      var opens = 0;
      final resumes = <Object?>[];
      final api = DemoApi(
        baseUrl: 'http://api.test',
        clock: () => now,
        client: MockClient((r) async {
          opens++;
          resumes.add((jsonDecode(r.body) as Map)['resume']);
          return _json(200, _session(token: 'jwt-$opens', expiresIn: 600));
        }),
      );
      expect(await api.token(), 'jwt-1');
      now = now.add(const Duration(minutes: 5));
      expect(await api.token(), 'jwt-1', reason: 'aún vigente: no se vuelve a pedir');
      now = now.add(const Duration(minutes: 5)); // quedan 0 s < margen de 30 s
      expect(await api.token(), 'jwt-2');
      expect(resumes, [null, 'muestra.abc']);
    });

    test('varias peticiones simultáneas comparten UNA apertura de sesión', () async {
      var opens = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          opens++;
          await Future<void>.delayed(const Duration(milliseconds: 20));
          return _json(200, _session());
        }),
      );
      await Future.wait([api.token(), api.token(), api.openSession()]);
      expect(opens, 1);
    });

    test('un 403 demoSessionInvalid renueva la sesión y reintenta UNA vez con la misma clave', () async {
      final keys = <String?>[];
      var purchases = 0;
      var opens = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/session') return _json(200, _session(token: 'jwt-${++opens}'));
          keys.add(r.headers['Idempotency-Key']);
          return ++purchases == 1
              ? _json(403, {
                  'error': {'code': 'demoSessionInvalid', 'message': 'x'},
                })
              : _json(200, _order());
        }),
      );
      final order = await api.purchase([const DemoLine('recarga-20', 1)], idempotencyKey: 'clave-0002');
      expect(order.balance, 9000);
      expect(keys, ['clave-0002', 'clave-0002']);
      expect(opens, 2);
    });

    test('si persiste el rechazo no reintenta más de una vez', () async {
      var purchases = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/session') return _json(200, _session());
          purchases++;
          return _json(403, {
            'error': {'code': 'demoSessionInvalid', 'message': 'x'},
          });
        }),
      );
      await expectLater(
        api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0003'),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      expect(purchases, 2);
    });

    test('traduce los errores del servidor sin filtrar excepciones de red o de formato', () async {
      Future<DemoException> fail(http.Response Function(http.Request) h, {bool onSession = false}) async {
        final api = DemoApi(
          baseUrl: 'http://api.test',
          client: MockClient(
            (r) async => r.url.path == '/v1/demo/session' && !onSession ? _json(200, _session()) : h(r),
          ),
        );
        try {
          onSession
              ? await api.openSession()
              : await api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0004');
        } on DemoException catch (e) {
          return e;
        }
        throw StateError('no falló');
      }

      Map<String, Object?> err(String code, [String m = 'm']) => {
        'error': {'code': code, 'message': m},
      };
      var e = await fail((_) => _json(409, err('demoPoolExhausted')));
      expect(e.reason, DemoFailure.poolExhausted);
      e = await fail((_) => _json(422, err('invalidPurchase', 'La compra no alcanza el mínimo')));
      expect((e.reason, e.message), (DemoFailure.invalidPurchase, 'La compra no alcanza el mínimo'));
      e = await fail((_) => _json(429, err('rateLimited')));
      expect(e.reason, DemoFailure.rateLimited);
      e = await fail((_) => http.Response('<html>', 502));
      expect(e.reason, DemoFailure.network);
      e = await fail((_) => throw http.ClientException('x'));
      expect(e.reason, DemoFailure.network);
      e = await fail((_) => _json(404, err('notFound')), onSession: true);
      expect(e.reason, DemoFailure.off);
      e = await fail((_) => _json(503, err('devIssuerNotConfigured')), onSession: true);
      expect(e.reason, DemoFailure.network);
      e = await fail((_) => _json(200, {'token': 1}), onSession: true);
      expect(e.reason, DemoFailure.unknown);
      e = await fail((_) => _json(200, {'orderId': 1}));
      expect(e.reason, DemoFailure.unknown);
    });

    test('un tiempo de espera agotado es un fallo de red', () async {
      final api = DemoApi(
        baseUrl: 'http://api.test',
        timeout: const Duration(milliseconds: 20),
        client: MockClient((_) => Completer<http.Response>().future),
      );
      await expectLater(
        api.openSession(),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.network)),
      );
    });

    test(
      'como TokenProvider del repositorio: si la apertura falla por red lanza GamificationException(network)',
      () async {
        final api = DemoApi(
          baseUrl: 'http://api.test',
          client: MockClient((_) async => throw http.ClientException('x')),
        );
        await expectLater(
          api.token(),
          throwsA(isA<GamificationException>().having((e) => e.reason, 'reason', FailureReason.network)),
        );
      },
    );
  });
}
