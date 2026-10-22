import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'bingo_board.dart';
import 'bingo_config.dart';

/// Pantalla del Bingo en vivo (SPEC-005 §4 y §8). El resultado ya lo decidió el servidor: aquí solo salen las bolas (solas o a toque) y se
/// marcan en el cartón. La habilidad del jugador no cambia el premio.
class BingoGame extends StatefulWidget {
  const BingoGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 1500),
    this.interval,
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con el final a la vista, antes de pasar al resultado.
  final Duration settleDelay;

  /// Cada cuánto sale una bola en modo automático. Solo para pruebas: por defecto lo dice la campaña (`drawIntervalMs`).
  final Duration? interval;

  @override
  State<BingoGame> createState() => _BingoGameState();
}

class _BingoGameState extends State<BingoGame> {
  BingoConfig? _config;
  BingoBoard? _board;
  late final GameFeedback _feedback;

  Timer? _drawTimer;
  Timer? _settleTimer;
  bool _started = false;
  bool _auto = false;
  bool _done = false;
  BingoEvent? _lastEvent;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    try {
      _config = BingoConfig.fromRedemption(widget.session.redemption);
      _board = BingoBoard(_config!);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
  }

  @override
  void dispose() {
    _drawTimer?.cancel();
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
    _drawTimer?.cancel();
    final board = _board;
    final config = _config;
    if (!_auto || _done || board == null || config == null) return;
    _drawTimer = Timer(widget.interval ?? config.interval, () {
      if (!mounted) return;
      _draw();
      _schedule();
    });
  }

  void _toggleAuto() {
    setState(() => _auto = !_auto);
    if (_auto) {
      _schedule();
    } else {
      _drawTimer?.cancel();
    }
  }

  void _draw() {
    final board = _board;
    if (board == null || _done) return;
    final draw = board.next();
    if (draw == null) return;
    _feedback.tick();
    setState(() {
      if (draw.events.isNotEmpty) _lastEvent = draw.events.last;
    });
    if (board.isOver) _finish();
  }

  void _manualNext() {
    _begin();
    _drawTimer?.cancel();
    setState(() => _auto = false);
    _draw();
  }

  void _revealAll() {
    final board = _board;
    if (board == null || _done) return;
    _begin();
    _drawTimer?.cancel();
    setState(() {
      _auto = false;
      board.revealAll();
      final ev = board.happened;
      if (ev.isNotEmpty) _lastEvent = ev.last;
    });
    _finish();
  }

  void _finish() {
    if (_done) return;
    _drawTimer?.cancel();
    setState(() {
      _done = true;
      _auto = false;
    });
    final wins = widget.session.redemption.outcome.prize.isWin;
    wins ? _feedback.win() : _feedback.lose();
    _settleTimer = Timer(widget.settleDelay, () {
      if (mounted) widget.session.onFinished();
    });
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final config = _config;
    final board = _board;
    if (config == null || board == null) return _Fallback(onContinue: widget.session.onFinished);

    final theme = scope.theme;
    final s = scope.strings;
    final prize = widget.session.redemption.outcome.prize;
    final wins = prize.isWin;
    final recent = board.recent();

    final message = _done
        ? (wins ? s.bingoWon(config.pattern!) : s.bingoNoWin)
        : (_lastEvent != null ? s.bingoEvent(_lastEvent!.pattern) : (_started ? ' ' : s.bingoHint));

    // Casillas de los patrones ya completados (en curso) y, al final, las del que paga.
    final doneCells = <(int, int)>{for (final e in board.happened) ...e.cells};
    final winCells = _done && wins ? config.cells!.toSet() : const <(int, int)>{};

    return LayoutBuilder(
      builder: (context, constraints) {
        final width = min(constraints.maxWidth, 420.0) - 32;
        final cell = min(60.0, width / 5 - 6);
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
                    // Bola actual y las últimas.
                    Semantics(
                      liveRegion: true,
                      label: recent.isEmpty
                          ? s.bingoLabel(config.draws)
                          : s.bingoBall(BingoConfig.letterFor(recent.first), recent.first),
                      child: ExcludeSemantics(
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            _Ball(
                              key: const ValueKey('bingo-ball'),
                              number: recent.isEmpty ? null : recent.first,
                              size: 76,
                              theme: theme,
                            ),
                            const SizedBox(width: 14),
                            for (final n in recent.skip(1))
                              Padding(
                                padding: const EdgeInsets.symmetric(horizontal: 3),
                                child: _Ball(number: n, size: 36, theme: theme, muted: true),
                              ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      s.bingoDrawn(board.drawn, config.draws),
                      key: const ValueKey('bingo-drawn'),
                      textAlign: TextAlign.center,
                      style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 13),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Text(
                        message,
                        key: const ValueKey('bingo-message'),
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: _done ? (wins ? theme.success : theme.navy) : theme.primary,
                          fontWeight: FontWeight.w800,
                          fontSize: 16,
                        ),
                      ),
                    ),
                    // Cartón.
                    Semantics(
                      container: true,
                      label: s.bingoLabel(config.draws),
                      child: Column(
                        key: const ValueKey('bingo-card'),
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              for (var c = 0; c < 5; c++)
                                SizedBox(
                                  width: cell + 6,
                                  child: ExcludeSemantics(
                                    child: Text(
                                      'BINGO'[c],
                                      textAlign: TextAlign.center,
                                      style: TextStyle(color: theme.primary, fontWeight: FontWeight.w900, fontSize: 20),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                          for (var r = 0; r < 5; r++)
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                for (var c = 0; c < 5; c++)
                                  _CardCell(
                                    key: ValueKey('bingo-cell-$r-$c'),
                                    number: config.card[r][c],
                                    size: cell,
                                    marked: board.isMarked(r, c),
                                    completed: doneCells.contains((r, c)),
                                    winner: winCells.contains((r, c)),
                                    theme: theme,
                                    strings: s,
                                  ),
                              ],
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    // Controles.
                    if (!_started)
                      FilledButton(
                        key: const ValueKey('bingo-start'),
                        onPressed: _start,
                        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                        child: Text(s.bingoStart, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                      )
                    else
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton(
                              key: const ValueKey('bingo-auto'),
                              onPressed: _done ? null : _toggleAuto,
                              child: Text(_auto ? s.bingoPause : s.bingoResume),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: FilledButton(
                              key: const ValueKey('bingo-next'),
                              onPressed: _done ? null : _manualNext,
                              child: Text(s.bingoNext),
                            ),
                          ),
                        ],
                      ),
                    const SizedBox(height: 8),
                    OutlinedButton(
                      key: const ValueKey('bingo-reveal-all'),
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

  Widget _legend(BingoConfig config, Prize won, GamificationTheme theme, GamificationStrings s) {
    return Container(
      key: const ValueKey('bingo-legend'),
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
            s.bingoTable,
            style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 13, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 8),
          for (final e in config.legend)
            Container(
              key: _done && e.prize.id == won.id
                  ? const ValueKey('bingo-legend-win')
                  : ValueKey('bingo-legend-row-${e.pattern}'),
              margin: const EdgeInsets.symmetric(vertical: 3),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
              decoration: BoxDecoration(
                color: _done && e.prize.id == won.id ? theme.accent.withValues(alpha: 0.3) : theme.background,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: _done && e.prize.id == won.id ? theme.accent : Colors.transparent, width: 2),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Text(s.bingoPattern(e.pattern), style: TextStyle(color: theme.navy)),
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

/// Una bola: círculo con la letra y el número de la bola.
class _Ball extends StatelessWidget {
  const _Ball({super.key, required this.number, required this.size, required this.theme, this.muted = false});

  final int? number;
  final double size;
  final GamificationTheme theme;
  final bool muted;

  @override
  Widget build(BuildContext context) {
    final n = number;
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 250),
      transitionBuilder: (child, anim) => ScaleTransition(scale: anim, child: child),
      child: Container(
        key: ValueKey('ball-${n ?? 'none'}-$size'),
        width: size,
        height: size,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            center: const Alignment(-0.35, -0.4),
            colors: [
              Color.lerp(n == null ? theme.background : theme.primary, Colors.white, 0.45)!,
              n == null ? theme.background : (muted ? theme.navy : theme.primary),
            ],
          ),
          border: Border.all(color: theme.navy.withValues(alpha: 0.35)),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: 0.18), blurRadius: 6, offset: const Offset(0, 3)),
          ],
        ),
        child: n == null
            ? Text(
                '?',
                style: TextStyle(
                  color: theme.navy.withValues(alpha: 0.4),
                  fontWeight: FontWeight.w900,
                  fontSize: size * 0.4,
                ),
              )
            : Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    BingoConfig.letterFor(n),
                    style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w800, fontSize: size * 0.22),
                  ),
                  Text(
                    '$n',
                    style: TextStyle(color: Colors.white, fontWeight: FontWeight.w900, fontSize: size * 0.36),
                  ),
                ],
              ),
      ),
    );
  }
}

/// Una casilla del cartón: número, ficha si está marcada, borde si forma parte de un patrón completado y brillo si es el que paga.
class _CardCell extends StatelessWidget {
  const _CardCell({
    super.key,
    required this.number,
    required this.size,
    required this.marked,
    required this.completed,
    required this.winner,
    required this.theme,
    required this.strings,
  });

  final int? number;
  final double size;
  final bool marked;
  final bool completed;
  final bool winner;
  final GamificationTheme theme;
  final GamificationStrings strings;

  @override
  Widget build(BuildContext context) {
    final n = number;
    final free = n == null;
    final label = free ? strings.bingoFree : strings.bingoBall(BingoConfig.letterFor(n), n);
    return Semantics(
      label: label,
      selected: marked,
      child: ExcludeSemantics(
        child: Container(
          width: size,
          height: size,
          margin: const EdgeInsets.all(3),
          decoration: BoxDecoration(
            color: winner ? theme.accent.withValues(alpha: 0.45) : theme.surface,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: winner ? theme.accent : (completed ? theme.success : theme.navy.withValues(alpha: 0.25)),
              width: winner || completed ? 3 : 1,
            ),
            boxShadow: winner ? [BoxShadow(color: theme.accent.withValues(alpha: 0.6), blurRadius: 10)] : null,
          ),
          alignment: Alignment.center,
          child: free
              ? Icon(Icons.star_rounded, color: theme.accent, size: size * 0.6)
              : Stack(
                  alignment: Alignment.center,
                  children: [
                    if (marked)
                      Container(
                        width: size * 0.78,
                        height: size * 0.78,
                        decoration: BoxDecoration(shape: BoxShape.circle, color: theme.primary.withValues(alpha: 0.9)),
                      ),
                    Text(
                      '$n',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: size * 0.38,
                        color: marked ? Colors.white : theme.navy,
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

/// Si la campaña llega con un Bingo inválido, el usuario igual ve su resultado.
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
