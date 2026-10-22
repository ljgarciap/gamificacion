import 'package:flutter_test/flutter_test.dart';
import 'package:game_bomb/game_bomb.dart';

// Caras EN ORDEN DE VOLTEO: tres 8 (en el 2.º, 5.º y 11.º volteo) y relleno.
const _win = ['star', '8', 'moon', 'coin', '8', 'gem', 'sun', 'bell', 'heart', 'flash', '8', 'star'];
const _none = ['star', '8', 'moon', 'coin', 'sun', 'gem', '8', 'bell', 'heart', 'flash', 'coin', 'sun'];

void main() {
  test('la carta que se toque, sea cual sea, muestra la siguiente cara de la lista', () {
    final b = BombBoard(_win);
    expect(b.flip(7), isTrue); // se toca la carta 7 primero
    expect(b.faceAt(7), 'star', reason: 'la primera cara del servidor');
    expect(b.flip(0), isTrue);
    expect(b.faceAt(0), '8', reason: 'la segunda cara del servidor, aunque sea la carta 0');
    expect(b.flipped, 2);
    expect(b.fuseLeft, 10);
    expect(b.eights, 1);
  });

  test('una carta ya destapada no cuenta ni gasta mecha', () {
    final b = BombBoard(_win);
    b.flip(3);
    expect(b.flip(3), isFalse);
    expect(b.flipped, 1);
  });

  test('con tres 8 se desactiva en el momento, sin importar qué cartas se toquen', () {
    final b = BombBoard(_win);
    for (var i = 0; i < 10; i++) {
      expect(b.status, BombStatus.playing, reason: 'volteo ${i + 1}');
      b.flip(i);
    }
    expect(b.status, BombStatus.playing);
    b.flip(10); // el 11.º volteo trae el tercer 8
    expect(b.status, BombStatus.defused);
    expect(b.flip(11), isFalse, reason: 'ya terminó');
    expect(b.flipped, 11);
  });

  test('sin tres 8, al voltear todas la bomba estalla', () {
    final b = BombBoard(_none);
    for (var i = 0; i < 11; i++) {
      b.flip(i);
    }
    expect(b.status, BombStatus.playing, reason: 'queda un paso de mecha');
    expect(b.fuseLeft, 1);
    b.flip(11);
    expect(b.status, BombStatus.exploded);
    expect(b.eights, 2);
  });

  test('"Revelar todo" destapa en orden y deja el mismo resultado', () {
    final w = BombBoard(_win)..revealAll();
    expect(w.status, BombStatus.defused);
    final n = BombBoard(_none)..revealAll();
    expect(n.status, BombStatus.exploded);
    expect([for (var i = 0; i < 12; i++) n.faceAt(i)], _none);
  });
}
