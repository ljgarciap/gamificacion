import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';

Future<FailureReason> failureOf(Future<Object?> f) async {
  try {
    await f;
  } on GamificationException catch (e) {
    return e.reason;
  }
  fail('se esperaba GamificationException');
}

void main() {
  late MockRepository repo;
  setUp(() => repo = MockRepository(initialBalance: 1000));

  test('el servidor decide: RULETA-50K cae en el segmento 0 y coincide con su premio', () async {
    final r = await repo.redeem('RULETA-50K', idempotencyKey: 'k1');
    final segments = r.campaign.gameConfig['segments'] as List;
    final idx = r.outcome.gameResult['segmentIndex'] as int;
    expect(idx, 0);
    expect(Prize.fromJson((segments[idx] as Map).cast()).id, r.outcome.prize.id);
    expect(r.outcome.prize.amount, 50000);
  });

  test('normaliza mayúsculas y espacios', () async {
    final r = await repo.redeem('  ruleta-nada ', idempotencyKey: 'k1');
    expect(r.outcome.prize.isWin, isFalse);
  });

  test('código inexistente -> invalidCode', () async {
    expect(await failureOf(repo.redeem('NOPE', idempotencyKey: 'k')), FailureReason.invalidCode);
  });

  test('vencido, inactiva y límite devuelven su razón', () async {
    expect(await failureOf(repo.redeem('VENCIDO', idempotencyKey: 'a')), FailureReason.expired);
    expect(await failureOf(repo.redeem('INACTIVA', idempotencyKey: 'b')), FailureReason.campaignInactive);
    try {
      await repo.redeem('LIMITE', idempotencyKey: 'c');
      fail('debía lanzar');
    } on GamificationException catch (e) {
      expect(e.reason, FailureReason.rateLimited);
      expect(e.retryAfter, const Duration(seconds: 30));
    }
  });

  group('un uso por código', () {
    test('rejectOnly: segundo intento con otra clave -> alreadyUsed', () async {
      await repo.redeem('RULETA-50K', idempotencyKey: 'k1');
      expect(await failureOf(repo.redeem('RULETA-50K', idempotencyKey: 'k2')), FailureReason.alreadyUsed);
    });

    test('showPrevious: devuelve el resultado anterior marcado alreadyPlayed', () async {
      final first = await repo.redeem('RULETA-REPITE', idempotencyKey: 'k1');
      final again = await repo.redeem('RULETA-REPITE', idempotencyKey: 'k2');
      expect(first.alreadyPlayed, isFalse);
      expect(again.alreadyPlayed, isTrue);
      expect(again.outcome.prize.id, first.outcome.prize.id);
      expect(again.redemptionId, first.redemptionId);
    });

    test('misma Idempotency-Key (reintento tras cierre de app) devuelve lo mismo, sin error', () async {
      final first = await repo.redeem('RULETA-50K', idempotencyKey: 'k1');
      final retry = await repo.redeem('RULETA-50K', idempotencyKey: 'k1');
      expect(retry.redemptionId, first.redemptionId);
      expect(retry.alreadyPlayed, isFalse);
    });
  });

  group('saldo', () {
    test('premio en dinero suma al saldo una sola vez', () async {
      await repo.redeem('RULETA-50K', idempotencyKey: 'k1');
      await repo.redeem('RULETA-50K', idempotencyKey: 'k1'); // reintento
      expect((await repo.getBalance()).amount, 51000);
    });
    test('sin premio y cupón no tocan el saldo', () async {
      await repo.redeem('RULETA-NADA', idempotencyKey: 'a');
      await repo.redeem('RULETA-CUPON', idempotencyKey: 'b');
      expect((await repo.getBalance()).amount, 1000);
    });
  });

  test('giro extra informa extraSpins', () async {
    final r = await repo.redeem('RULETA-EXTRA', idempotencyKey: 'k');
    expect(r.outcome.prize.type, PrizeType.extraSpin);
    expect(r.outcome.extraSpins, 1);
  });

  test('raspa: la cuadrícula ganadora tiene 3 símbolos iguales al id del premio', () async {
    final r = await repo.redeem('RASPA-GANA', idempotencyKey: 'k');
    final grid = (r.outcome.gameResult['grid'] as List).cast<String>();
    expect(grid, hasLength(9));
    expect(grid.where((s) => s == r.outcome.prize.id).length, 3);
  });

  test('raspa sin premio: ningún símbolo aparece 3 veces', () async {
    final r = await repo.redeem('RASPA-NADA', idempotencyKey: 'k');
    final grid = (r.outcome.gameResult['grid'] as List).cast<String>();
    final counts = <String, int>{};
    for (final s in grid) {
      counts[s] = (counts[s] ?? 0) + 1;
    }
    expect(counts.values.every((c) => c < 3), isTrue);
  });
}
