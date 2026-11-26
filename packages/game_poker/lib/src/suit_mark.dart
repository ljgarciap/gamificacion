import 'package:material_ui/material_ui.dart';

/// Palo de una carta (`S`, `H`, `D`, `C`), dibujado a mano. Con los caracteres ♠♥♦♣ la figura la ponía la fuente de respaldo
/// que eligiera cada plataforma (con Flutter 3.50 la web pasó a la de emojis y cambió forma, tamaño y color).
class SuitMark extends StatelessWidget {
  const SuitMark({super.key, required this.suit, required this.color, required this.size});

  final String suit;
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) => CustomPaint(size: Size.square(size), painter: _SuitPainter(suit, color));
}

class _SuitPainter extends CustomPainter {
  const _SuitPainter(this.suit, this.color);

  final String suit;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) => canvas.drawPath(suitPath(suit, size), Paint()..color = color);

  @override
  bool shouldRepaint(_SuitPainter old) => old.suit != suit || old.color != color;
}

/// Contorno del palo dentro de [size]. Las figuras se definen en un cuadro de 100 x 100 y se escalan.
Path suitPath(String suit, Size size) {
  final path = switch (suit) {
    'H' =>
      Path()
        ..moveTo(50, 92)
        ..cubicTo(20, 70, 4, 52, 4, 32)
        ..cubicTo(4, 17, 15, 8, 28, 8)
        ..cubicTo(38, 8, 46, 14, 50, 23)
        ..cubicTo(54, 14, 62, 8, 72, 8)
        ..cubicTo(85, 8, 96, 17, 96, 32)
        ..cubicTo(96, 52, 80, 70, 50, 92)
        ..close(),
    'D' =>
      Path()
        ..moveTo(50, 4)
        ..quadraticBezierTo(66, 30, 86, 50)
        ..quadraticBezierTo(66, 70, 50, 96)
        ..quadraticBezierTo(34, 70, 14, 50)
        ..quadraticBezierTo(34, 30, 50, 4)
        ..close(),
    'S' =>
      Path()
        ..moveTo(50, 5)
        ..cubicTo(68, 26, 95, 40, 95, 60)
        ..cubicTo(95, 74, 84, 80, 74, 80)
        ..cubicTo(65, 80, 58, 76, 54, 70)
        ..quadraticBezierTo(55, 85, 66, 95)
        ..lineTo(34, 95)
        ..quadraticBezierTo(45, 85, 46, 70)
        ..cubicTo(42, 76, 35, 80, 26, 80)
        ..cubicTo(16, 80, 5, 74, 5, 60)
        ..cubicTo(5, 40, 32, 26, 50, 5)
        ..close(),
    // Tréboles: tres hojas, el centro que las une y el tallo (todos en el mismo sentido, para que se sumen).
    'C' =>
      Path()
        ..addOval(Rect.fromCircle(center: const Offset(50, 27), radius: 22))
        ..addOval(Rect.fromCircle(center: const Offset(27, 58), radius: 22))
        ..addOval(Rect.fromCircle(center: const Offset(73, 58), radius: 22))
        ..addOval(Rect.fromCircle(center: const Offset(50, 52), radius: 12))
        ..moveTo(54, 55)
        ..quadraticBezierTo(55, 82, 66, 95)
        ..lineTo(34, 95)
        ..quadraticBezierTo(45, 82, 46, 55)
        ..close(),
    _ => Path(),
  };
  return path.transform(Matrix4.diagonal3Values(size.width / 100, size.height / 100, 1).storage);
}
