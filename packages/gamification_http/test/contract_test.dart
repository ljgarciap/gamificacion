import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:game_roulette/game_roulette.dart';
import 'package:game_scratch/game_scratch.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_http/gamification_http.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// PRUEBA DE CONTRATO ENTRE LENGUAJES. Los archivos de docs/contract/examples/ son respuestas REALES del backend (PHP); aquí los lee el
/// cliente Flutter. Si el backend cambia un campo sin que el cliente lo entienda, esta prueba falla (y al revés: la del backend compara su
/// respuesta con estos mismos archivos).
///
/// Además de parsear con `Redemption.fromJson`, comprueba que LOS JUEGOS entienden la configuración y el resultado (no solo el modelo).
Directory _dir() {
  for (final candidate in ['../../docs/contract/examples', 'docs/contract/examples']) {
    final d = Directory(candidate);
    if (d.existsSync()) return d;
  }
  fail('No se encuentran los ejemplos del contrato (docs/contract/examples). Corra la prueba desde el directorio del paquete.');
}

String _read(String name) => File('${_dir().path}/$name.json').readAsStringSync();

Map<String, dynamic> _example(String name) => jsonDecode(_read(name)) as Map<String, dynamic>;

class _Token implements TokenProvider {
  @override
  Future<String> token({bool forceRefresh = false}) async => 't';
}

HttpRepository _repoReturning(int status, String body) => HttpRepository(
      baseUrl: 'http://api.test',
      tokenProvider: _Token(),
      client: MockClient((_) async => http.Response.bytes(utf8.encode(body), status, headers: {'content-type': 'application/json'})),
    );

void main() {
  const redemptionExamples = [
    'redemption-roulette-win',
    'redemption-roulette-none',
    'redemption-roulette-coupon',
    'redemption-roulette-points',
    'redemption-roulette-extra-spin',
    'redemption-scratch-win',
    'redemption-scratch-none',
    'redemption-scratch-already-played',
  ];
  const errorExamples = <String, (int, FailureReason)>{
    'error-invalid-code': (422, FailureReason.invalidCode),
    'error-already-used': (409, FailureReason.alreadyUsed),
    'error-expired': (410, FailureReason.expired),
    'error-campaign-inactive': (403, FailureReason.campaignInactive),
    'error-rate-limited': (429, FailureReason.rateLimited),
    'error-idempotency-key-reuse': (422, FailureReason.unknown),
    'error-idempotency-key-required': (400, FailureReason.unknown),
    'error-unauthenticated': (401, FailureReason.unauthorized),
    'error-server-error': (500, FailureReason.unknown),
  };

  test('hay un test para cada ejemplo del contrato (si el backend agrega uno, hay que cubrirlo aquí)', () {
    final onDisk = _dir().listSync().whereType<File>().map((f) => f.uri.pathSegments.last.replaceAll('.json', '')).toSet();
    expect(onDisk, {...redemptionExamples, ...errorExamples.keys, 'balance'});
  });

  group('redenciones', () {
    for (final name in redemptionExamples) {
      test('$name se interpreta con el modelo del cliente, también por HTTP', () async {
        final viaModel = Redemption.fromJson(_example(name));
        final viaHttp = await _repoReturning(200, _read(name)).redeem('X', idempotencyKey: 'clave-0000001');

        expect(viaHttp.redemptionId, viaModel.redemptionId);
        expect(viaModel.redemptionId, isNotEmpty);
        expect(viaModel.playedAt, isNotNull, reason: 'playedAt es una fecha ISO 8601 válida');
        expect(viaModel.campaign.gameType, anyOf('roulette', 'scratch'));
        expect(viaModel.outcome.prize.label, isNotEmpty);
        expect(viaModel.outcome.prize.type, isNot(PrizeType.unknown), reason: 'el servidor solo emite tipos que el cliente conoce');
      });
    }

    test('el tema vacío ({}) se lee como mapa y da el tema por defecto', () {
      final r = Redemption.fromJson(_example('redemption-roulette-win'));
      expect(r.campaign.theme, const GamificationTheme());
    });

    test('un código ya jugado (showPrevious) llega con alreadyPlayed', () {
      final r = Redemption.fromJson(_example('redemption-scratch-already-played'));
      expect(r.alreadyPlayed, isTrue);
      expect(r.campaign.usedCodeBehavior, UsedCodeBehavior.showPrevious);
      expect(Redemption.fromJson(_example('redemption-scratch-win')).alreadyPlayed, isFalse);
    });

    test('cada tipo de premio llega con sus datos', () {
      final coupon = Redemption.fromJson(_example('redemption-roulette-coupon')).outcome.prize;
      expect((coupon.type, coupon.couponCode, coupon.iconKey), (PrizeType.coupon, 'GIRA20', 'gift'));

      final points = Redemption.fromJson(_example('redemption-roulette-points')).outcome.prize;
      expect((points.type, points.amount), (PrizeType.points, 250));

      final extra = Redemption.fromJson(_example('redemption-roulette-extra-spin')).outcome;
      expect((extra.prize.type, extra.extraSpins), (PrizeType.extraSpin, 1));

      final money = Redemption.fromJson(_example('redemption-roulette-win')).outcome.prize;
      expect((money.type, money.amount), (PrizeType.money, 50000));

      final none = Redemption.fromJson(_example('redemption-roulette-none')).outcome.prize;
      expect(none.type, PrizeType.none);
      expect(none.isWin, isFalse);
    });
  });

  group('la ruleta entiende el contrato', () {
    for (final name in redemptionExamples.where((n) => n.contains('roulette'))) {
      test('$name: el segmento donde se detiene muestra el premio ganado', () {
        final r = Redemption.fromJson(_example(name));
        final config = RouletteConfig.fromRedemption(r); // lanza si la configuración o el resultado no sirven

        expect(config.segments.length, inInclusiveRange(RouletteConfig.minSegments, RouletteConfig.maxSegments));
        expect(config.segments[config.targetIndex].id, r.outcome.prize.id, reason: 'el segmento del resultado es el del premio');
        expect(config.segments.every((s) => s.type != PrizeType.unknown), isTrue, reason: 'ningún segmento trae un tipo de premio que el cliente no conozca');
        expect(config.duration.inMilliseconds, greaterThan(0));
      });
    }
  });

  group('el raspa entiende el contrato', () {
    test('premio: 3 casillas del premio', () {
      final r = Redemption.fromJson(_example('redemption-scratch-win'));
      final config = ScratchConfig.fromRedemption(r);
      expect(config.grid, hasLength(config.rows * config.cols));
      expect(config.winningCells(r.outcome.prize), hasLength(3));
    });

    test('sin premio: ninguna casilla ganadora y ningún símbolo forma un trío', () {
      final r = Redemption.fromJson(_example('redemption-scratch-none'));
      final config = ScratchConfig.fromRedemption(r);
      expect(config.winningCells(r.outcome.prize), isEmpty);
      final counts = <String, int>{};
      for (final s in config.grid) {
        counts[s] = (counts[s] ?? 0) + 1;
      }
      expect(counts.values.every((c) => c < 3), isTrue);
    });

    test('el resultado de un código ya jugado también es representable', () {
      final r = Redemption.fromJson(_example('redemption-scratch-already-played'));
      expect(ScratchConfig.fromRedemption(r).winningCells(r.outcome.prize), hasLength(3));
    });
  });

  group('errores', () {
    errorExamples.forEach((name, expected) {
      test('$name (HTTP ${expected.$1}) -> ${expected.$2.name}', () async {
        try {
          await _repoReturning(expected.$1, _read(name)).redeem('X', idempotencyKey: 'clave-0000001');
          fail('debía fallar');
        } on GamificationException catch (e) {
          expect(e.reason, expected.$2);
        }
      });
    });

    test('rateLimited trae los segundos de espera del ejemplo real', () async {
      try {
        await _repoReturning(429, _read('error-rate-limited')).redeem('X', idempotencyKey: 'clave-0000001');
        fail('debía fallar');
      } on GamificationException catch (e) {
        expect(e.retryAfter, const Duration(seconds: 60));
      }
    });
  });

  test('saldo', () async {
    final balance = await _repoReturning(200, _read('balance')).getBalance();
    expect(balance, const Balance(120000));
  });
}
