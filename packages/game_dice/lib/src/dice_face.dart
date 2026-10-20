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
    final body = RRect.fromRectAndRadius(Rect.fromLTWH(2, 2, s - 4, s - 4), Radius.circular(s * 0.19));
    if (glow != null) {
      canvas.drawRRect(
        body.inflate(2),
        Paint()
          ..color = glow!.withValues(alpha: 0.55)
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 8),
      );
    }
    canvas.drawRRect(body, Paint()..color = color);
    canvas.drawRRect(
      body,
      Paint()
        ..color = border
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3,
    );
    final inset = s * 0.24;
    final step = (s - inset * 2) / 2;
    final paint = Paint()..color = pip;
    for (final (c, r) in _pips[value.clamp(1, 6)]!) {
      canvas.drawCircle(Offset(inset + c * step, inset + r * step), s * 0.075, paint);
    }
  }

  @override
  bool shouldRepaint(_DicePainter old) =>
      old.value != value || old.color != color || old.border != border || old.pip != pip || old.glow != glow;
}
