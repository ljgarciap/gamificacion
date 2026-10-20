import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'scratch_config.dart';
import 'scratch_mask.dart';
import 'scratch_painter.dart';
import 'scratch_symbol.dart';

/// Pantalla 2b (ux-spec-001). La cuadrícula ya la decidió el servidor: aquí solo se raspa y se revela.
class ScratchGame extends StatefulWidget {
  const ScratchGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 900),
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con todo revelado y las casillas ganadoras resaltadas, antes de pasar al resultado.
  final Duration settleDelay;

  @override
  State<ScratchGame> createState() => _ScratchGameState();
}

class _ScratchGameState extends State<ScratchGame> with SingleTickerProviderStateMixin {
  ScratchConfig? _config;
  late final GameFeedback _feedback;
  ScratchMask? _mask;

  // Se crea en initState (no de forma perezosa): con una config inválida nadie lo usa, y un
  // `late final` con inicializador se crearía por primera vez dentro de dispose(), que Flutter rechaza.
  late final AnimationController _fade;

  /// Trazos en coordenadas normalizadas (ancho = 1). Se muta y se avisa por [_repaint].
  final Path _strokes = Path();
  final _repaint = ValueNotifier<int>(0);
  final _coverage = ValueNotifier<double>(0);

  bool _started = false;
  bool _revealing = false;
  bool _done = false;
  bool _pulse = false;
  double _width = 1;
  Offset? _last;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    _fade = AnimationController(vsync: this, duration: const Duration(milliseconds: 450));
    try {
      _config = ScratchConfig.fromRedemption(widget.session.redemption);
      _mask = ScratchMask(aspect: _config!.aspect);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
  }

  @override
  void dispose() {
    _fade.dispose();
    _repaint.dispose();
    _coverage.dispose();
    super.dispose();
  }

  void _begin() {
    if (_started) return;
    _started = true;
    widget.session.onStarted();
  }

  Offset _normalize(Offset local) => Offset(local.dx / _width, local.dy / _width);

  void _scratchAt(Offset local, {required bool newStroke}) {
    final config = _config;
    final mask = _mask;
    if (config == null || mask == null || _revealing) return;
    _begin();

    final p = _normalize(local);
    final radius = config.brushWidth / 2 / _width;
    final double coverage;
    if (newStroke || _last == null) {
      _strokes.moveTo(p.dx, p.dy);
      // Un segmento de largo casi cero con punta redonda dibuja un punto: un toque también raspa.
      _strokes.lineTo(p.dx + 0.0001, p.dy);
      coverage = mask.scratch(p, radius);
    } else {
      _strokes.lineTo(p.dx, p.dy);
      coverage = mask.scratchSegment(_last!, p, radius);
    }
    _last = p;
    _repaint.value++;
    _coverage.value = coverage;
    _feedback.tick();
    if (coverage >= config.revealThreshold) _reveal();
  }

  Future<void> _reveal() async {
    final config = _config;
    if (config == null || _revealing) return;
    _begin();
    _revealing = true;
    _mask?.fill();
    _coverage.value = 1;

    final reduce = GamificationScope.reduceMotion(context);
    if (reduce) {
      _fade.value = 1;
    } else {
      try {
        await _fade.forward().orCancel;
      } on TickerCanceled {
        return; // Se desmontó durante el revelado.
      }
    }
    if (!mounted) return;

    setState(() {
      _done = true;
      _pulse = !reduce;
    });
    final prize = widget.session.redemption.outcome.prize;
    prize.isWin ? _feedback.win() : _feedback.lose();

    // Pulso breve de las casillas ganadoras (se omite con "reducir movimiento").
    if (_pulse) {
      await Future<void>.delayed(const Duration(milliseconds: 260));
      if (mounted) setState(() => _pulse = false);
    }

    await Future<void>.delayed(widget.settleDelay);
    if (mounted) widget.session.onFinished();
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final config = _config;
    if (config == null) return _Fallback(onContinue: widget.session.onFinished);

    final theme = scope.theme;
    final s = scope.strings;
    final prize = widget.session.redemption.outcome.prize;
    final winners = config.winningCells(prize).toSet();

    return LayoutBuilder(
      builder: (context, constraints) {
        // La tarjeta se ajusta a lo disponible para no necesitar scroll (que pelearía con el gesto).
        const controlsHeight = 96.0;
        final byWidth = constraints.maxWidth * 0.88;
        final byHeight = (constraints.maxHeight - controlsHeight) * config.cols / config.rows;
        final width = max(120.0, min(byWidth, byHeight));
        final height = width * config.rows / config.cols;
        _width = width;

        return Center(
          child: SizedBox(
            width: width,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Semantics(
                  label: s.scratchLabel(config.rows, config.cols),
                  container: true,
                  child: SizedBox(
                    key: const ValueKey('scratch-card'),
                    width: width,
                    height: height,
                    child: ExcludeSemantics(
                      child: Stack(
                        children: [
                          Positioned.fill(child: _grid(config, prize, winners, theme, s)),
                          Positioned.fill(
                            child: IgnorePointer(
                              ignoring: _revealing,
                              child: GestureDetector(
                                behavior: HitTestBehavior.opaque,
                                onPanStart: (d) => _scratchAt(d.localPosition, newStroke: true),
                                onPanUpdate: (d) => _scratchAt(d.localPosition, newStroke: false),
                                onTapDown: (d) => _scratchAt(d.localPosition, newStroke: true),
                                child: CustomPaint(
                                  painter: ScratchCoverPainter(
                                    strokes: _strokes,
                                    brushWidth: config.brushWidth,
                                    fade: _fade,
                                    label: s.scratchHere,
                                    labelColor: theme.navy,
                                    repaint: Listenable.merge([_repaint, _fade]),
                                  ),
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                ValueListenableBuilder<double>(
                  valueListenable: _coverage,
                  builder: (_, value, __) => ExcludeSemantics(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                        key: const ValueKey('scratch-progress'),
                        value: min(1, value / config.revealThreshold),
                        minHeight: 6,
                        color: theme.primary,
                        backgroundColor: theme.navy.withValues(alpha: 0.12),
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 12),
                // Alternativa sin gestos finos (accesibilidad, RNF-05).
                OutlinedButton(
                  onPressed: _revealing ? null : _reveal,
                  child: Text(s.revealAll),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _grid(ScratchConfig config, Prize prize, Set<int> winners, GamificationTheme theme,
      GamificationStrings s) {
    return Container(
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: theme.surface,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: theme.navy, width: 3),
      ),
      child: Column(
        children: [
          for (var r = 0; r < config.rows; r++)
            Expanded(
              child: Row(
                // Estira las casillas al alto de la fila; centradas quedaban bajas y con huecos.
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  for (var c = 0; c < config.cols; c++)
                    Expanded(child: _cell(config, r * config.cols + c, prize, winners, theme, s)),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _cell(ScratchConfig config, int index, Prize prize, Set<int> winners,
      GamificationTheme theme, GamificationStrings s) {
    final isWinner = _done && winners.contains(index);
    return AnimatedScale(
      scale: isWinner && _pulse ? 1.08 : 1,
      duration: const Duration(milliseconds: 240),
      child: Container(
        key: isWinner ? const ValueKey('scratch-win-cell') : null,
        margin: const EdgeInsets.all(3),
        decoration: BoxDecoration(
          color: isWinner ? theme.accent.withValues(alpha: 0.3) : theme.background,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: isWinner ? theme.accent : theme.navy.withValues(alpha: 0.12),
            width: isWinner ? 3 : 1,
          ),
        ),
        child: ScratchSymbol(
          symbolId: config.grid[index],
          prize: prize,
          strings: s,
          color: index.isEven ? theme.navy : theme.primary,
        ),
      ),
    );
  }
}

/// Si la campaña llega con un raspa inválido, el usuario igual ve su resultado.
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
