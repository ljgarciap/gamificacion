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

/// Giros extra que una redención todavía tiene por jugar (el servidor los informa al volver a ver un resultado ya jugado).
class SpinEntitlement {
  const SpinEntitlement({required this.redemptionId, required this.remaining, required this.played});

  factory SpinEntitlement.fromJson(Map<String, dynamic> json) => SpinEntitlement(
    redemptionId: json['redemptionId'] as String,
    remaining: (json['remaining'] as num).toInt(),
    played: (json['played'] as num).toInt(),
  );

  final String redemptionId;
  final int remaining;

  /// Giros ya jugados de esta redención: el siguiente se pide con la clave `<redemptionId>:<played + 1>`.
  final int played;
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
    this.pendingSpins = const [],
  });

  factory Redemption.fromJson(Map<String, dynamic> json) => Redemption(
    redemptionId: json['redemptionId'] as String,
    campaign: Campaign.fromJson((json['campaign'] as Map).cast<String, dynamic>()),
    outcome: Outcome.fromJson((json['outcome'] as Map).cast<String, dynamic>()),
    alreadyPlayed: json['alreadyPlayed'] as bool? ?? false,
    playedAt: json['playedAt'] == null ? null : DateTime.parse(json['playedAt'] as String),
    parentRedemptionId: json['parentRedemptionId'] as String?,
    spinNo: (json['spinNo'] as num?)?.toInt(),
    pendingSpins: [
      for (final s in (json['pendingSpins'] as List?) ?? const [])
        SpinEntitlement.fromJson((s as Map).cast<String, dynamic>()),
    ],
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

  /// Solo en un resultado ya jugado (`alreadyPlayed`): los giros que aún quedan, para recuperarlos al volver a abrir la app.
  final List<SpinEntitlement> pendingSpins;

  String get gameType => campaign.gameType;
}
