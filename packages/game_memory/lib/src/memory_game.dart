import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'machine_rival.dart';
import 'memory_board.dart';
import 'memory_config.dart';

/// Pantalla 2c (ux-spec-002). La disposición ya la decidió el servidor: aquí solo se voltean cartas y se buscan parejas.
/// La habilidad del jugador no cambia el premio; solo qué tan rápido termina.
class MemoryGame extends StatefulWidget {
  const MemoryGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 900),
    this.rival,
    this.machineThink = const Duration(milliseconds: 800),
    this.machineGap = const Duration(milliseconds: 650),
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con lo ganador resaltado, antes de pasar al resultado.
  final Duration settleDelay;

  /// Rival simulado (solo si la campaña lo pide con `gameConfig.rival`). Inyectable para que las pruebas sean deterministas.
  final MachineRival? rival;

  /// Pausa antes de que la máquina empiece su turno, y entre sus dos cartas.
  final Duration machineThink;
  final Duration machineGap;

  @override
  State<MemoryGame> createState() => _MemoryGameState();
}

class _MemoryGameState extends State<MemoryGame> {
  MemoryConfig? _config;
  MemoryBoard? _board;
  late final GameFeedback _feedback;

  Timer? _mismatchTimer;
  Timer? _settleTimer;
  Timer? _machineTimer;
  late final MachineRival _rival = widget.rival ?? MachineRival();
  bool _machineTurn = false;
  bool _started = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    try {
      _config = MemoryConfig.fromRedemption(widget.session.redemption);
      _board = MemoryBoard(_config!.cards);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
  }

  @override
  void dispose() {
    _mismatchTimer?.cancel();
    _settleTimer?.cancel();
    _machineTimer?.cancel();
    super.dispose();
  }

  void _begin() {
    if (_started) return;
    _started = true;
    widget.session.onStarted();
  }

  void _onTap(int index) {
    final board = _board;
    final config = _config;
    if (board == null || config == null || _done || _machineTurn) return;

    final outcome = board.flip(index);
    if (outcome == FlipOutcome.ignored) return;
    _begin();
    _feedback.tick();
    setState(() {});

    switch (outcome) {
      case FlipOutcome.mismatch:
        _mismatchTimer = Timer(config.flipBack, () {
          if (!mounted) return;
          setState(board.hidePending);
          _machinePlays();
        });
      case FlipOutcome.match:
        final prize = widget.session.redemption.outcome.prize;
        if ((prize.isWin && board.lastMatch == prize.id) || board.allResolved) {
          _finish();
        } else {
          _machinePlays();
        }
      case FlipOutcome.first || FlipOutcome.ignored:
        break;
    }
  }

  /// Turno de la máquina (si la campaña lo pide). Es solo espectáculo: nunca voltea la pareja premio, así que el jugador es quien la halla.
  void _machinePlays() {
    final config = _config;
    if (config == null || !config.rival || _done || !mounted) return;
    setState(() => _machineTurn = true);
    _machineTimer = Timer(widget.machineThink, () {
      final board = _board;
      if (board == null || !mounted || _done) return;
      final prizeCards = config.prizeCards(widget.session.redemption.outcome.prize).toSet();
      final pick = _rival.pickTurn(board, prizeCards);
      if (pick == null) return _endMachineTurn(); // sin jugada posible sin tocar el premio: pasa
      board.flip(pick.$1);
      _feedback.tick();
      setState(() {});
      _machineTimer = Timer(widget.machineGap, () {
        if (!mounted || _done) return;
        final outcome = board.flip(pick.$2);
        _feedback.tick();
        setState(() {});
        if (outcome == FlipOutcome.mismatch) {
          _mismatchTimer = Timer(config.flipBack, () {
            if (!mounted || _done) return;
            setState(board.hidePending);
            _endMachineTurn();
          });
        } else if (board.allResolved) {
          _finish(); // sin premio: la máquina destapó la última pareja
        } else {
          _endMachineTurn();
        }
      });
    });
  }

  void _endMachineTurn() {
    if (mounted) setState(() => _machineTurn = false);
  }

  void _revealAll() {
    final board = _board;
    if (board == null || _done) return;
    _begin();
    _mismatchTimer?.cancel();
    _machineTimer?.cancel();
    _machineTurn = false;
    setState(board.revealAll);
    _finish();
  }

  void _finish() {
    if (_done) return;
    setState(() => _done = true);
    final prize = widget.session.redemption.outcome.prize;
    prize.isWin ? _feedback.win() : _feedback.lose();
    // Con rival se deja más tiempo para leer quién ganó antes de pasar al resultado.
    final settle = (_config?.rival ?? false) ? widget.settleDelay * 2 : widget.settleDelay;
    _settleTimer = Timer(settle, () {
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
    final prizeCards = config.prizeCards(prize).toSet();
    final reduce = GamificationScope.reduceMotion(context);

    return LayoutBuilder(
      builder: (context, constraints) {
        // Pista + progreso + botón + separaciones. El tablero se ajusta a lo que queda para no necesitar scroll.
        final controlsHeight = config.rival ? 215.0 : 150.0;
        const gap = 8.0;
        final cols = config.columns;
        final rows = config.rows;
        final byWidth = (constraints.maxWidth * 0.88 - gap * (cols - 1)) / cols;
        final byHeight = (constraints.maxHeight - controlsHeight - gap * (rows - 1)) / rows;
        final size = max(40.0, min(byWidth, byHeight));
        final boardWidth = size * cols + gap * (cols - 1);

        return Center(
          child: SizedBox(
            width: boardWidth,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  s.memoryHint,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 15),
                ),
                if (config.rival) ...[
                  const SizedBox(height: 4),
                  Text(
                    s.rivalNote,
                    key: const ValueKey('memory-rival-note'),
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.navy.withValues(alpha: 0.6), fontSize: 12),
                  ),
                  const SizedBox(height: 6),
                  Semantics(
                    liveRegion: true,
                    child: Text(
                      _done
                          ? (prize.isWin ? s.rivalYouWin : s.rivalMachineWins)
                          : (_machineTurn ? s.rivalTurnMachine : s.rivalTurnYou),
                      key: const ValueKey('memory-rival-status'),
                      textAlign: TextAlign.center,
                      style: TextStyle(color: theme.primary, fontWeight: FontWeight.w800),
                    ),
                  ),
                ],
                const SizedBox(height: 12),
                Semantics(
                  label: s.memoryLabel(config.pairs),
                  container: true,
                  child: Wrap(
                    key: const ValueKey('memory-board'),
                    spacing: gap,
                    runSpacing: gap,
                    children: [
                      for (var i = 0; i < config.cards.length; i++)
                        SizedBox(
                          width: size,
                          height: size,
                          child: _MemoryCard(
                            key: ValueKey('memory-card-$i'),
                            index: i,
                            total: config.cards.length,
                            symbol: config.cards[i],
                            state: board.stateAt(i),
                            prize: prize,
                            highlight: _done && prizeCards.contains(i),
                            theme: theme,
                            strings: s,
                            duration: reduce ? Duration.zero : const Duration(milliseconds: 320),
                            onTap: () => _onTap(i),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 12),
                Semantics(
                  liveRegion: true,
                  label: s.memoryProgress(board.foundPairs, config.pairs),
                  child: ExcludeSemantics(
                    child: Text(
                      s.memoryProgress(board.foundPairs, config.pairs),
                      key: const ValueKey('memory-progress'),
                      textAlign: TextAlign.center,
                      style: TextStyle(color: theme.navy, fontWeight: FontWeight.w700),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                // Alternativa sin precisión y salida si el jugador no quiere seguir (RNF-05).
                OutlinedButton(
                  key: const ValueKey('memory-reveal-all'),
                  onPressed: _done ? null : _revealAll,
                  child: Text(s.revealAll),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

/// Una carta: gira entre su dorso y su cara. El controlador se crea en initState (no de forma perezosa).
class _MemoryCard extends StatefulWidget {
  const _MemoryCard({
    super.key,
    required this.index,
    required this.total,
    required this.symbol,
    required this.state,
    required this.prize,
    required this.highlight,
    required this.theme,
    required this.strings,
    required this.duration,
    required this.onTap,
  });

  final int index;
  final int total;
  final String symbol;
  final CardState state;
  final Prize prize;
  final bool highlight;
  final GamificationTheme theme;
  final GamificationStrings strings;
  final Duration duration;
  final VoidCallback onTap;

  @override
  State<_MemoryCard> createState() => _MemoryCardState();
}

class _MemoryCardState extends State<_MemoryCard> with SingleTickerProviderStateMixin {
  late final AnimationController _flip;

  bool get _faceUp => widget.state != CardState.down;
  bool get _isPrize => widget.prize.isWin && widget.symbol == widget.prize.id;

  @override
  void initState() {
    super.initState();
    _flip = AnimationController(vsync: this, duration: widget.duration, value: _faceUp ? 1 : 0);
  }

  @override
  void didUpdateWidget(_MemoryCard old) {
    super.didUpdateWidget(old);
    _flip.duration = widget.duration;
    if (_faceUp == (old.state != CardState.down)) return; // la cara no cambió
    if (widget.duration == Duration.zero) {
      _flip.value = _faceUp ? 1 : 0;
    } else {
      _faceUp ? _flip.forward() : _flip.reverse();
    }
  }

  @override
  void dispose() {
    _flip.dispose();
    super.dispose();
  }

  String get _what => _isPrize ? widget.strings.prizeDescription(widget.prize) : widget.symbol;

  @override
  Widget build(BuildContext context) {
    final label = _faceUp
        ? widget.strings.cardFaceUp(widget.index + 1, widget.total, _what)
        : widget.strings.cardFaceDown(widget.index + 1, widget.total);

    return Semantics(
      button: true,
      enabled: widget.state == CardState.down,
      label: label,
      child: ExcludeSemantics(
        child: AnimatedBuilder(
          animation: _flip,
          builder: (context, _) {
            final angle = pi * _flip.value;
            final showFront = _flip.value >= 0.5;
            return Transform(
              alignment: Alignment.center,
              transform: Matrix4.identity()
                ..setEntry(3, 2, 0.001)
                ..rotateY(angle),
              child: showFront
                  // La cara se contra-gira para que no se vea en espejo.
                  ? Transform(alignment: Alignment.center, transform: Matrix4.rotationY(pi), child: _front())
                  : _back(),
            );
          },
        ),
      ),
    );
  }

  Widget _back() {
    final t = widget.theme;
    return Material(
      color: t.navy,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        focusColor: t.primary.withValues(alpha: 0.4),
        onTap: widget.state == CardState.down ? widget.onTap : null,
        child: Center(child: Icon(Icons.question_mark_rounded, color: t.surface.withValues(alpha: 0.7), size: 28)),
      ),
    );
  }

  Widget _front() {
    final t = widget.theme;
    final matched = widget.state == CardState.matched;
    final highlighted = widget.highlight && _isPrize;
    final color = widget.index.isEven ? t.navy : t.primary;
    final icon = _isPrize ? prizeIcon(widget.prize.type) : decorativeSymbolIcon(widget.symbol);

    return Container(
      key: highlighted ? const ValueKey('memory-prize-card') : null,
      decoration: BoxDecoration(
        color: highlighted ? t.accent.withValues(alpha: 0.3) : t.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: highlighted
              ? t.accent
              : matched
              ? t.success
              : t.navy,
          width: highlighted ? 3 : (matched ? 2 : 1),
        ),
      ),
      padding: const EdgeInsets.all(6),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Opacity(
          opacity: matched && !_isPrize ? 0.55 : 1,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (icon != null) Icon(icon, size: 40, color: color),
              if (_isPrize || icon == null)
                Text(
                  _what,
                  textAlign: TextAlign.center,
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: color),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Si la campaña llega con un Concéntrese inválido, el usuario igual ve su resultado.
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
