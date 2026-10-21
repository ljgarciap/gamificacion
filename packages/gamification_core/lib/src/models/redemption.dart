import 'campaign.dart';
import 'prize.dart';

/// Resultado ya decidido por el servidor.
class Outcome {
  const Outcome({required this.prize, this.extraSpins = 0, this.gameResult = const {}});

  factory Outcome.fromJson(Map<String, dynamic> json) => Outcome(
    prize: Prize.fromJson((json['prize'] as Map).cast<String, dynamic>()),
    extraSpins: (json['extraSpins'] as num?)?.toInt() ?? 0,
    gameResult: (json['gameResult'] as Map?)?.cast<String, dynamic>() ?? const {},
  );

  final Prize prize;

  /// Giros adicionales concedidos; cada uno llega como una nueva redención hija.
  final int extraSpins;

  /// Datos que el juego necesita para representar el resultado
  /// (ej. `{segmentIndex: 3}` en la ruleta, `{grid: [...]}` en el raspa).
  final Map<String, dynamic> gameResult;
}

class Redemption {
  const Redemption({
    required this.redemptionId,
    required this.campaign,
    required this.outcome,
    this.alreadyPlayed = false,
    this.playedAt,
    this.parentRedemptionId,
    this.spinNo,
  });

  factory Redemption.fromJson(Map<String, dynamic> json) => Redemption(
    redemptionId: json['redemptionId'] as String,
    campaign: Campaign.fromJson((json['campaign'] as Map).cast<String, dynamic>()),
    outcome: Outcome.fromJson((json['outcome'] as Map).cast<String, dynamic>()),
    alreadyPlayed: json['alreadyPlayed'] as bool? ?? false,
    playedAt: json['playedAt'] == null ? null : DateTime.parse(json['playedAt'] as String),
    parentRedemptionId: json['parentRedemptionId'] as String?,
    spinNo: (json['spinNo'] as num?)?.toInt(),
  );

  final String redemptionId;
  final Campaign campaign;
  final Outcome outcome;

  /// true si el código ya se había jugado y la campaña usa [UsedCodeBehavior.showPrevious].
  final bool alreadyPlayed;
  final DateTime? playedAt;

  /// Solo en un giro hijo del giro extra: la redención que concedió el giro y su número dentro de ella.
  final String? parentRedemptionId;
  final int? spinNo;

  String get gameType => campaign.gameType;
}
