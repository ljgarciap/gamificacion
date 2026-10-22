import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'poker_config.dart';
import 'poker_table.dart';

/// Pantalla del Póker del crupier (SPEC-005 §5 y §8). El resultado ya lo decidió el servidor: aquí solo se abren las cartas de la mesa
/// (solas o a toque) y se muestra la mejor mano que se va teniendo. El crupier no tiene mano propia ni es un rival.
class PokerGame extends StatefulWidget {
  const PokerGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 1600),
    this.interval,
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con la mano resaltada, antes de pasar al resultado.
  final Duration settleDelay;

  /// Cada cuánto abre el crupier la siguiente etapa en modo automático. Solo para pruebas: por defecto lo dice la campaña.
  final Duration? interval;

  @override
  State<PokerGame> createState() => _PokerGameState();
}

class _PokerGameState extends State<PokerGame> {
  PokerConfig? _config;
  PokerTable? _table;
  late final GameFeedback _feedback;

  Timer? _timer;
  Timer? _settleTimer;
  bool _started = false;
  bool _auto = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    try {
      _config = PokerConfig.fromRedemption(widget.session.redemption);
      _table = PokerTable(_config!);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _settleTimer?.cancel();
    super.dispose();
  }

  void _begin() {
    if (_started) return;
    _started = true;
    widget.session.onStarted();
  }

  void _start() {
    _begin();
    setState(() => _auto = true);
    _schedule();
  }

  void _schedule() {
    _timer?.cancel();
    final config = _config;
    if (!_auto || _done || config == null) return;
    _timer = Timer(widget.interval ?? config.interval, () {
      if (!mounted) return;
      _open();
      _schedule();
    });
  }

  void _toggleAuto() {
    setState(() => _auto = !_auto);
    if (_auto) {
      _schedule();
    } else {
      _timer?.cancel();
    }
  }

  void _open() {
    final table = _table;
    if (table == null || _done) return;
    if (!table.next()) return;
    _feedback.tick();
    setState(() {});
    if (table.isOver) _finish();
  }

  void _manualNext() {
    _begin();
    _timer?.cancel();
    setState(() => _auto = false);
    _open();
  }

  void _revealAll() {
    final table = _table;
    if (table == null || _done) return;
    _begin();
    _timer?.cancel();
    setState(() {
      _auto = false;
      table.revealAll();
    });
    _finish();
  }

  void _finish() {
    if (_done) return;
    _timer?.cancel();
    setState(() {
      _done = true;
      _auto = false;
    });
    widget.session.redemption.outcome.prize.isWin ? _feedback.win() : _feedback.lose();
    _settleTimer = Timer(widget.settleDelay, () {
      if (mounted) widget.session.onFinished();
    });
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final config = _config;
    final table = _table;
    if (config == null || table == null) return _Fallback(onContinue: widget.session.onFinished);

    final theme = scope.theme;
    final s = scope.strings;
    final prize = widget.session.redemption.outcome.prize;
    final reduce = GamificationScope.reduceMotion(context);
    final felt = Color.lerp(theme.navy, theme.success, 0.55)!;
    final best = config.best.toSet();

    final message = _done
        ? (config.hand != null
              ? '${s.pokerWon(config.category)} ${s.prizeDescription(prize)}'
              : s.pokerNoWin(config.category))
        : (table.current != null ? s.pokerCurrent(table.current!) : (_started ? ' ' : s.pokerHint));

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = min(constraints.maxWidth, 420.0) - 32;
        final cardW = min(62.0, (width - 28 - 6 - 4 * 8 - 4) / 5);
        final cardH = cardW * 1.4;

        Widget card(String c, {required bool up, required String key, required int pos, required int of}) =>
            _PlayingCard(
              key: ValueKey(key),
              id: key,
              position: pos,
              total: of,
              card: c,
              faceUp: up,
              highlight: _done && best.contains(c),
              dim: _done && !best.contains(c),
              width: cardW,
              height: cardH,
              theme: theme,
              strings: s,
              duration: reduce ? Duration.zero : const Duration(milliseconds: 320),
            );

        return Center(
          child: SingleChildScrollView(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Container(
                      key: const ValueKey('poker-felt'),
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(22),
                        gradient: RadialGradient(colors: [Color.lerp(felt, Colors.white, 0.12)!, felt], radius: 1.1),
                        border: Border.all(color: theme.accent.withValues(alpha: 0.7), width: 3),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.25),
                            blurRadius: 12,
                            offset: const Offset(0, 6),
                          ),
                        ],
                      ),
                      child: Column(
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.casino_rounded, color: Colors.white.withValues(alpha: 0.85), size: 22),
                              const SizedBox(width: 8),
                              Flexible(
                                child: Text(
                                  s.pokerDealer,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: 0.85),
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          Semantics(
                            container: true,
                            explicitChildNodes: true,
                            label: s.pokerTable,
                            child: Row(
                              key: const ValueKey('poker-board'),
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                for (var i = 0; i < 5; i++)
                                  Padding(
                                    padding: EdgeInsets.only(right: i < 4 ? 8 : 0),
                                    child: card(
                                      config.board[i],
                                      up: table.isBoardOpen(i),
                                      key: 'poker-board-$i',
                                      pos: i + 1,
                                      of: 5,
                                    ),
                                  ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 14),
                          Text(
                            s.pokerYourCards,
                            style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 13),
                          ),
                          const SizedBox(height: 6),
                          Semantics(
                            container: true,
                            explicitChildNodes: true,
                            label: s.pokerYourCards,
                            child: Row(
                              key: const ValueKey('poker-hole'),
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                for (var i = 0; i < 2; i++)
                                  Padding(
                                    padding: EdgeInsets.only(right: i == 0 ? 10 : 0),
                                    child: card(config.hole[i], up: true, key: 'poker-hole-$i', pos: i + 1, of: 2),
                                  ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 10),
                      child: Semantics(
                        liveRegion: true,
                        child: Text(
                          message,
                          key: const ValueKey('poker-message'),
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            color: _done ? (config.hand != null ? theme.success : theme.navy) : theme.primary,
                            fontWeight: FontWeight.w800,
                            fontSize: 16,
                          ),
                        ),
                      ),
                    ),
                    if (!_started)
                      FilledButton(
                        key: const ValueKey('poker-start'),
                        onPressed: _start,
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        child: Text(s.pokerStart, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                      )
                    else
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton(
                              key: const ValueKey('poker-auto'),
                              onPressed: _done ? null : _toggleAuto,
                              child: Text(_auto ? s.bingoPause : s.bingoResume),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: FilledButton(
                              key: const ValueKey('poker-next'),
                              onPressed: _done ? null : _manualNext,
                              child: Text(s.pokerNext),
                            ),
                          ),
                        ],
                      ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      key: const ValueKey('poker-reveal-all'),
                      onPressed: _done ? null : _revealAll,
                      child: Text(s.revealAll),
                    ),
                    const SizedBox(height: 12),
                    if (config.legend.isNotEmpty) _legend(config, prize, theme, s),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _legend(PokerConfig config, Prize won, GamificationTheme theme, GamificationStrings s) {
    return Container(
      key: const ValueKey('poker-legend'),
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
            s.pokerPrizes,
            style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 13, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          for (final e in config.legend)
            Container(
              key: _done && e.hand == config.hand
                  ? const ValueKey('poker-legend-win')
                  : ValueKey('poker-legend-row-${e.hand}'),
              margin: const EdgeInsets.symmetric(vertical: 3),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: _done && e.hand == config.hand ? theme.accent.withValues(alpha: 0.3) : theme.background,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: _done && e.hand == config.hand ? theme.accent : Colors.transparent, width: 2),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(s.pokerHand(e.hand), style: TextStyle(color: theme.navy)),
                  ),
                  Text(
                    s.prizeDescription(e.prize),
                    style: TextStyle(color: theme.navy, fontWeight: FontWeight.w800),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Una carta de la baraja: gira entre su dorso y su cara. El controlador se crea en initState.
class _PlayingCard extends StatefulWidget {
  const _PlayingCard({
    super.key,
    required this.id,
    required this.position,
    required this.total,
    required this.card,
    required this.faceUp,
    required this.highlight,
    required this.dim,
    required this.width,
    required this.height,
    required this.theme,
    required this.strings,
    required this.duration,
  });

  final String id;

  /// Posición de la carta en su grupo (para decir «carta 3 de 5, boca abajo»).
  final int position;
  final int total;
  final String card;
  final bool faceUp;
  final bool highlight;
  final bool dim;
  final double width;
  final double height;
  final GamificationTheme theme;
  final GamificationStrings strings;
  final Duration duration;

  @override
  State<_PlayingCard> createState() => _PlayingCardState();
}

class _PlayingCardState extends State<_PlayingCard> with SingleTickerProviderStateMixin {
  late final AnimationController _flip;

  static const _glyph = {'S': '♠', 'H': '♥', 'D': '♦', 'C': '♣'};

  @override
  void initState() {
    super.initState();
    _flip = AnimationController(vsync: this, duration: widget.duration, value: widget.faceUp ? 1 : 0);
  }

  @override
  void didUpdateWidget(_PlayingCard old) {
    super.didUpdateWidget(old);
    _flip.duration = widget.duration;
    if (widget.faceUp == old.faceUp) return;
    if (widget.duration == Duration.zero) {
      _flip.value = widget.faceUp ? 1 : 0;
    } else {
      widget.faceUp ? _flip.forward() : _flip.reverse();
    }
  }

  @override
  void dispose() {
    _flip.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final label = widget.faceUp
        ? widget.strings.pokerCardName(widget.card)
        : widget.strings.cardFaceDown(widget.position, widget.total);
    return Semantics(
      container: true,
      label: widget.faceUp ? label : widget.strings.cardFaceDown(widget.position, widget.total),
      child: ExcludeSemantics(
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 250),
          opacity: widget.dim ? 0.45 : 1,
          child: AnimatedBuilder(
            animation: _flip,
            builder: (context, _) {
              final showFront = _flip.value >= 0.5;
              return Transform(
                alignment: Alignment.center,
                transform: Matrix4.identity()
                  ..setEntry(3, 2, 0.001)
                  ..rotateY(pi * _flip.value),
                child: showFront
                    ? Transform(alignment: Alignment.center, transform: Matrix4.rotationY(pi), child: _front())
                    : _back(),
              );
            },
          ),
        ),
      ),
    );
  }

  Widget _back() {
    final t = widget.theme;
    return Container(
      width: widget.width,
      height: widget.height,
      decoration: BoxDecoration(
        color: t.navy,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.white.withValues(alpha: 0.85), width: 2),
      ),
      child: Center(
        child: Icon(Icons.diamond_outlined, color: t.accent.withValues(alpha: 0.8), size: widget.width * 0.5),
      ),
    );
  }

  Widget _front() {
    final t = widget.theme;
    final rank = widget.card[0] == 'T' ? '10' : widget.card[0];
    final red = widget.card[1] == 'H' || widget.card[1] == 'D';
    final color = red ? t.error : t.navy;
    return Container(
      key: widget.highlight ? ValueKey('${widget.id}-best') : null,
      width: widget.width,
      height: widget.height,
      decoration: BoxDecoration(
        color: t.surface,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(
          color: widget.highlight ? t.accent : t.navy.withValues(alpha: 0.3),
          width: widget.highlight ? 3.5 : 1,
        ),
        boxShadow: widget.highlight ? [BoxShadow(color: t.accent.withValues(alpha: 0.7), blurRadius: 10)] : null,
      ),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              rank,
              style: TextStyle(color: color, fontWeight: FontWeight.w900, fontSize: widget.width * 0.5, height: 1),
            ),
            Text(
              _glyph[widget.card[1]]!,
              style: TextStyle(color: color, fontSize: widget.width * 0.5, height: 1),
            ),
          ],
        ),
      ),
    );
  }
}

/// Si la campaña llega con un Póker inválido, el usuario igual ve su resultado.
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
