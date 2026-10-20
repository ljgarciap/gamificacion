import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'roulette_config.dart';
import 'roulette_math.dart';
import 'roulette_painter.dart';

/// Pantalla 2a (ux-spec-001). El servidor ya decidió el segmento: aquí solo se anima hasta él.
class RouletteGame extends StatefulWidget {
  const RouletteGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.random,
    this.settleDelay = const Duration(milliseconds: 700),
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Inyectable para tests deterministas; por defecto aleatorio.
  final Random? random;

  /// Pausa con la rueda detenida y el segmento resaltado antes de pasar al resultado.
  final Duration settleDelay;

  @override
  State<RouletteGame> createState() => _RouletteGameState();
}

class _RouletteGameState extends State<RouletteGame> with TickerProviderStateMixin {
  RouletteConfig? _config;
  late final GameFeedback _feedback;
  // Se crean en initState (no de forma perezosa): con una config inválida nadie los usa, y un
  // `late final` con inicializador se crearía por primera vez dentro de dispose(), que Flutter rechaza.
  late final AnimationController _spin;
  late final AnimationController _bounce;
  late final Animation<double> _eased;

  double _target = 0;
  bool _started = false;
  bool _stopped = false;
  bool _reduceMotion = false;
  int _lastSegment = 0;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    _spin = AnimationController(vsync: this);
    _bounce = AnimationController(vsync: this, duration: const Duration(milliseconds: 140));
    _eased = CurvedAnimation(parent: _spin, curve: Curves.easeOutCubic);
    try {
      _config = RouletteConfig.fromRedemption(widget.session.redemption);
      _spin.duration = _config!.duration;
    } on FormatException {
      _config = null; // Se muestra el fallback: el premio no debe quedar oculto por una config mala.
    }
    _spin.addListener(_onTick);
  }

  @override
  void dispose() {
    _spin.dispose();
    _bounce.dispose();
    super.dispose();
  }

  double get _rotation => _target * _eased.value;

  void _onTick() {
    final config = _config;
    // Con "reducir movimiento" el salto al segmento no debe sonar ni vibrar como un giro.
    if (config == null || !_started || _stopped || _reduceMotion) return;
    // Cada vez que una divisoria pasa bajo el puntero: tic + rebote del puntero.
    final segment = RouletteMath.segmentAt(_rotation, config.segments.length);
    if (segment != _lastSegment) {
      _lastSegment = segment;
      _feedback.tick();
      _bounce.forward(from: 0);
    }
  }

  Future<void> _start() async {
    final config = _config;
    if (config == null || _started) return;
    final reduce = GamificationScope.reduceMotion(context);
    final rnd = widget.random ?? Random();
    final offset = (rnd.nextDouble() * 2 - 1) * config.offsetSpread;

    setState(() {
      _started = true;
      _reduceMotion = reduce;
      _target = RouletteMath.finalRotation(
        index: config.targetIndex,
        segments: config.segments.length,
        // Reducir movimiento: sin vueltas extra, giro corto directo al segmento (ux-spec-001).
        fullTurns: reduce ? 0 : config.fullTurns,
        offsetFraction: offset,
      );
      _spin.duration = reduce ? const Duration(milliseconds: 600) : config.duration;
    });
    widget.session.onStarted();

    try {
      await _spin.forward(from: 0).orCancel;
    } on TickerCanceled {
      return; // El widget se desmontó a mitad del giro.
    }
    if (!mounted) return;

    setState(() => _stopped = true);
    if (config.segments[config.targetIndex].isWin) {
      _feedback.win();
    } else {
      _feedback.lose();
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
    return LayoutBuilder(
      builder: (context, constraints) {
        final side = min(constraints.maxWidth * 0.88, constraints.maxHeight * 0.92);
        final hub = side * 0.24;

        return Center(
          child: SizedBox(
            width: side,
            height: side,
            child: Semantics(
              label: scope.strings.rouletteLabel(config.segments.length),
              child: Stack(
                alignment: Alignment.center,
                children: [
                  ExcludeSemantics(
                    child: AnimatedBuilder(
                      animation: _eased,
                      builder: (_, __) => CustomPaint(
                        size: Size.square(side),
                        painter: RoulettePainter(
                          segments: config.segments,
                          theme: theme,
                          rotation: _rotation,
                          highlight: _stopped ? config.targetIndex : null,
                        ),
                      ),
                    ),
                  ),
                  Positioned(
                    top: -side * 0.015,
                    child: ExcludeSemantics(child: _Pointer(bounce: _bounce, size: side * 0.11, theme: theme)),
                  ),
                  SizedBox.square(
                    dimension: hub,
                    child: FilledButton(
                      onPressed: _started ? null : _start,
                      style: FilledButton.styleFrom(
                        padding: EdgeInsets.zero,
                        shape: const CircleBorder(),
                        fixedSize: Size.square(hub),
                        minimumSize: Size.square(hub),
                        backgroundColor: theme.accent,
                        foregroundColor: theme.navy,
                        disabledBackgroundColor: theme.accent,
                        disabledForegroundColor: theme.navy.withValues(alpha: 0.6),
                        side: BorderSide(color: theme.navy, width: 3),
                      ),
                      child: Text(
                        scope.strings.spin,
                        style: TextStyle(fontSize: hub * 0.24, fontWeight: FontWeight.w900),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

/// Puntero fijo arriba; "rebota" cuando una divisoria pasa por debajo.
class _Pointer extends StatelessWidget {
  const _Pointer({required this.bounce, required this.size, required this.theme});

  final Animation<double> bounce;
  final double size;
  final GamificationTheme theme;

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: bounce,
      builder: (_, child) => Transform.rotate(
        angle: -sin(bounce.value * pi) * 0.45,
        alignment: Alignment.topCenter,
        child: child,
      ),
      child: CustomPaint(size: Size(size, size * 1.2), painter: _PointerPainter(theme)),
    );
  }
}

class _PointerPainter extends CustomPainter {
  _PointerPainter(this.theme);
  final GamificationTheme theme;

  @override
  void paint(Canvas canvas, Size size) {
    final path = Path()
      ..moveTo(0, 0)
      ..lineTo(size.width, 0)
      ..lineTo(size.width / 2, size.height)
      ..close();
    canvas
      ..drawPath(path, Paint()..color = theme.accent)
      ..drawPath(
        path,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3
          ..strokeJoin = StrokeJoin.round
          ..color = theme.navy,
      );
  }

  @override
  bool shouldRepaint(_PointerPainter old) => old.theme != theme;
}

/// Si la campaña llega con una ruleta inválida, el usuario igual ve su resultado.
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
