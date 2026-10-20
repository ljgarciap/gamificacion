import 'dart:ui' show Color;

import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';

void main() {
  group('formatCop', () {
    test('agrupa miles con punto y prefijo \$', () {
      expect(formatCop(0), r'$ 0');
      expect(formatCop(999), r'$ 999');
      expect(formatCop(1000), r'$ 1.000');
      expect(formatCop(50000), r'$ 50.000');
      expect(formatCop(1234567), r'$ 1.234.567');
    });
    test('negativos', () => expect(formatCop(-2500), r'-$ 2.500'));
  });

  group('Prize', () {
    test('tipo desconocido no rompe: se lee como unknown', () {
      final p = Prize.fromJson({'id': 'x', 'type': 'hologram', 'label': 'Nuevo'});
      expect(p.type, PrizeType.unknown);
      expect(p.isWin, isTrue);
    });
    test('none no cuenta como premio', () {
      expect(const Prize(id: 'n', type: PrizeType.none, label: '-').isWin, isFalse);
    });
    test('roundtrip JSON', () {
      const p = Prize(id: 'a', type: PrizeType.money, label: 'L', amount: 5000);
      final back = Prize.fromJson(p.toJson());
      expect(back.id, 'a');
      expect(back.type, PrizeType.money);
      expect(back.amount, 5000);
    });
  });

  group('GamificationTheme', () {
    test('por defecto es la paleta SuperGIROS', () {
      const t = GamificationTheme();
      expect(t.primary, const Color(0xFF0B4EF0));
      expect(t.accent, const Color(0xFFFFC800));
    });
    test('fromJson sobreescribe solo lo presente', () {
      final t = GamificationTheme.fromJson({'primary': '#112233'});
      expect(t.primary, const Color(0xFF112233));
      expect(t.navy, const GamificationTheme().navy);
    });
    test('hex inválido cae al valor por defecto', () {
      final t = GamificationTheme.fromJson({'primary': 'rojo', 'accent': '#12'});
      expect(t.primary, const GamificationTheme().primary);
      expect(t.accent, const GamificationTheme().accent);
    });
  });

  group('Redemption.fromJson', () {
    test('lee campaña, outcome y banderas', () {
      final r = Redemption.fromJson({
        'redemptionId': 'r1',
        'alreadyPlayed': true,
        'playedAt': '2026-10-20T10:00:00Z',
        'campaign': {
          'id': 'c',
          'name': 'N',
          'gameType': 'roulette',
          'usedCodeBehavior': 'showPrevious',
        },
        'outcome': {
          'prize': {'id': 'p', 'type': 'points', 'label': '10', 'amount': 10},
          'gameResult': {'segmentIndex': 3},
        },
      });
      expect(r.gameType, 'roulette');
      expect(r.alreadyPlayed, isTrue);
      expect(r.campaign.usedCodeBehavior, UsedCodeBehavior.showPrevious);
      expect(r.outcome.gameResult['segmentIndex'], 3);
      expect(r.playedAt, DateTime.utc(2026, 10, 20, 10));
    });
  });
}
