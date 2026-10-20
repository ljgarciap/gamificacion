import 'dart:math';

import 'package:flutter/material.dart';

/// Capa plateada que se borra con el dedo. Dibuja la cubierta y "perfora" los trazos con
/// `BlendMode.clear` dentro de una capa aparte, de modo que lo raspado deja ver lo de abajo.
///
/// Los trazos viven en coordenadas normalizadas (ancho = 1); se escalan al pintar.
/// Se repinta solo con [repaint] (trazos nuevos) o [fade] (revelado), sin reconstruir widgets.
class ScratchCoverPainter extends CustomPainter {
  ScratchCoverPainter({
    required this.strokes,
    required this.brushWidth,
    required this.fade,
    required this.label,
    required this.labelColor,
    required Listenable repaint,
  }) : super(repaint: repaint);

  final Path strokes;
  final double brushWidth;

  /// 0 = cubierta completa, 1 = desvanecida del todo.
  final Animation<double> fade;
  final String label;
  final Color labelColor;

  @override
  void paint(Canvas canvas, Size size) {
    final opacity = 1 - fade.value;
    if (opacity <= 0) return;

    final rect = Offset.zero & size;
    final shape = RRect.fromRectAndRadius(rect, const Radius.circular(12));

    canvas
      ..saveLayer(rect, Paint()..color = Colors.white.withValues(alpha: opacity))
      ..clipRRect(shape);

    canvas.drawRect(
      rect,
      Paint()
        ..shader = const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFFD5D9DF), Color(0xFF9EA5B0), Color(0xFFC4C9D1)],
        ).createShader(rect),
    );

    // Textura de rayas diagonales sutiles, sin imágenes.
    final stripe = Paint()
      ..color = Colors.white.withValues(alpha: 0.18)
      ..strokeWidth = 3;
    for (var x = -size.height; x < size.width; x += 16) {
      canvas.drawLine(Offset(x, size.height), Offset(x + size.height, 0), stripe);
    }

    final text = TextPainter(
      text: TextSpan(
        text: label,
        style: TextStyle(
          color: labelColor.withValues(alpha: 0.75),
          fontSize: min(size.width, size.height) * 0.11,
          fontWeight: FontWeight.w900,
          letterSpacing: 3,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout(maxWidth: size.width * 0.9);
    canvas
      ..save()
      ..translate(size.width / 2, size.height / 2)
      ..rotate(-0.12);
    text.paint(canvas, Offset(-text.width / 2, -text.height / 2));
    canvas.restore();

    // Perfora los trazos. El path está normalizado: se escala de forma uniforme por el ancho.
    canvas
      ..save()
      ..scale(size.width)
      ..drawPath(
        strokes,
        Paint()
          ..blendMode = BlendMode.clear
          ..style = PaintingStyle.stroke
          ..strokeWidth = brushWidth / size.width
          ..strokeCap = StrokeCap.round
          ..strokeJoin = StrokeJoin.round,
      )
      ..restore();

    canvas.restore();
  }

  @override
  bool shouldRepaint(ScratchCoverPainter old) =>
      old.brushWidth != brushWidth || old.label != label || old.labelColor != labelColor;
}
