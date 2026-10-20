import '../models/campaign.dart';
import '../models/failure.dart';
import '../models/money.dart';
import '../models/prize.dart';
import '../models/redemption.dart';
import '../models/theme.dart';
import 'repository.dart';

/// Backend simulado y determinista para desarrollo, tests y demo (RF-08).
/// Reproduce las reglas del servidor real: redención atómica, un uso por código,
/// idempotencia por clave y comportamiento de código usado según la campaña.
///
/// Códigos de prueba: ver [demoCodes].
class MockRepository implements GamificationRepository {
  MockRepository({int initialBalance = 120000, this.latency = Duration.zero, DateTime Function()? clock})
    : _balance = initialBalance,
      _clock = clock ?? DateTime.now;

  final Duration latency;
  final DateTime Function() _clock;
  int _balance;

  final _byIdempotencyKey = <String, Redemption>{};
  final _byCode = <String, Redemption>{};

  static const _rouletteSegments = <Prize>[
    Prize(id: 'p50k', type: PrizeType.money, label: r'$ 50.000', amount: 50000),
    Prize(id: 'none-a', type: PrizeType.none, label: 'Sin premio'),
    Prize(id: 'p10k', type: PrizeType.money, label: r'$ 10.000', amount: 10000),
    Prize(id: 'pts', type: PrizeType.points, label: '250 puntos', amount: 250),
    Prize(id: 'none-b', type: PrizeType.none, label: 'Sin premio'),
    Prize(id: 'cupon', type: PrizeType.coupon, label: 'Cupón 20%', couponCode: 'GIRA20'),
    Prize(id: 'extra', type: PrizeType.extraSpin, label: 'Gira de nuevo'),
    Prize(id: 'p100k', type: PrizeType.money, label: r'$ 100.000', amount: 100000),
  ];

  /// Código -> (campaña, índice de segmento / símbolo ganador). Los prefijos no distinguen mayúsculas.
  static const demoCodes = <String, String>{
    'RULETA-50K': 'Ruleta: gana \$ 50.000 (segmento 0)',
    'RULETA-NADA': 'Ruleta: sin premio (segmento 1)',
    'RULETA-PUNTOS': 'Ruleta: 250 puntos (segmento 3)',
    'RULETA-CUPON': 'Ruleta: cupón (segmento 5)',
    'RULETA-EXTRA': 'Ruleta: giro extra (segmento 6)',
    'RULETA-REPITE': 'Ruleta con showPrevious al reutilizar',
    'RASPA-GANA': 'Raspa: gana \$ 10.000',
    'RASPA-NADA': 'Raspa: sin premio',
    'VENCIDO': 'Error: código vencido',
    'INACTIVA': 'Error: campaña inactiva',
    'LIMITE': 'Error: demasiados intentos',
  };

  static const _rouletteIndex = {
    'RULETA-50K': 0,
    'RULETA-NADA': 1,
    'RULETA-PUNTOS': 3,
    'RULETA-CUPON': 5,
    'RULETA-EXTRA': 6,
    'RULETA-REPITE': 2,
  };

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async {
    if (latency > Duration.zero) await Future<void>.delayed(latency);

    final normalized = code.trim().toUpperCase();

    final replay = _byIdempotencyKey[idempotencyKey];
    if (replay != null) return replay;

    switch (normalized) {
      case 'VENCIDO':
        throw const GamificationException(FailureReason.expired);
      case 'INACTIVA':
        throw const GamificationException(FailureReason.campaignInactive);
      case 'LIMITE':
        throw const GamificationException(FailureReason.rateLimited, retryAfter: Duration(seconds: 30));
    }

    final previous = _byCode[normalized];
    if (previous != null) {
      if (previous.campaign.usedCodeBehavior == UsedCodeBehavior.showPrevious) {
        final again = Redemption(
          redemptionId: previous.redemptionId,
          campaign: previous.campaign,
          outcome: previous.outcome,
          alreadyPlayed: true,
          playedAt: previous.playedAt,
        );
        _byIdempotencyKey[idempotencyKey] = again;
        return again;
      }
      throw const GamificationException(FailureReason.alreadyUsed);
    }

    final built = _build(normalized);
    if (built == null) throw const GamificationException(FailureReason.invalidCode);

    // Redención atómica: se registra antes de devolver, igual que el servidor real.
    final redemption = Redemption(
      redemptionId: 'r-${_byCode.length + 1}',
      campaign: built.campaign,
      outcome: built.outcome,
      playedAt: _clock(),
    );
    _byCode[normalized] = redemption;
    _byIdempotencyKey[idempotencyKey] = redemption;
    final p = redemption.outcome.prize;
    if (p.type == PrizeType.money) _balance += p.amount ?? 0;
    return redemption;
  }

  @override
  Future<Balance> getBalance() async => Balance(_balance);

  ({Campaign campaign, Outcome outcome})? _build(String code) {
    final rIndex = _rouletteIndex[code];
    if (rIndex != null) {
      final campaign = Campaign(
        id: 'demo-ruleta',
        name: 'Gira y Gana',
        gameType: 'roulette',
        theme: const GamificationTheme(),
        title: 'Gira y Gana',
        helpText: 'Tu código está en el comprobante de tu transacción.',
        usedCodeBehavior: code == 'RULETA-REPITE' ? UsedCodeBehavior.showPrevious : UsedCodeBehavior.rejectOnly,
        gameConfig: {
          'durationMs': 4600,
          'fullTurns': 5,
          'segments': [for (final p in _rouletteSegments) p.toJson()],
        },
      );
      final prize = _rouletteSegments[rIndex];
      return (
        campaign: campaign,
        outcome: Outcome(
          prize: prize,
          extraSpins: prize.type == PrizeType.extraSpin ? 1 : 0,
          gameResult: {'segmentIndex': rIndex},
        ),
      );
    }

    if (code == 'RASPA-GANA' || code == 'RASPA-NADA') {
      final win = code == 'RASPA-GANA';
      const prize10k = Prize(id: 'p10k', type: PrizeType.money, label: r'$ 10.000', amount: 10000);
      const noPrize = Prize(id: 'none', type: PrizeType.none, label: 'Sin premio');
      final campaign = Campaign(
        id: 'demo-raspa',
        name: 'Raspa y Gana',
        gameType: 'scratch',
        title: 'Raspa y Gana',
        gameConfig: const {'rows': 3, 'cols': 3, 'revealThreshold': 0.6},
      );
      // Cuadrícula decidida por el servidor: 3 símbolos iguales = premio.
      final grid = win
          ? ['p10k', 'star', 'p10k', 'moon', 'p10k', 'sun', 'star', 'moon', 'sun']
          : ['star', 'moon', 'sun', 'coin', 'gem', 'star', 'moon', 'sun', 'coin'];
      return (campaign: campaign, outcome: Outcome(prize: win ? prize10k : noPrize, gameResult: {'grid': grid}));
    }
    return null;
  }
}
