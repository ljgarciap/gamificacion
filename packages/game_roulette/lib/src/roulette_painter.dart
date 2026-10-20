import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'roulette_math.dart';

/// Colores de cada segmento: alternan los de la paleta, cuidando que el último no repita el
/// color del primero cuando la cantidad de segmentos no es múltiplo de la paleta.
List<Color> segmentFills(List<Color> palette, int count) {
  final fills = [for (var i = 0; i < count; i++) palette[i % palette.length]];
  if (count > 1 && fills.last == fills.first) {
    fills[count - 1] = palette.firstWhere((c) => c != fills.first && c != fills[count - 2], orElse: () => fills.last);
  }
  return fills;
}

IconData _iconFor(PrizeType type) => switch (type) {
  PrizeType.money => Icons.payments_rounded,
  PrizeType.coupon => Icons.local_activity_rounded,
  PrizeType.points => Icons.stars_rounded,
  PrizeType.extraSpin => Icons.replay_rounded,
  PrizeType.none => Icons.sentiment_neutral_rounded,
  PrizeType.unknown => Icons.card_giftcard_rounded,
};

class RoulettePainter extends CustomPainter {
  RoulettePainter({required this.segments, required this.theme, required this.rotation, this.highlight});

  final List<Prize> segments;
  final GamificationTheme theme;

  /// Radianes girados en sentido horario.
  final double rotation;

  /// Segmento ganador resaltado cuando la rueda se detiene.
  final int? highlight;

  @override
  void paint(Canvas canvas, Size size) {
    final center = size.center(Offset.zero);
    final outerRadius = min(size.width, size.height) / 2;
    final ringWidth = outerRadius * 0.045;
    final radius = outerRadius - ringWidth;
    final count = segments.length;
    final step = RouletteMath.step(count);
    final fills = segmentFills(theme.segmentColors, count);
    final rect = Rect.fromCircle(center: center, radius: radius);

    canvas
      ..save()
      ..translate(center.dx, center.dy)
      ..rotate(rotation)
      ..translate(-center.dx, -center.dy);

    final fill = Paint()..style = PaintingStyle.fill;
    final divider = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..color = Colors.white;

    for (var i = 0; i < count; i++) {
      final start = -pi / 2 + i * step;
      fill.color = fills[i];
      canvas
        ..drawArc(rect, start, step, true, fill)
        ..drawArc(rect, start, step, true, divider);
      _paintSegmentContent(canvas, center, radius, start + step / 2, segments[i], fills[i]);
    }

    if (highlight != null) {
      final start = -pi / 2 + highlight! * step;
      canvas.drawArc(
        rect.deflate(ringWidth * 0.8),
        start,
        step,
        true,
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = ringWidth * 1.4
          ..strokeJoin = StrokeJoin.round
          ..color = theme.accent,
      );
    }
    canvas.restore();

    // El aro exterior no gira visualmente distinto, por eso se dibuja fuera de la rotación.
    canvas.drawCircle(
      center,
      outerRadius - ringWidth / 2,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = ringWidth
        ..color = theme.navy,
    );
  }

  void _paintSegmentContent(Canvas canvas, Offset center, double radius, double angle, Prize prize, Color background) {
    final onColor = background.computeLuminance() > 0.5 ? theme.navy : Colors.white;

    canvas
      ..save()
      ..translate(center.dx, center.dy)
      ..rotate(angle);

    // Icono cerca del borde, etiqueta hacia el centro; ambos leen de adentro hacia afuera.
    final icon = _iconFor(prize.type);
    final iconPainter = TextPainter(
      text: TextSpan(
        text: String.fromCharCode(icon.codePoint),
        style: TextStyle(
          fontSize: radius * 0.13,
          fontFamily: icon.fontFamily,
          package: icon.fontPackage,
          color: onColor,
        ),
      ),
      textDirection: TextDirection.ltr,
    )..layout();
    iconPainter.paint(canvas, Offset(radius * 0.80, -iconPainter.height / 2));

    final labelPainter = TextPainter(
      text: TextSpan(
        text: prize.label,
        style: TextStyle(
          fontSize: radius * 0.095,
          fontWeight: FontWeight.w800,
          color: onColor,
          fontFamily: theme.fontFamily,
        ),
      ),
      textDirection: TextDirection.ltr,
      maxLines: 2,
      ellipsis: '…',
      textAlign: TextAlign.center,
    )..layout(maxWidth: radius * 0.50);
    labelPainter.paint(canvas, Offset(radius * 0.26, -labelPainter.height / 2));

    canvas.restore();
  }

  @override
  bool shouldRepaint(RoulettePainter old) =>
      old.rotation != rotation || old.highlight != highlight || old.theme != theme || old.segments != segments;
}
