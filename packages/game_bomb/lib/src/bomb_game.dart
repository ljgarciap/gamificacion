import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'bomb_board.dart';
import 'bomb_config.dart';

/// Pantalla de la Bomba 8 (SPEC-005 §3 y §8). El resultado ya lo decidió el servidor: aquí solo se destapan cartas EN ORDEN y se consume la
/// mecha. La habilidad del jugador no cambia el premio.
class BombGame extends StatefulWidget {
  const BombGame({
    super.key,
    required this.session,
    required this.feedbackFactory,
    this.settleDelay = const Duration(milliseconds: 1000),
  });

  final GameSession session;
  final GameFeedback Function(GamePreferences) feedbackFactory;

  /// Pausa con el final a la vista (bomba desactivada o estallada), antes de pasar al resultado.
  final Duration settleDelay;

  @override
  State<BombGame> createState() => _BombGameState();
}

class _BombGameState extends State<BombGame> with SingleTickerProviderStateMixin {
  BombConfig? _config;
  BombBoard? _board;
  late final GameFeedback _feedback;

  // Se crea en initState (no de forma perezosa): con una config inválida nadie lo usa y Flutter lo crearía dentro de dispose().
  late final AnimationController _boom;

  Timer? _settleTimer;
  bool _started = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    _feedback = widget.feedbackFactory(widget.session.preferences);
    _boom = AnimationController(vsync: this, duration: const Duration(milliseconds: 800));
    try {
      _config = BombConfig.fromRedemption(widget.session.redemption);
      _board = BombBoard(_config!.faces);
    } on FormatException {
      _config = null; // Fallback: el premio no debe quedar oculto por una config inválida.
    }
  }

  @override
  void dispose() {
    _settleTimer?.cancel();
    _boom.dispose();
    super.dispose();
  }

  void _begin() {
    if (_started) return;
    _started = true;
    widget.session.onStarted();
  }

  void _onTap(int index) {
    final board = _board;
    if (board == null || _done) return;
    if (!board.flip(index)) return;
    _begin();
    _feedback.tick();
    setState(() {});
    if (board.isOver) _finish();
  }

  void _revealAll() {
    final board = _board;
    if (board == null || _done) return;
    _begin();
    setState(board.revealAll);
    _finish();
  }

  void _finish() {
    final board = _board;
    if (board == null || _done) return;
    final defused = board.status == BombStatus.defused;
    setState(() => _done = true);
    if (defused) {
      _feedback.win();
    } else {
      if (!GamificationScope.reduceMotion(context)) _boom.forward();
      _feedback.lose();
    }
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
    final reduce = GamificationScope.reduceMotion(context);
    final defused = board.status == BombStatus.defused;
    final exploded = board.status == BombStatus.exploded;

    return LayoutBuilder(
      builder: (context, constraints) {
        // Bomba + textos + botón + separaciones; las cartas usan el resto para no necesitar scroll.
        final bombHeight = constraints.maxHeight < 640 ? 90.0 : 120.0;
        final controlsHeight = bombHeight + 255.0;
        const gap = 8.0;
        final cols = config.columns;
        final rows = config.rows;
        final byWidth = (constraints.maxWidth * 0.9 - gap * (cols - 1)) / cols;
        final byHeight = (constraints.maxHeight - controlsHeight - gap * (rows - 1)) / rows;
        final size = max(40.0, min(byWidth, byHeight));
        final boardWidth = size * cols + gap * (cols - 1);

        final status = defused
            ? s.bombDefused
            : exploded
            ? s.bombExploded
            : s.bombEights(board.eights, BombConfig.needed);

        return Center(
          // Por si la pantalla es aún más chica de lo previsto: se desplaza en vez de desbordarse.
          child: SingleChildScrollView(
            child: SizedBox(
              width: max(boardWidth, 280),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    s.bombHint,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 15),
                  ),
                  const SizedBox(height: 6),
                  Semantics(
                    label: s.bombFuse(board.fuseLeft, board.total),
                    child: ExcludeSemantics(
                      child: AnimatedBuilder(
                        animation: _boom,
                        builder: (context, _) => SizedBox(
                          height: bombHeight,
                          child: CustomPaint(
                            key: const ValueKey('bomb-art'),
                            painter: _BombPainter(
                              fuse: board.fuseLeft / board.total,
                              boom: _boom.value,
                              defused: defused,
                              exploded: exploded && reduce,
                              body: theme.navy,
                              fuseColor: theme.accent,
                              spark: theme.error,
                              ok: theme.success,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                  Semantics(
                    liveRegion: true,
                    label: status,
                    child: ExcludeSemantics(
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          for (var i = 0; i < BombConfig.needed; i++)
                            Container(
                              key: ValueKey('bomb-eight-$i'),
                              width: 28,
                              height: 28,
                              margin: const EdgeInsets.symmetric(horizontal: 4),
                              alignment: Alignment.center,
                              decoration: BoxDecoration(
                                shape: BoxShape.circle,
                                color: i < board.eights ? theme.accent : theme.background,
                                border: Border.all(color: theme.navy.withValues(alpha: 0.4)),
                              ),
                              child: Text(
                                BombConfig.eight,
                                style: TextStyle(
                                  fontWeight: FontWeight.w900,
                                  color: i < board.eights ? theme.navy : theme.navy.withValues(alpha: 0.25),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    status,
                    key: const ValueKey('bomb-status'),
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      color: defused ? theme.success : (exploded ? theme.error : theme.navy),
                      fontWeight: FontWeight.w800,
                      fontSize: 16,
                    ),
                  ),
                  Text(
                    defused ? s.prizeDescription(prize) : s.bombFuse(board.fuseLeft, board.total),
                    key: const ValueKey('bomb-fuse'),
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.navy.withValues(alpha: 0.7), fontSize: 13),
                  ),
                  const SizedBox(height: 10),
                  Semantics(
                    label: s.bombLabel(config.cards),
                    container: true,
                    child: Wrap(
                      key: const ValueKey('bomb-board'),
                      alignment: WrapAlignment.center,
                      spacing: gap,
                      runSpacing: gap,
                      children: [
                        for (var i = 0; i < config.cards; i++)
                          SizedBox(
                            width: size,
                            height: size,
                            child: _BombCard(
                              key: ValueKey('bomb-card-$i'),
                              index: i,
                              total: config.cards,
                              face: board.faceAt(i),
                              theme: theme,
                              strings: s,
                              duration: reduce ? Duration.zero : const Duration(milliseconds: 300),
                              onTap: () => _onTap(i),
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    s.bombOrderNote,
                    textAlign: TextAlign.center,
                    style: TextStyle(color: theme.navy.withValues(alpha: 0.55), fontSize: 11),
                  ),
                  const SizedBox(height: 6),
                  OutlinedButton(
                    key: const ValueKey('bomb-reveal-all'),
                    onPressed: _done ? null : _revealAll,
                    child: Text(s.revealAll),
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

/// La bomba: esfera con su mecha. La mecha se acorta con cada carta (la chispa avanza hacia la bomba); al estallar se abre una onda de fuego.
class _BombPainter extends CustomPainter {
  _BombPainter({
    required this.fuse,
    required this.boom,
    required this.defused,
    required this.exploded,
    required this.body,
    required this.fuseColor,
    required this.spark,
    required this.ok,
  });

  /// Cuánta mecha queda (1 = entera, 0 = nada).
  final double fuse;
  final double boom;
  final bool defused;

  /// Estalló con «reducir movimiento»: se muestra el estallido ya terminado, sin animación.
  final bool exploded;
  final Color body;
  final Color fuseColor;
  final Color spark;
  final Color ok;

  @override
  void paint(Canvas canvas, Size size) {
    final cx = size.width / 2;
    final r = size.height * 0.3;
    final cy = size.height * 0.68;
    final blown = exploded || boom > 0;
    final t = exploded && boom == 0 ? 1.0 : boom;

    // Sombra sobre la mesa.
    canvas.drawOval(
      Rect.fromCenter(center: Offset(cx, size.height - 6), width: r * 2.1, height: r * 0.35),
      Paint()
        ..color = Colors.black.withValues(alpha: 0.18)
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
    );

    if (blown && t > 0) {
      // Onda de fuego: crece y se desvanece; los rayos salen de la bomba.
      final fade = (1 - t).clamp(0.0, 1.0);
      canvas.drawCircle(
        Offset(cx, cy),
        r * (0.9 + 2.1 * t),
        Paint()
          ..shader = RadialGradient(
            colors: [
              Colors.yellow.withValues(alpha: 0.9 * fade),
              Colors.orange.withValues(alpha: 0.7 * fade),
              spark.withValues(alpha: 0),
            ],
          ).createShader(Rect.fromCircle(center: Offset(cx, cy), radius: r * (0.9 + 2.1 * t))),
      );
      final rays = Paint()
        ..color = Colors.orange.withValues(alpha: 0.8 * fade)
        ..strokeWidth = 3
        ..strokeCap = StrokeCap.round;
      for (var i = 0; i < 12; i++) {
        final a = i * pi / 6;
        canvas.drawLine(
          Offset(cx + cos(a) * r * (1.1 + 1.6 * t), cy + sin(a) * r * (1.1 + 1.6 * t)),
          Offset(cx + cos(a) * r * (1.5 + 2.0 * t), cy + sin(a) * r * (1.5 + 2.0 * t)),
          rays,
        );
      }
    }

    final alpha = blown ? (1 - t).clamp(0.0, 1.0) : 1.0;
    // Cuerpo con luz arriba a la izquierda.
    canvas.drawCircle(
      Offset(cx, cy),
      r,
      Paint()
        ..shader = RadialGradient(
          center: const Alignment(-0.4, -0.5),
          colors: [
            Color.lerp(body, Colors.white, 0.35)!.withValues(alpha: alpha),
            body.withValues(alpha: alpha),
          ],
        ).createShader(Rect.fromCircle(center: Offset(cx, cy), radius: r)),
    );
    // Casquillo.
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset(cx + r * 0.1, cy - r * 0.98), width: r * 0.55, height: r * 0.3),
        const Radius.circular(4),
      ),
      Paint()..color = Color.lerp(body, Colors.black, 0.35)!.withValues(alpha: alpha),
    );
    if (defused) {
      final check = Path()
        ..moveTo(cx - r * 0.35, cy)
        ..lineTo(cx - r * 0.08, cy + r * 0.3)
        ..lineTo(cx + r * 0.4, cy - r * 0.3);
      canvas.drawPath(
        check,
        Paint()
          ..color = ok
          ..style = PaintingStyle.stroke
          ..strokeWidth = 6
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round,
      );
    }

    if (blown) return;

    // Mecha: sale del casquillo y sube en curva. Lo que queda va desde la bomba hasta la chispa; lo ya quemado, más allá, en gris.
    final start = Offset(cx + r * 0.1, cy - r * 1.1);
    final path = Path()
      ..moveTo(start.dx, start.dy)
      ..quadraticBezierTo(cx + r * 0.1, cy - r * 2.0, cx + r * 1.3, cy - r * 1.75);
    final metric = path.computeMetrics().first;
    final length = metric.length;
    final burnt = (fuse.clamp(0.0, 1.0)) * length;
    canvas.drawPath(
      metric.extractPath(0, burnt),
      Paint()
        ..color = fuseColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = 4
        ..strokeCap = StrokeCap.round,
    );
    canvas.drawPath(
      metric.extractPath(burnt, length),
      Paint()
        ..color = Colors.grey.withValues(alpha: 0.35)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeCap = StrokeCap.round,
    );
    if (!defused) {
      final tip = metric.getTangentForOffset(burnt)!.position;
      canvas.drawCircle(tip, 7, Paint()..color = spark.withValues(alpha: 0.35));
      canvas.drawCircle(tip, 4.5, Paint()..color = Colors.orange);
      canvas.drawCircle(tip, 2.2, Paint()..color = Colors.yellow);
    }
  }

  @override
  bool shouldRepaint(_BombPainter old) =>
      old.fuse != fuse || old.boom != boom || old.defused != defused || old.exploded != exploded || old.body != body;
}

/// Una carta: gira entre su dorso y su cara. El controlador se crea en initState.
class _BombCard extends StatefulWidget {
  const _BombCard({
    super.key,
    required this.index,
    required this.total,
    required this.face,
    required this.theme,
    required this.strings,
    required this.duration,
    required this.onTap,
  });

  final int index;
  final int total;
  final String? face;
  final GamificationTheme theme;
  final GamificationStrings strings;
  final Duration duration;
  final VoidCallback onTap;

  @override
  State<_BombCard> createState() => _BombCardState();
}

class _BombCardState extends State<_BombCard> with SingleTickerProviderStateMixin {
  late final AnimationController _flip;

  bool get _faceUp => widget.face != null;
  bool get _isEight => widget.face == BombConfig.eight;

  @override
  void initState() {
    super.initState();
    _flip = AnimationController(vsync: this, duration: widget.duration, value: _faceUp ? 1 : 0);
  }

  @override
  void didUpdateWidget(_BombCard old) {
    super.didUpdateWidget(old);
    _flip.duration = widget.duration;
    if (_faceUp == (old.face != null)) return;
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

  @override
  Widget build(BuildContext context) {
    final label = _faceUp
        ? widget.strings.cardFaceUp(widget.index + 1, widget.total, widget.face!)
        : widget.strings.cardFaceDown(widget.index + 1, widget.total);
    return Semantics(
      button: true,
      enabled: !_faceUp,
      label: label,
      child: ExcludeSemantics(
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
        onTap: _faceUp ? null : widget.onTap,
        child: Center(
          child: Icon(Icons.local_fire_department_rounded, color: t.surface.withValues(alpha: 0.7), size: 28),
        ),
      ),
    );
  }

  Widget _front() {
    final t = widget.theme;
    final face = widget.face!;
    final icon = _isEight ? null : decorativeSymbolIcon(face);
    return Container(
      key: _isEight ? const ValueKey('bomb-eight-card') : null,
      decoration: BoxDecoration(
        color: _isEight ? t.accent.withValues(alpha: 0.35) : t.surface,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: _isEight ? t.accent : t.navy, width: _isEight ? 3 : 1),
      ),
      padding: const EdgeInsets.all(6),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: _isEight
            ? Text(
                BombConfig.eight,
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 44, color: t.navy),
              )
            : (icon != null
                  ? Icon(icon, size: 40, color: widget.index.isEven ? t.navy : t.primary)
                  : Text(
                      face,
                      style: TextStyle(fontWeight: FontWeight.w700, color: t.navy),
                    )),
      ),
    );
  }
}

/// Si la campaña llega con una Bomba inválida, el usuario igual ve su resultado.
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
