import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'dice_config.dart';
import 'dice_face.dart';

/// Pantalla 2d (ux-spec-002). La combinación ya la decidió el servidor: aquí solo se anima la tirada hasta ella.
/// Las caras que cambian durante la tirada son puramente cosméticas y no tienen relación con el resultado.
class DiceGame extends StatefulWidget {
  const DiceGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 900),
    this.faceChange = const Duration(milliseconds: 90),
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con la combinación resaltada, antes de pasar al resultado.
  final Duration settleDelay;

  /// Cada cuánto cambian las caras mientras ruedan.
  final Duration faceChange;

  @override
  State<DiceGame> createState() => _DiceGameState();
}

class _DiceGameState extends State<DiceGame> with SingleTickerProviderStateMixin {
  DiceConfig? _config;
  late final GameFeedback _feedback;

  // Se crea en initState (no de forma perezosa): con una config inválida nadie lo usa, y un `late final` con
  // inicializador se crearía por primera vez dentro de dispose(), que Flutter rechaza.
  late final AnimationController _bounce;

  // Solo para las caras cosméticas de la tirada: NO decide nada (el resultado es el del servidor).
  final _cosmetic = Random();

  Timer? _faceTimer;
  Timer? _landTimer;
  Timer? _settleTimer;
  (int, int) _faces = (1, 1);
  bool _started = false;
  bool _rolling = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    try {
      _config = DiceConfig.fromRedemption(widget.session.redemption);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
    _bounce = AnimationController(vsync: this, duration: _config?.rollDuration ?? const Duration(milliseconds: 1800));
  }

  @override
  void dispose() {
    _faceTimer?.cancel();
    _landTimer?.cancel();
    _settleTimer?.cancel();
    _bounce.dispose();
    super.dispose();
  }

  void _roll() {
    final config = _config;
    if (config == null || _rolling || _done) return;
    if (!_started) {
      _started = true;
      widget.session.onStarted();
    }

    if (GamificationScope.reduceMotion(context)) {
      _land();
      return;
    }
    setState(() => _rolling = true);
    _bounce.forward(from: 0);
    _faceTimer = Timer.periodic(widget.faceChange, (_) {
      if (!mounted) return;
      setState(() => _faces = (_cosmetic.nextInt(6) + 1, _cosmetic.nextInt(6) + 1));
      _feedback.tick();
    });
    _landTimer = Timer(config.rollDuration, _land);
  }

  void _land() {
    final config = _config;
    if (config == null || !mounted) return;
    _faceTimer?.cancel();
    setState(() {
      _faces = config.dice;
      _rolling = false;
      _done = true;
    });
    final prize = widget.session.redemption.outcome.prize;
    prize.isWin ? _feedback.win() : _feedback.lose();
    _settleTimer = Timer(widget.settleDelay, () {
      if (mounted) widget.session.onFinished();
    });
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final config = _config;
    if (config == null) return _Fallback(onContinue: widget.session.onFinished);

    final theme = scope.theme;
    final s = scope.strings;
    final prize = widget.session.redemption.outcome.prize;

    return LayoutBuilder(
      builder: (context, constraints) {
        final compact = constraints.maxHeight < 640;
        final size = compact ? 72.0 : 96.0;
        // Lo que ocupan la pista, los dados, el resultado y el botón; la tabla usa el resto (con su propio scroll si hace falta).
        final fixed = 40 + size + 40 + 56 + 48;
        final legendMax = max(80.0, constraints.maxHeight - fixed);

        return Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    _done ? ' ' : (_rolling ? s.diceRolling : s.diceHint), // al terminar el resultado ya está debajo
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 15),
                  ),
                  const SizedBox(height: 16),
                  Semantics(
                    container: true,
                    liveRegion: _done,
                    label: s.diceLabel(_faces.$1, _faces.$2),
                    child: ExcludeSemantics(
                      child: AnimatedBuilder(
                        animation: _bounce,
                        builder: (context, _) => Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            for (var i = 0; i < 2; i++) ...[
                              if (i == 1) const SizedBox(width: 20),
                              _bouncing(
                                i,
                                DiceFace(
                                  key: ValueKey('dice-$i'),
                                  value: i == 0 ? _faces.$1 : _faces.$2,
                                  size: size,
                                  color: theme.surface,
                                  border: theme.navy,
                                  pip: theme.navy,
                                  glow: _done && prize.isWin ? theme.accent : null,
                                ),
                              ),
                            ],
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  SizedBox(
                    height: 28,
                    child: _done
                        ? Text(
                            s.diceLabel(_faces.$1, _faces.$2),
                            key: const ValueKey('dice-result'),
                            textAlign: TextAlign.center,
                            style: TextStyle(color: theme.navy, fontWeight: FontWeight.w800, fontSize: 18),
                          )
                        : null,
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    key: const ValueKey('dice-roll-button'),
                    onPressed: _rolling || _done ? null : _roll,
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    child: Text(s.rollDice, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                  ),
                  if (config.legend.any((e) => e.prize.isWin)) ...[
                    const SizedBox(height: 16),
                    ConstrainedBox(
                      constraints: BoxConstraints(maxHeight: legendMax),
                      child: SingleChildScrollView(child: _legend(config, prize, theme, s)),
                    ),
                  ],
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  /// Rebote y giro de cada dado mientras ruedan; se amortigua hasta asentarse.
  Widget _bouncing(int i, Widget die) {
    final t = _bounce.value;
    final damp = 1 - t;
    final phase = i * 0.7;
    final dy = -sin((t * 4 + phase) * pi).abs() * 22 * damp;
    final rot = sin((t * 6 + phase) * pi) * 0.5 * damp;
    return Transform.translate(
      offset: Offset(0, _rolling ? dy : 0),
      child: Transform.rotate(angle: _rolling ? rot : 0, child: die),
    );
  }

  Widget _legend(DiceConfig config, Prize won, GamificationTheme theme, GamificationStrings s) {
    final entries = config.legend.where((e) => e.prize.isWin && e.rolls.isNotEmpty).toList();
    return Container(
      key: const ValueKey('dice-legend'),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: theme.navy.withValues(alpha: 0.12)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            s.diceTable,
            style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 13, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          for (final e in entries) _legendRow(e, _done && e.prize.id == won.id, theme, s),
        ],
      ),
    );
  }

  Widget _legendRow(DiceLegendEntry e, bool winner, GamificationTheme theme, GamificationStrings s) {
    return Container(
      key: winner ? const ValueKey('dice-legend-win') : ValueKey('dice-legend-row-${e.prize.id}'),
      margin: const EdgeInsets.symmetric(vertical: 3),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: winner ? theme.accent.withValues(alpha: 0.3) : theme.background,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: winner ? theme.accent : Colors.transparent, width: 2),
      ),
      child: Row(
        children: [
          Expanded(
            flex: 4,
            child: Text(
              s.prizeDescription(e.prize),
              style: TextStyle(color: theme.navy, fontWeight: FontWeight.w800),
            ),
          ),
          Expanded(
            flex: 6,
            child: Wrap(
              alignment: WrapAlignment.end,
              spacing: 6,
              runSpacing: 4,
              children: [
                for (final (a, b) in e.rolls)
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: theme.surface,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: theme.navy.withValues(alpha: 0.2)),
                    ),
                    child: Text(s.diceCombo(a, b), style: TextStyle(color: theme.navy, fontSize: 12)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Si la campaña llega con unos dados inválidos, el usuario igual ve su resultado.
class _Fallback extends StatelessWidget {
  const _Fallback({required this.onContinue});
  final VoidCallback onContinue;

  @override
  Widget build(BuildContext context) {
    final s = GamificationScope.of(context).strings;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(s.unsupportedGame, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            FilledButton(onPressed: onContinue, child: Text(s.continueLabel)),
          ],
        ),
      ),
    );
  }
}
