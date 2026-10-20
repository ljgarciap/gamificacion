import 'dart:async';
import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

http.Response _json(int status, Object body) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json'});

void main() {
  group('DevClient', () {
    test('pide el token con el userId y lo interpreta', () async {
      late http.Request seen;
      final client = DevClient(
        baseUrl: 'http://api.test:8000',
        client: MockClient((r) async {
          seen = r;
          return _json(200, {'token': 'jwt', 'expiresIn': 300, 'userId': 'standalone-dev:maria'});
        }),
      );
      final t = await client.requestToken('maria');

      expect(seen.method, 'POST');
      expect(seen.url.toString(), 'http://api.test:8000/v1/dev/token');
      expect(jsonDecode(seen.body), {'userId': 'maria'});
      expect(
        seen.headers.containsKey('Authorization'),
        isFalse,
        reason: 'este endpoint no usa token: es el que lo emite',
      );
      expect((t.token, t.expiresIn, t.userId), ('jwt', const Duration(seconds: 300), 'standalone-dev:maria'));
    });

    test('un error del servidor es unauthorized; sin conexión es network; una respuesta rota es unknown', () async {
      Future<GamificationException> fails(http.Response Function() r) async {
        try {
          await DevClient(baseUrl: 'http://a.test', client: MockClient((_) async => r())).requestToken('x');
        } on GamificationException catch (e) {
          return e;
        }
        fail('debía fallar');
      }

      expect((await fails(() => _json(503, {'error': {}}))).reason, FailureReason.unauthorized);
      expect((await fails(() => _json(200, {'sin': 'token'}))).reason, FailureReason.unknown);
      expect((await fails(() => http.Response('basura', 200))).reason, FailureReason.unknown);
      try {
        await DevClient(
          baseUrl: 'http://a.test',
          client: MockClient((_) async => throw http.ClientException('x')),
        ).requestToken('x');
        fail('debía fallar');
      } on GamificationException catch (e) {
        expect(e.reason, FailureReason.network);
      }
    });

    test('el tiempo de espera agotado es network', () async {
      final client = DevClient(
        baseUrl: 'http://a.test',
        timeout: const Duration(milliseconds: 40),
        client: MockClient((_) => Completer<http.Response>().future),
      );
      expect(
        client.requestToken('x'),
        throwsA(isA<GamificationException>().having((e) => e.reason, 'reason', FailureReason.network)),
      );
    });

    test('demoCodes: código -> descripción', () async {
      final client = DevClient(
        baseUrl: 'http://a.test',
        client: MockClient(
          (_) async => _json(200, {
            'codes': [
              {'code': 'RULETA-AAAA-BBBB-CCC', 'game': 'roulette', 'description': 'Ruleta: \$ 50.000'},
              {'code': 'RASPA-DDDD-EEEE-FFF', 'game': 'scratch', 'description': 'Raspa: Sin premio'},
            ],
          }),
        ),
      );
      expect(await client.demoCodes(), {
        'RULETA-AAAA-BBBB-CCC': 'Ruleta: \$ 50.000',
        'RASPA-DDDD-EEEE-FFF': 'Raspa: Sin premio',
      });
    });

    test('demoCodes nunca falla: es solo una ayuda (producción: 404; sin red; basura)', () async {
      expect(
        await DevClient(baseUrl: 'http://a.test', client: MockClient((_) async => http.Response('', 404))).demoCodes(),
        isEmpty,
      );
      expect(
        await DevClient(
          baseUrl: 'http://a.test',
          client: MockClient((_) async => throw http.ClientException('x')),
        ).demoCodes(),
        isEmpty,
      );
      expect(
        await DevClient(
          baseUrl: 'http://a.test',
          client: MockClient((_) async => http.Response('[]', 200)),
        ).demoCodes(),
        isEmpty,
      );
    });
  });

  group('DevTokenProvider', () {
    late int requests;
    late DateTime now;

    DevTokenProvider provider({Duration expires = const Duration(seconds: 300)}) {
      requests = 0;
      return DevTokenProvider(
        userId: 'maria',
        clock: () => now,
        client: DevClient(
          baseUrl: 'http://a.test',
          client: MockClient((_) async {
            requests++;
            return _json(200, {
              'token': 'jwt-$requests',
              'expiresIn': expires.inSeconds,
              'userId': 'standalone-dev:maria',
            });
          }),
        ),
      );
    }

    setUp(() => now = DateTime(2026, 10, 20, 12));

    test('reutiliza el token mientras sea válido', () async {
      final p = provider();
      expect(await p.token(), 'jwt-1');
      now = now.add(const Duration(seconds: 200));
      expect(await p.token(), 'jwt-1');
      expect(requests, 1);
    });

    test('lo renueva ANTES de que venza (margen de 30 s)', () async {
      final p = provider();
      await p.token();
      now = now.add(const Duration(seconds: 271)); // faltan 29 s
      expect(await p.token(), 'jwt-2');
    });

    test('forceRefresh pide uno nuevo aunque el actual sirva', () async {
      final p = provider();
      await p.token();
      expect(await p.token(forceRefresh: true), 'jwt-2');
      expect(await p.token(), 'jwt-2');
    });

    test('peticiones simultáneas comparten UNA petición de token', () async {
      final p = provider();
      final results = await Future.wait([for (var i = 0; i < 8; i++) p.token()]);
      expect(results.toSet(), {'jwt-1'});
      expect(requests, 1);
    });

    test('si el servidor falla, el error se propaga y la siguiente llamada vuelve a intentar', () async {
      var fail = true;
      final p = DevTokenProvider(
        clock: () => now,
        client: DevClient(
          baseUrl: 'http://a.test',
          client: MockClient(
            (_) async => fail ? http.Response('', 503) : _json(200, {'token': 't', 'expiresIn': 300, 'userId': 'u'}),
          ),
        ),
      );
      await expectLater(p.token(), throwsA(isA<GamificationException>()));
      fail = false;
      expect(await p.token(), 't', reason: 'el fallo no deja la petición "colgada"');
    });
  });
}
