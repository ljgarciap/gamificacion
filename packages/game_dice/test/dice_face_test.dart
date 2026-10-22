import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:game_dice/src/dice_face.dart';

void main() {
  test('detenido en N, la cara que mira al jugador es la N (las seis)', () {
    for (var v = 1; v <= 6; v++) {
      expect(diceFrontValue(diceViewOrientation(v)), v, reason: 'valor $v');
    }
  });

  test('cada eje del cubo lleva la cara correcta al frente (opuestas suman 7)', () {
    const pi = 3.141592653589793;
    expect(diceFrontValue(Matrix4.identity()), 1);
    expect(diceFrontValue(Matrix4.rotationY(pi)), 6); // 1 ↔ 6
    expect(diceFrontValue(Matrix4.rotationY(-pi / 2)), 3);
    expect(diceFrontValue(Matrix4.rotationY(pi / 2)), 4); // 3 ↔ 4
    expect(diceFrontValue(Matrix4.rotationX(pi / 2)), 2);
    expect(diceFrontValue(Matrix4.rotationX(-pi / 2)), 5); // 2 ↔ 5
  });

  testWidgets('el dado se dibuja en cualquier fase de la tirada sin errores', (t) async {
    for (final spin in [0.0, 0.3, 0.7, 1.0]) {
      for (final lift in [0.0, 0.5, 1.0]) {
        await t.pumpWidget(
          MaterialApp(
            home: Center(
              child: DiceFace(
                value: 5,
                size: 120,
                color: Colors.white,
                border: Colors.black,
                pip: Colors.black,
                spin: spin,
                lift: lift,
                turns: (2, -1, 3),
              ),
            ),
          ),
        );
        expect(t.takeException(), isNull);
      }
    }
  });
}
