import 'package:flutter_test/flutter_test.dart';
import 'package:game_poker/src/suit_mark.dart';
import 'package:material_ui/material_ui.dart';

void main() {
  // Puntos en un cuadro de 100 x 100: dentro y fuera de cada figura. Distinguen un palo de otro.
  const inside = {
    'H': [Offset(28, 25), Offset(72, 25), Offset(50, 80)],
    'D': [Offset(50, 50), Offset(50, 12), Offset(50, 88)],
    'S': [Offset(50, 18), Offset(20, 60), Offset(80, 60), Offset(50, 90)],
    'C': [Offset(50, 18), Offset(22, 58), Offset(78, 58), Offset(50, 52), Offset(50, 90)],
  };
  const outside = {
    'H': [Offset(50, 10), Offset(10, 85), Offset(90, 85)],
    'D': [Offset(20, 20), Offset(80, 20), Offset(20, 80), Offset(80, 80)],
    'S': [Offset(15, 20), Offset(85, 20), Offset(10, 92)],
    'C': [Offset(15, 20), Offset(85, 20), Offset(15, 90)],
  };

  for (final suit in inside.keys) {
    test('el palo $suit tiene su figura y cabe en su cuadro', () {
      final path = suitPath(suit, const Size.square(100));
      for (final p in inside[suit]!) {
        expect(path.contains(p), isTrue, reason: '$p debe quedar dentro');
      }
      for (final p in outside[suit]!) {
        expect(path.contains(p), isFalse, reason: '$p debe quedar fuera');
      }
      final bounds = path.getBounds();
      expect(bounds.left >= 0 && bounds.top >= 0 && bounds.right <= 100 && bounds.bottom <= 100, isTrue);
    });
  }

  test('la figura escala con el tamaño', () {
    final bounds = suitPath('H', const Size.square(40)).getBounds();
    expect(bounds.right <= 40 && bounds.bottom <= 40 && bounds.width > 30, isTrue);
  });

  testWidgets('se dibuja con el color pedido y sin depender de una fuente', (t) async {
    const color = Color(0xFF112233);
    await t.pumpWidget(
      const Center(
        child: SuitMark(suit: 'D', color: color, size: 24),
      ),
    );
    expect(find.byType(Text), findsNothing);
    expect(t.getSize(find.byType(SuitMark)), const Size.square(24));
    expect(find.byType(SuitMark), paints..path(color: color));
  });
}
