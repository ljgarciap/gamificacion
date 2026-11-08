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
  bool isNew = false,
}) => {
  'token': token,
  'expiresIn': expiresIn,
  'userId': 'standalone-dev:muestra.abc',
  'subject': subject,
  'resumed': resumed,
  'isNew': isNew,
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

    test('verifyCode manda correo y código, interpreta la cuenta y no guarda nada si el servidor rechaza', () async {
      final bodies = <Map<String, dynamic>>[];
      final api = DemoApi(
        baseUrl: 'http://api.test:8000',
        client: MockClient((r) async {
          expect(r.url.toString(), 'http://api.test:8000/v1/demo/auth/verify');
          bodies.add(jsonDecode(r.body) as Map<String, dynamic>);
          return _json(200, _session(isNew: true));
        }),
      );
      final s = await api.verifyCode('ana@ejemplo.com', '123456');
      expect((s.displayName, s.balance, s.resumed, s.isNew), ('Cliente de muestra', 5000, false, true));
      expect(s.products.single.price, 20000);
      expect(s.rules.preview(40000).codes, 2);
      expect(bodies, [
        {'email': 'ana@ejemplo.com', 'code': '123456'},
      ]);
      expect(await api.token(), 'jwt-1');
    });

    test('requestCode manda el correo y devuelve la espera; el correo inválido y el envío caído se traducen', () async {
      late http.Request seen;
      http.Response Function(http.Request) handler = (r) {
        seen = r;
        return _json(200, {'sent': true, 'retryAfter': 60});
      };
      final api = DemoApi(baseUrl: 'http://api.test', client: MockClient((r) async => handler(r)));
      final res = await api.requestCode('ana@ejemplo.com');
      expect(res.retryAfter, const Duration(seconds: 60));
      expect(seen.url.path, '/v1/demo/auth/request');
      expect(jsonDecode(seen.body), {'email': 'ana@ejemplo.com'});

      Future<DemoFailure> fail(http.Response r) async {
        handler = (_) => r;
        try {
          await api.requestCode('ana@ejemplo.com');
        } on DemoException catch (e) {
          return e.reason;
        }
        throw StateError('no falló');
      }

      Map<String, Object?> err(String code) => {
        'error': {'code': code, 'message': 'm'},
      };
      expect(await fail(_json(422, err('invalidEmail'))), DemoFailure.invalidEmail);
      expect(await fail(_json(503, err('mailUnavailable'))), DemoFailure.mailUnavailable);
      expect(await fail(_json(503, err('accountsFull'))), DemoFailure.accountsFull);
      expect(await fail(_json(429, err('rateLimited'))), DemoFailure.rateLimited);
      expect(await fail(_json(404, err('notFound'))), DemoFailure.off);
      expect(
        await fail(_json(200, {'sent': true})),
        DemoFailure.unknown,
        reason: 'sin retryAfter: respuesta mal formada',
      );
    });

    test('verifyCode: el 401 invalidCode, el tope de cuentas y la muestra apagada se distinguen', () async {
      Future<DemoFailure> fail(http.Response r) async {
        final api = DemoApi(baseUrl: 'http://api.test', client: MockClient((_) async => r));
        try {
          await api.verifyCode('ana@ejemplo.com', '000000');
        } on DemoException catch (e) {
          return e.reason;
        }
        throw StateError('no falló');
      }

      Map<String, Object?> err(String code) => {
        'error': {'code': code, 'message': 'm'},
      };
      expect(await fail(_json(401, err('invalidCode'))), DemoFailure.invalidCode);
      expect(await fail(_json(503, err('accountsFull'))), DemoFailure.accountsFull);
      expect(await fail(_json(404, err('notFound'))), DemoFailure.off);
      expect(await fail(_json(429, err('rateLimited'))), DemoFailure.rateLimited);
    });

    test('sin ingreso no hay token ni compra ni cuenta: no se abre ninguna sesión anónima', () async {
      var calls = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((_) async {
          calls++;
          return _json(200, _session());
        }),
      );
      await expectLater(
        api.token(),
        throwsA(isA<GamificationException>().having((e) => e.reason, 'reason', FailureReason.unauthorized)),
      );
      await expectLater(
        api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0000'),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      await expectLater(
        api.account(),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      expect(calls, 0, reason: 'ni una sola petición al servidor');
    });

    test('account trae saldo y códigos con su estado usando el token de la cuenta', () async {
      late http.Request seen;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/auth/verify') return _json(200, _session());
          seen = r;
          return _json(200, {
            'balance': 9000,
            'codes': [
              {'code': 'RULETA-AAAA-BBBB-CCC', 'game': 'roulette', 'played': false},
              {'code': 'DADOS-AAAA-BBBB-CCC', 'game': 'dice', 'played': true},
            ],
          });
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      final a = await api.account();
      expect(seen.method, 'GET');
      expect(seen.url.path, '/v1/demo/account');
      expect(seen.headers['Authorization'], 'Bearer jwt-1');
      expect(a.balance, 9000);
      expect(a.codes.map((c) => (c.game, c.played)), [('roulette', false), ('dice', true)]);
    });

    test('purchase manda solo ids y cantidades con la clave de idempotencia y el token de la cuenta', () async {
      late http.Request seen;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/auth/verify') return _json(200, _session());
          seen = r;
          return _json(200, _order());
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
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

    test('el token se renueva solo, reanudando la cuenta, cuando está por vencer', () async {
      var now = DateTime(2026, 11, 8, 10);
      var calls = 0;
      final resumes = <Object?>[];
      final api = DemoApi(
        baseUrl: 'http://api.test',
        clock: () => now,
        client: MockClient((r) async {
          calls++;
          if (r.url.path == '/v1/demo/session') resumes.add((jsonDecode(r.body) as Map)['resume']);
          return _json(200, _session(token: 'jwt-$calls', expiresIn: 600));
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      expect(await api.token(), 'jwt-1');
      now = now.add(const Duration(minutes: 5));
      expect(await api.token(), 'jwt-1', reason: 'aún vigente: no se vuelve a pedir');
      now = now.add(const Duration(minutes: 5)); // quedan 0 s < margen de 30 s
      expect(await api.token(), 'jwt-2');
      expect(resumes, ['muestra.abc']);
    });

    test('varias peticiones simultáneas comparten UNA renovación', () async {
      var now = DateTime(2026, 11, 8, 10);
      var renewals = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        clock: () => now,
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/session') {
            renewals++;
            await Future<void>.delayed(const Duration(milliseconds: 20));
          }
          return _json(200, _session());
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      now = now.add(const Duration(minutes: 11));
      await Future.wait([api.token(), api.token(), api.token()]);
      expect(renewals, 1);
    });

    test('un 403 demoSessionInvalid renueva la sesión y reintenta UNA vez con la misma clave', () async {
      final keys = <String?>[];
      var purchases = 0;
      var tokens = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path != '/v1/demo/purchase') return _json(200, _session(token: 'jwt-${++tokens}'));
          keys.add(r.headers['Idempotency-Key']);
          return ++purchases == 1
              ? _json(403, {
                  'error': {'code': 'demoSessionInvalid', 'message': 'x'},
                })
              : _json(200, _order());
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      final order = await api.purchase([const DemoLine('recarga-20', 1)], idempotencyKey: 'clave-0002');
      expect(order.balance, 9000);
      expect(keys, ['clave-0002', 'clave-0002']);
      expect(tokens, 2);
    });

    test('si persiste el rechazo no reintenta más de una vez', () async {
      var purchases = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((r) async {
          if (r.url.path != '/v1/demo/purchase') return _json(200, _session());
          purchases++;
          return _json(403, {
            'error': {'code': 'demoSessionInvalid', 'message': 'x'},
          });
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      await expectLater(
        api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0003'),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      expect(purchases, 2);
    });

    test('una cuenta borrada (401 al renovar) olvida la sesión: no se sigue con un token muerto', () async {
      var now = DateTime(2026, 11, 8, 10);
      var renewals = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        clock: () => now,
        client: MockClient((r) async {
          if (r.url.path == '/v1/demo/session') {
            renewals++;
            return _json(401, {
              'error': {'code': 'demoSessionInvalid', 'message': 'x'},
            });
          }
          return _json(200, _session());
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      now = now.add(const Duration(minutes: 11));
      await expectLater(
        api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0005'),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      expect(renewals, 1);
      // Olvidada: el siguiente intento ni siquiera pregunta al servidor.
      await expectLater(
        api.account(),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.sessionInvalid)),
      );
      expect(renewals, 1);
    });

    test('signOut olvida token y subject', () async {
      var calls = 0;
      final api = DemoApi(
        baseUrl: 'http://api.test',
        client: MockClient((_) async {
          calls++;
          return _json(200, _session());
        }),
      );
      await api.verifyCode('ana@ejemplo.com', '123456');
      expect(await api.token(), 'jwt-1');
      api.signOut();
      await expectLater(
        api.token(),
        throwsA(isA<GamificationException>().having((e) => e.reason, 'reason', FailureReason.unauthorized)),
      );
      expect(calls, 1);
    });

    test('traduce los errores del servidor sin filtrar excepciones de red o de formato', () async {
      Future<DemoException> fail(http.Response Function(http.Request) h, {bool onLogin = false}) async {
        final api = DemoApi(
          baseUrl: 'http://api.test',
          client: MockClient(
            (r) async => r.url.path == '/v1/demo/auth/verify' && !onLogin ? _json(200, _session()) : h(r),
          ),
        );
        try {
          if (onLogin) {
            await api.verifyCode('ana@ejemplo.com', '123456');
          } else {
            await api.verifyCode('ana@ejemplo.com', '123456');
            await api.purchase([const DemoLine('a', 1)], idempotencyKey: 'clave-0004');
          }
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
      e = await fail((_) => _json(404, err('notFound')), onLogin: true);
      expect(e.reason, DemoFailure.off);
      e = await fail((_) => _json(503, err('devIssuerNotConfigured')), onLogin: true);
      expect(e.reason, DemoFailure.network);
      e = await fail((_) => _json(200, {'token': 1}), onLogin: true);
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
        api.verifyCode('ana@ejemplo.com', '123456'),
        throwsA(isA<DemoException>().having((e) => e.reason, 'reason', DemoFailure.network)),
      );
    });

    test(
      'como TokenProvider del repositorio: si la renovación falla por red lanza GamificationException(network)',
      () async {
        var down = false;
        var now = DateTime(2026, 11, 8, 10);
        final api = DemoApi(
          baseUrl: 'http://api.test',
          clock: () => now,
          client: MockClient((_) async => down ? throw http.ClientException('x') : _json(200, _session())),
        );
        await api.verifyCode('ana@ejemplo.com', '123456');
        down = true;
        now = now.add(const Duration(minutes: 11));
        await expectLater(
          api.token(),
          throwsA(isA<GamificationException>().having((e) => e.reason, 'reason', FailureReason.network)),
        );
      },
    );
  });
}
