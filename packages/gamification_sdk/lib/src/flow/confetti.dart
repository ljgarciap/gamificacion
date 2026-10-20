import 'dart:math';

import 'package:flutter/material.dart';

/// Confeti breve (~2 s) sin dependencias. Determinista por semilla; no intercepta toques.
class ConfettiBurst extends StatefulWidget {
  const ConfettiBurst({super.key, required this.colors, this.particles = 48});

  final List<Color> colors;
  final int particles;

  @override
  State<ConfettiBurst> createState() => _ConfettiBurstState();
}

class _ConfettiBurstState extends State<ConfettiBurst> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 2200))..forward();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: ExcludeSemantics(
        child: AnimatedBuilder(
          animation: _c,
          builder: (_, __) => CustomPaint(
            size: Size.infinite,
            painter: _ConfettiPainter(_c.value, widget.colors, widget.particles),
          ),
        ),
      ),
    );
  }
}

class _ConfettiPainter extends CustomPainter {
  _ConfettiPainter(this.t, this.colors, this.count);

  final double t;
  final List<Color> colors;
  final int count;

  @override
  void paint(Canvas canvas, Size size) {
    if (t >= 1) return;
    final rnd = Random(7);
    final paint = Paint();
    for (var i = 0; i < count; i++) {
      final x0 = rnd.nextDouble() * size.width;
      final speed = 0.6 + rnd.nextDouble() * 0.8;
      final sway = (rnd.nextDouble() - 0.5) * 60;
      final spin = rnd.nextDouble() * pi * 4;
      final y = -20 + t * speed * (size.height + 40);
      final x = x0 + sin(t * 6 + i) * sway;
      paint.color = colors[i % colors.length].withValues(alpha: (1 - t).clamp(0.0, 1.0));
      canvas
        ..save()
        ..translate(x, y)
        ..rotate(spin * t)
        ..drawRect(const Rect.fromLTWH(-4, -2, 8, 4), paint)
        ..restore();
    }
  }

  @override
  bool shouldRepaint(_ConfettiPainter old) => old.t != t;
}
