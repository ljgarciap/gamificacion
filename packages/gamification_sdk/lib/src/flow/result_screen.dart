import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:gamification_core/gamification_core.dart';

import 'confetti.dart';

/// Pantalla 3 (ux-spec-001): premio o "sin premio", con acciones configuradas por el host.
class ResultScreen extends StatefulWidget {
  const ResultScreen({super.key, required this.redemption, required this.onContinue, required this.onBackHome});

  final Redemption redemption;
  final VoidCallback onContinue;
  final VoidCallback onBackHome;

  @override
  State<ResultScreen> createState() => _ResultScreenState();
}

class _ResultScreenState extends State<ResultScreen> {
  bool _copied = false;

  static IconData _icon(PrizeType t) => switch (t) {
    PrizeType.money => Icons.payments_rounded,
    PrizeType.coupon => Icons.local_activity_rounded,
    PrizeType.points => Icons.stars_rounded,
    PrizeType.extraSpin => Icons.replay_rounded,
    PrizeType.none => Icons.sentiment_satisfied_rounded,
    PrizeType.unknown => Icons.card_giftcard_rounded,
  };

  Future<void> _copy(String code) async {
    try {
      await Clipboard.setData(ClipboardData(text: code));
    } catch (_) {
      // En un iframe sin permiso de portapapeles la escritura falla: no se anuncia "Copiado".
      return;
    }
    if (mounted) setState(() => _copied = true);
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final s = scope.strings;
    final theme = scope.theme;
    final redemption = widget.redemption;
    final prize = redemption.outcome.prize;
    final won = prize.isWin;
    final celebrate = won && !redemption.alreadyPlayed && !GamificationScope.reduceMotion(context);
    final accent = won ? theme.success : theme.info;

    final headline = won ? s.resultWon : s.resultNone;
    final description = won ? s.prizeDescription(prize) : null;

    return Stack(
      children: [
        SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (redemption.alreadyPlayed && redemption.playedAt != null)
                Container(
                  margin: const EdgeInsets.only(bottom: 16),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: theme.info.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(s.previouslyPlayed(redemption.playedAt!.toLocal()), textAlign: TextAlign.center),
                ),
              // El lector de pantalla anuncia el resultado al aparecer la pantalla.
              Semantics(
                liveRegion: true,
                container: true,
                label: description == null ? headline : '$headline $description',
                child: Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(
                    children: [
                      Container(height: 8, color: accent),
                      Padding(
                        padding: const EdgeInsets.all(24),
                        child: ExcludeSemantics(
                          child: Column(
                            children: [
                              Icon(_icon(prize.type), size: 64, color: accent),
                              const SizedBox(height: 12),
                              Text(
                                headline,
                                textAlign: TextAlign.center,
                                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: theme.navy),
                              ),
                              if (description != null) ...[
                                const SizedBox(height: 8),
                                Text(
                                  description,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(fontSize: 34, fontWeight: FontWeight.w800, color: theme.navy),
                                ),
                              ],
                              if (won && prize.type == PrizeType.money) ...[
                                const SizedBox(height: 8),
                                Text(s.creditedToBalance, style: TextStyle(color: theme.navy.withValues(alpha: 0.7))),
                              ],
                            ],
                          ),
                        ),
                      ),
                      if (prize.type == PrizeType.coupon && prize.couponCode != null)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
                          child: OutlinedButton.icon(
                            onPressed: () => _copy(prize.couponCode!),
                            icon: Icon(_copied ? Icons.check : Icons.copy, size: 18),
                            label: Text('${prize.couponCode}  ·  ${_copied ? s.copied : s.copy}'),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 24),
              FilledButton(onPressed: widget.onContinue, child: Text(s.continueLabel)),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: widget.onBackHome, child: Text(s.backHome)),
            ],
          ),
        ),
        if (celebrate)
          Positioned.fill(child: ConfettiBurst(colors: [theme.accent, theme.primary, theme.info, theme.success])),
      ],
    );
  }
}
