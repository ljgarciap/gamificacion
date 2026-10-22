import 'bomb_config.dart';

enum BombStatus { playing, defused, exploded }

/// Lógica pura del tablero (sin Flutter): las cartas se destapan EN ORDEN. La carta que se toque, sea cual sea, muestra la siguiente cara de la
/// lista del servidor. Con cada carta se consume un paso de la mecha; con tres 8 se desactiva la bomba, y si se voltean todas sin tres, estalla.
class BombBoard {
  BombBoard(this.faces) : _revealed = List.filled(faces.length, null);

  final List<String> faces;
  final List<String?> _revealed;
  int _flipped = 0;

  /// La cara que muestra la carta [index] (null si sigue tapada).
  String? faceAt(int index) => _revealed[index];

  int get flipped => _flipped;
  int get total => faces.length;

  /// Pasos de mecha que quedan.
  int get fuseLeft => total - _flipped;

  int get eights => _revealed.where((f) => f == BombConfig.eight).length;

  BombStatus get status {
    if (eights >= BombConfig.needed) return BombStatus.defused;
    if (_flipped >= total) return BombStatus.exploded;
    return BombStatus.playing;
  }

  bool get isOver => status != BombStatus.playing;

  /// Destapa la carta [index] con la siguiente cara. Devuelve false si no hizo nada (ya estaba destapada o el juego terminó).
  bool flip(int index) {
    if (isOver || _revealed[index] != null) return false;
    _revealed[index] = faces[_flipped];
    _flipped++;
    return true;
  }

  /// Botón "Revelar todo": destapa las cartas que faltan en orden (de izquierda a derecha). No cambia el resultado.
  void revealAll() {
    for (var i = 0; i < total; i++) {
      if (_revealed[i] == null) {
        _revealed[i] = faces[_flipped];
        _flipped++;
      }
    }
  }
}
