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
  final _byId = <String, Redemption>{};
  final _spinsPlayed = <String, int>{};

  /// Premios de los giros hijos del simulador, en el orden en que se consumen (como la reserva del servidor).
  static const _spinPrizes = <int>[2, 1, 7, 6]; // p10k, sin premio, p100k (y otra vez extra: encadena)

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
    'CONCENTRESE-GANA': 'Concéntrese: gana \$ 10.000',
    'CONCENTRESE-NADA': 'Concéntrese: sin premio',
    'DADOS-GANA': 'Dados: dobles seis, gana \$ 50.000',
    'DADOS-NADA': 'Dados: sin premio',
    'BOMBA-GANA': 'Bomba 8: tres 8, gana \$ 50.000',
    'BOMBA-NADA': 'Bomba 8: la mecha se acaba',
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
    _byId[redemption.redemptionId] = redemption;
    _byIdempotencyKey[idempotencyKey] = redemption;
    final p = redemption.outcome.prize;
    if (p.type == PrizeType.money) _balance += p.amount ?? 0;
    return redemption;
  }

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) async {
    if (latency > Duration.zero) await Future<void>.delayed(latency);

    final replay = _byIdempotencyKey[idempotencyKey];
    if (replay != null) return replay;

    final parent = _byId[parentRedemptionId];
    if (parent == null) throw const GamificationException(FailureReason.noSpinsLeft);
    final played = _spinsPlayed[parentRedemptionId] ?? 0;
    if (played >= parent.outcome.extraSpins) throw const GamificationException(FailureReason.noSpinsLeft);

    // El simulador reparte la «reserva» en orden: el k-ésimo giro de toda la sesión recibe el k-ésimo segmento de la lista.
    final slot = _byId.values.where((r) => r.parentRedemptionId != null).length;
    final index = _spinPrizes[slot % _spinPrizes.length];
    final prize = _rouletteSegments[index];
    _spinsPlayed[parentRedemptionId] = played + 1;
    final child = Redemption(
      redemptionId: 'r-${_byId.length + 1}',
      campaign: parent.campaign,
      outcome: Outcome(
        prize: prize,
        extraSpins: prize.type == PrizeType.extraSpin ? 1 : 0,
        gameResult: {'segmentIndex': index},
      ),
      playedAt: _clock(),
      parentRedemptionId: parentRedemptionId,
      spinNo: played + 1,
    );
    _byId[child.redemptionId] = child;
    _byIdempotencyKey[idempotencyKey] = child;
    if (prize.type == PrizeType.money) _balance += prize.amount ?? 0;
    return child;
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

    if (code == 'CONCENTRESE-GANA' || code == 'CONCENTRESE-NADA') {
      final win = code == 'CONCENTRESE-GANA';
      const prize10k = Prize(id: 'p10k', type: PrizeType.money, label: r'$ 10.000', amount: 10000);
      const noPrize = Prize(id: 'none', type: PrizeType.none, label: 'Sin premio');
      final campaign = Campaign(
        id: 'demo-concentrese',
        name: 'Concéntrese',
        gameType: 'memory',
        title: 'Concéntrese',
        gameConfig: const {'pairs': 6, 'columns': 4, 'flipBackMs': 900},
      );
      // Disposición decidida por el servidor: cada símbolo dos veces; con premio, una de las parejas es la del premio.
      final cards = win
          ? ['star', 'p10k', 'moon', 'sun', 'coin', 'gem', 'moon', 'p10k', 'sun', 'star', 'gem', 'coin']
          : ['star', 'bell', 'moon', 'sun', 'coin', 'gem', 'moon', 'bell', 'sun', 'star', 'gem', 'coin'];
      return (campaign: campaign, outcome: Outcome(prize: win ? prize10k : noPrize, gameResult: {'cards': cards}));
    }

    if (code == 'DADOS-GANA' || code == 'DADOS-NADA') {
      final win = code == 'DADOS-GANA';
      const prize50k = Prize(id: 'p50k', type: PrizeType.money, label: r'$ 50.000', amount: 50000);
      const noPrize = Prize(id: 'none', type: PrizeType.none, label: 'Sin premio');
      final campaign = Campaign(
        id: 'demo-dados',
        name: 'Dados de la suerte',
        gameType: 'dice',
        title: 'Dados de la suerte',
        gameConfig: {
          'diceCount': 2,
          'rollDurationMs': 1800,
          'legend': [
            {
              'prize': prize50k.toJson(),
              'rolls': [
                [1, 1],
                [6, 6],
              ],
            },
            {
              'prize': noPrize.toJson(),
              'rolls': [
                [1, 2],
                [3, 5],
              ],
            },
          ],
        },
      );
      return (
        campaign: campaign,
        outcome: Outcome(
          prize: win ? prize50k : noPrize,
          gameResult: {
            'dice': win ? [6, 6] : [3, 5],
          },
        ),
      );
    }

    if (code == 'BOMBA-GANA' || code == 'BOMBA-NADA') {
      final win = code == 'BOMBA-GANA';
      const prize50k = Prize(id: 'p50k', type: PrizeType.money, label: r'$ 50.000', amount: 50000);
      const noPrize = Prize(id: 'none', type: PrizeType.none, label: 'Sin premio');
      final campaign = Campaign(
        id: 'demo-bomba',
        name: 'Bomba 8',
        gameType: 'bomb',
        title: 'Bomba 8',
        gameConfig: const {'cards': 12, 'columns': 4},
      );
      // Caras EN ORDEN DE VOLTEO (decididas por el servidor): con premio, tres 8 y el tercero entre los últimos; sin premio, solo dos.
      final faces = win
          ? ['star', '8', 'moon', 'coin', '8', 'gem', 'sun', 'bell', 'heart', 'flash', '8', 'star']
          : ['star', '8', 'moon', 'coin', 'sun', 'gem', '8', 'bell', 'heart', 'flash', 'coin', 'sun'];
      return (campaign: campaign, outcome: Outcome(prize: win ? prize50k : noPrize, gameResult: {'faces': faces}));
    }
    return null;
  }
}
