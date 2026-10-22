import 'package:flutter/material.dart';

/// Posiciones de los puntos de cada cara en una rejilla de 3×3 (columna, fila), de 0 a 2.
const _pips = <int, List<(int, int)>>{
  1: [(1, 1)],
  2: [(0, 0), (2, 2)],
  3: [(0, 0), (1, 1), (2, 2)],
  4: [(0, 0), (2, 0), (0, 2), (2, 2)],
  5: [(0, 0), (2, 0), (1, 1), (0, 2), (2, 2)],
  6: [(0, 0), (2, 0), (0, 1), (2, 1), (0, 2), (2, 2)],
};

/// Un dado dibujado con `CustomPainter` (sin imágenes): cuerpo redondeado y puntos.
class DiceFace extends StatelessWidget {
  const DiceFace({
    super.key,
    required this.value,
    required this.size,
    required this.color,
    required this.border,
    required this.pip,
    this.glow,
  });

  final int value;
  final double size;
  final Color color;
  final Color border;
  final Color pip;

  /// Resalta el dado (tirada terminada con premio).
  final Color? glow;

  @override
  Widget build(BuildContext context) => CustomPaint(
    size: Size.square(size),
    painter: _DicePainter(value: value, color: color, border: border, pip: pip, glow: glow),
  );
}

class _DicePainter extends CustomPainter {
  _DicePainter({required this.value, required this.color, required this.border, required this.pip, this.glow});

  final int value;
  final Color color;
  final Color border;
  final Color pip;
  final Color? glow;

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.width;
    final rect = Rect.fromLTWH(3, 2, s - 6, s - 6);
    final body = RRect.fromRectAndRadius(rect, Radius.circular(s * 0.2));

    if (glow != null) {
      canvas.drawRRect(
        body.inflate(3),
        Paint()
          ..color = glow!.withValues(alpha: 0.7)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 10),
      );
    }
    // Sombra sobre la mesa.
    canvas.drawRRect(
      body.shift(Offset(0, s * 0.06)),
      Paint()
        ..color = Colors.black.withValues(alpha: 0.22)
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, s * 0.06),
    );
    // Cuerpo con degradado (luz arriba a la izquierda) para que se vea volumen.
    canvas.drawRRect(
      body,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color.lerp(color, Colors.white, 0.55)!, color, Color.lerp(color, Colors.black, 0.14)!],
          stops: const [0, 0.5, 1],
        ).createShader(rect),
    );
    // Brillo del borde superior.
    canvas.drawRRect(
      body.deflate(1.5),
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [Colors.white.withValues(alpha: 0.9), Colors.white.withValues(alpha: 0)],
        ).createShader(rect),
    );
    canvas.drawRRect(
      body,
      Paint()
        ..color = border.withValues(alpha: 0.85)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );

    final inset = s * 0.25;
    final step = (s - 6 - inset * 2) / 2;
    final r = s * 0.078;
    for (final (c, row) in _pips[value.clamp(1, 6)]!) {
      final center = Offset(3 + inset + c * step, 2 + inset + row * step);
      // Punto hundido: degradado radial con un toque de luz.
      canvas.drawCircle(
        center,
        r,
        Paint()
          ..shader = RadialGradient(
            center: const Alignment(-0.35, -0.35),
            colors: [Color.lerp(pip, Colors.white, 0.35)!, pip],
          ).createShader(Rect.fromCircle(center: center, radius: r)),
      );
    }
  }

  @override
  bool shouldRepaint(_DicePainter old) =>
      old.value != value || old.color != color || old.border != border || old.pip != pip || old.glow != glow;
}
