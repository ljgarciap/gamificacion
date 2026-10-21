import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

const _okBody =
    '{"redemptionId":"r1","alreadyPlayed":false,"playedAt":"2026-10-20T12:00:00.000000Z",'
    '"campaign":{"id":"c","name":"N","gameType":"roulette","usedCodeBehavior":"rejectOnly","title":null,"theme":{},"gameConfig":{}},'
    '"outcome":{"prize":{"id":"p","type":"money","label":"x","amount":5},"extraSpins":0,"gameResult":{}}}';

http.Response _json(int status, String body, {Map<String, String> headers = const {}}) =>
    http.Response.bytes(utf8.encode(body), status, headers: {'content-type': 'application/json', ...headers});

class _Tokens implements TokenProvider {
  final calls = <bool>[]; // forceRefresh de cada llamada
  int _n = 0;
  Object? failWith;

  @override
  Future<String> token({bool forceRefresh = false}) async {
    calls.add(forceRefresh);
    if (failWith != null) throw failWith!;
    return 'token-${_n++}';
  }
}

Future<GamificationException> _fails(Future<Object?> Function() f) async {
  try {
    await f();
  } on GamificationException catch (e) {
    return e;
  }
  fail('se esperaba GamificationException');
}

void main() {
  late _Tokens tokens;
  late List<http.Request> sent;

  HttpRepository repo(
    Future<http.Response> Function(http.Request r) handler, {
    String baseUrl = 'http://api.test:8000',
    Duration? timeout,
  }) {
    sent = [];
    return HttpRepository(
      baseUrl: baseUrl,
      tokenProvider: tokens,
      timeout: timeout ?? const Duration(seconds: 5),
      client: MockClient((r) {
        sent.add(r);
        return handler(r);
      }),
    );
  }

  setUp(() => tokens = _Tokens());

  group('redeem: la petición', () {
    test('POST /v1/codes/redeem con token, clave de idempotencia y el código en JSON', () async {
      final r = repo((_) async => _json(200, _okBody));
      await r.redeem('RULETA-K3J9-QW2X-7HC', idempotencyKey: 'clave-0000001');

      final req = sent.single;
      expect(req.method, 'POST');
      expect(req.url.toString(), 'http://api.test:8000/v1/codes/redeem');
      expect(req.headers['Authorization'], 'Bearer token-0');
      expect(req.headers['Idempotency-Key'], 'clave-0000001');
      expect(req.headers['Content-Type'], startsWith('application/json'));
      expect(req.headers['Accept'], 'application/json');
      expect(jsonDecode(req.body), {'code': 'RULETA-K3J9-QW2X-7HC'});
    });

    test('devuelve la redención ya interpretada', () async {
      final result = await repo((_) async => _json(200, _okBody)).redeem('X', idempotencyKey: 'clave-0000001');
      expect(result.redemptionId, 'r1');
      expect(result.outcome.prize.amount, 5);
      expect(result.campaign.gameType, 'roulette');
    });

    test('lee bien los caracteres con acentos (UTF-8)', () async {
      final body = _okBody.replaceFirst('"name":"N"', '"name":"Campaña de niñez"');
      final result = await repo((_) async => _json(200, body)).redeem('X', idempotencyKey: 'clave-0000001');
      expect(result.campaign.name, 'Campaña de niñez');
    });
  });

  group('giros extra', () {
    test('un resultado ya jugado trae los giros pendientes y un canje normal no', () async {
      final withPending = _okBody.replaceFirst(
        '"alreadyPlayed":false',
        '"alreadyPlayed":true,"pendingSpins":[{"redemptionId":"p1","remaining":2,"played":1}]',
      );
      final r = await repo((_) async => _json(200, withPending)).redeem('X', idempotencyKey: 'clave-0000001');
      expect(r.alreadyPlayed, isTrue);
      expect(r.pendingSpins, hasLength(1));
      expect(
        (r.pendingSpins.single.redemptionId, r.pendingSpins.single.remaining, r.pendingSpins.single.played),
        ('p1', 2, 1),
      );

      final plain = await repo((_) async => _json(200, _okBody)).redeem('X', idempotencyKey: 'clave-0000002');
      expect(plain.pendingSpins, isEmpty);
    });

    test('los errores de giro se traducen: sin giros, padre desconocido y reserva agotada', () async {
      for (final (code, status, reason) in [
        ('noSpinsLeft', 409, FailureReason.noSpinsLeft),
        ('spinNotFound', 404, FailureReason.noSpinsLeft),
        ('spinReserveExhausted', 409, FailureReason.spinReserveExhausted),
        ('expired', 410, FailureReason.expired),
      ]) {
        final body = '{"error":{"code":"$code","message":"x"}}';
        final e = await _fails(() => repo((_) async => _json(status, body)).spin('p1', idempotencyKey: 'p1:1x2y3z'));
        expect(e.reason, reason, reason: code);
      }
    });
  });

  group('URL base', () {
    test('tolera una barra final y un prefijo de ruta', () async {
      for (final base in ['http://api.test/', 'http://api.test']) {
        final r = repo((_) async => _json(200, _okBody), baseUrl: base);
        await r.redeem('X', idempotencyKey: 'clave-0000001');
        expect(sent.single.url.toString(), 'http://api.test/v1/codes/redeem', reason: base);
      }
      final prefixed = repo((_) async => _json(200, _okBody), baseUrl: 'https://host.test/gamificacion/');
      await prefixed.redeem('X', idempotencyKey: 'clave-0000001');
      expect(sent.single.url.toString(), 'https://host.test/gamificacion/v1/codes/redeem');
    });

    test('rechaza una URL que no es absoluta', () {
      for (final bad in ['', 'localhost:8000', '/v1', 'api.test']) {
        expect(
          () => HttpRepository(baseUrl: bad, tokenProvider: tokens),
          throwsArgumentError,
          reason: bad,
        );
      }
    });
  });

  group('errores de negocio (mismo mapeo que el OpenAPI)', () {
    final cases = <String, (int, FailureReason)>{
      'invalidCode': (422, FailureReason.invalidCode),
      'alreadyUsed': (409, FailureReason.alreadyUsed),
      'expired': (410, FailureReason.expired),
      'campaignInactive': (403, FailureReason.campaignInactive),
      'rateLimited': (429, FailureReason.rateLimited),
      'idempotencyKeyReuse': (422, FailureReason.unknown),
      'idempotencyKeyRequired': (400, FailureReason.unknown),
      'serverError': (500, FailureReason.unknown),
      'algoNuevoDeUnaVersionFutura': (418, FailureReason.unknown),
    };
    cases.forEach((code, expected) {
      test('$code (HTTP ${expected.$1}) -> ${expected.$2.name}', () async {
        final r = repo((_) async => _json(expected.$1, '{"error":{"code":"$code","message":"m"}}'));
        final e = await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
        expect(e.reason, expected.$2);
      });
    });

    test('rateLimited trae el tiempo de espera del cuerpo', () async {
      final r = repo((_) async => _json(429, '{"error":{"code":"rateLimited","message":"m","retryAfter":90}}'));
      final e = await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      expect(e.retryAfter, const Duration(seconds: 90));
    });

    test('si el cuerpo no lo trae, usa la cabecera Retry-After', () async {
      final r = repo(
        (_) async => _json(429, '{"error":{"code":"rateLimited","message":"m"}}', headers: {'retry-after': '45'}),
      );
      final e = await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      expect(e.retryAfter, const Duration(seconds: 45));
    });

    test('solo rateLimited lleva retryAfter', () async {
      final r = repo((_) async => _json(409, '{"error":{"code":"alreadyUsed","message":"m","retryAfter":30}}'));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).retryAfter, isNull);
    });
  });

  group('respuestas que no son el formato esperado (proxy, balanceador)', () {
    final byStatus = <int, FailureReason>{
      502: FailureReason.network,
      503: FailureReason.network,
      504: FailureReason.network,
      500: FailureReason.unknown,
      404: FailureReason.unknown,
      429: FailureReason.rateLimited,
    };
    byStatus.forEach((status, reason) {
      test('HTTP $status con una página HTML -> ${reason.name}', () async {
        final r = repo(
          (_) async => http.Response('<html>Bad gateway</html>', status, headers: {'content-type': 'text/html'}),
        );
        expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, reason);
      });
    });

    test('cuerpo vacío', () async {
      final r = repo((_) async => http.Response('', 503));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.network);
    });
  });

  group('un 200 ilegible nunca revienta la app', () {
    for (final body in ['', 'no es json', '[1,2,3]', '{}', '{"redemptionId":"r"}', '{"campaign":{},"outcome":{}}']) {
      test('cuerpo «$body» -> unknown', () async {
        final r = repo((_) async => _json(200, body));
        expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.unknown);
      });
    }
  });

  group('autenticación', () {
    test('ante un 401 pide un token NUEVO y reintenta una vez con la misma clave de idempotencia', () async {
      var n = 0;
      final r = repo(
        (_) async => ++n == 1 ? _json(401, '{"error":{"code":"unauthenticated","message":"m"}}') : _json(200, _okBody),
      );
      final result = await r.redeem('X', idempotencyKey: 'clave-0000001');

      expect(result.redemptionId, 'r1');
      expect(tokens.calls, [false, true], reason: 'la segunda vez fuerza la renovación');
      expect(sent.map((s) => s.headers['Authorization']), ['Bearer token-0', 'Bearer token-1']);
      expect(sent.map((s) => s.headers['Idempotency-Key']).toSet(), {
        'clave-0000001',
      }, reason: 'misma clave: el servidor nunca juega dos veces');
    });

    test('si el segundo intento también da 401: unauthorized, sin bucle (2 peticiones exactas)', () async {
      final r = repo((_) async => _json(401, '{"error":{"code":"unauthenticated","message":"m"}}'));
      final e = await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      expect(e.reason, FailureReason.unauthorized);
      expect(sent, hasLength(2));
    });

    test('un 401 sin cuerpo útil también es unauthorized', () async {
      final r = repo((_) async => http.Response('', 401));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.unauthorized);
    });

    test('un 403 de negocio NO provoca renovación de token', () async {
      final r = repo((_) async => _json(403, '{"error":{"code":"campaignInactive","message":"m"}}'));
      await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      expect(tokens.calls, [false]);
      expect(sent, hasLength(1));
    });

    test('si el proveedor de token falla, es unauthorized y no se envía nada', () async {
      tokens.failWith = StateError('sesión cerrada');
      final r = repo((_) async => _json(200, _okBody));
      final e = await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      expect(e.reason, FailureReason.unauthorized);
      expect(sent, isEmpty);
    });

    test('si el proveedor ya lanza una GamificationException, se respeta', () async {
      tokens.failWith = const GamificationException(FailureReason.network);
      final r = repo((_) async => _json(200, _okBody));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.network);
    });
  });

  group('red', () {
    test('sin conexión (ClientException) -> network', () async {
      final r = repo((_) async => throw http.ClientException('Failed host lookup'));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.network);
    });

    test('tiempo de espera agotado -> network', () async {
      final r = repo((_) => Completer<http.Response>().future, timeout: const Duration(milliseconds: 50));
      expect((await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'))).reason, FailureReason.network);
    });

    test('el reintento tras un fallo de red conserva la clave (la decide el flujo)', () async {
      var n = 0;
      final r = repo((_) async => ++n == 1 ? throw http.ClientException('x') : _json(200, _okBody));
      await _fails(() => r.redeem('X', idempotencyKey: 'clave-0000001'));
      await r.redeem('X', idempotencyKey: 'clave-0000001');
      expect(sent.map((s) => s.headers['Idempotency-Key']).toSet(), {'clave-0000001'});
    });

    test('peticiones simultáneas son independientes', () async {
      final r = repo((req) async => _json(200, _okBody.replaceFirst('"r1"', '"${jsonDecode(req.body)['code']}"')));
      final results = await Future.wait([
        for (var i = 0; i < 10; i++) r.redeem('C$i', idempotencyKey: 'clave-000000$i'),
      ]);
      expect(results.map((e) => e.redemptionId).toSet(), {for (var i = 0; i < 10; i++) 'C$i'});
    });
  });

  group('saldo', () {
    test('GET /v1/balance', () async {
      final r = repo((_) async => _json(200, '{"amount":120000,"currency":"COP"}'));
      expect(await r.getBalance(), const Balance(120000));
      expect(sent.single.method, 'GET');
      expect(sent.single.url.path, '/v1/balance');
      expect(sent.single.headers['Authorization'], 'Bearer token-0');
    });

    test('errores', () async {
      expect(
        (await _fails(
          () => repo((_) async => _json(401, '{"error":{"code":"unauthenticated","message":"m"}}')).getBalance(),
        )).reason,
        FailureReason.unauthorized,
      );
      expect(
        (await _fails(() => repo((_) async => throw http.ClientException('x')).getBalance())).reason,
        FailureReason.network,
      );
      // La app host no responde: transitorio, como una caída de red.
      expect(
        (await _fails(
          () => repo((_) async => _json(503, '{"error":{"code":"hostUnavailable","message":"m"}}')).getBalance(),
        )).reason,
        FailureReason.network,
      );
      expect((await _fails(() => repo((_) async => _json(200, 'roto')).getBalance())).reason, FailureReason.unknown);
      expect(
        (await _fails(() => repo((_) async => _json(200, '{"currency":"COP"}')).getBalance())).reason,
        FailureReason.unknown,
      );
    });
  });

  test('close() cierra el cliente HTTP', () {
    var closed = false;
    final client = _ClosingClient(() => closed = true);
    HttpRepository(baseUrl: 'http://a.test', tokenProvider: tokens, client: client).close();
    expect(closed, isTrue);
  });
}

class _ClosingClient extends http.BaseClient {
  _ClosingClient(this.onClose);
  final void Function() onClose;

  @override
  Future<http.StreamedResponse> send(http.BaseRequest request) => throw UnimplementedError();

  @override
  void close() => onClose();
}
