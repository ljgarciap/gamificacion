import 'dart:math';
import 'dart:ui' show Offset;

/// Mide qué fracción de la tarjeta ya se raspó.
///
/// Trabaja en coordenadas normalizadas: x en `[0, 1]` y y en `[0, aspect]` (con `aspect = alto / ancho`).
/// Así el avance no se pierde si la tarjeta cambia de tamaño (rotar el móvil, redimensionar el iframe).
/// La tarjeta se discretiza en una cuadrícula fina y se marcan las celdas que toca cada trazo; contar
/// celdas marcadas es mucho más barato que medir píxeles y basta para decidir el umbral de revelado.
class ScratchMask {
  ScratchMask({required this.aspect, this.columns = 48})
      : assert(aspect > 0),
        assert(columns > 0),
        rows = max(1, (columns * aspect).round()) {
    _cells = List<bool>.filled(columns * rows, false);
  }

  final double aspect;
  final int columns;
  final int rows;
  late final List<bool> _cells;
  int _marked = 0;

  /// Fracción raspada, de 0 a 1.
  double get coverage => _marked / _cells.length;

  /// Marca las celdas cuyo centro queda a menos de [radius] de [point] (ambos normalizados).
  /// Devuelve la cobertura resultante. Los puntos fuera de la tarjeta se recortan a su borde.
  double scratch(Offset point, double radius) {
    final cellW = 1 / columns;
    final cellH = aspect / rows;

    final minCol = ((point.dx - radius) / cellW).floor().clamp(0, columns - 1);
    final maxCol = ((point.dx + radius) / cellW).floor().clamp(0, columns - 1);
    final minRow = ((point.dy - radius) / cellH).floor().clamp(0, rows - 1);
    final maxRow = ((point.dy + radius) / cellH).floor().clamp(0, rows - 1);

    final r2 = radius * radius;
    for (var row = minRow; row <= maxRow; row++) {
      final cy = (row + 0.5) * cellH;
      for (var col = minCol; col <= maxCol; col++) {
        final i = row * columns + col;
        if (_cells[i]) continue;
        final cx = (col + 0.5) * cellW;
        final dx = cx - point.dx;
        final dy = cy - point.dy;
        if (dx * dx + dy * dy <= r2) {
          _cells[i] = true;
          _marked++;
        }
      }
    }
    return coverage;
  }

  /// Marca el recorrido de un trazo de [from] a [to]. Un barrido rápido genera eventos táctiles muy
  /// separados; sin interpolar, el trazo se vería continuo pero la cobertura se subestimaría.
  double scratchSegment(Offset from, Offset to, double radius) {
    final distance = (to - from).distance;
    final steps = max(1, (distance / (radius * 0.5)).ceil());
    var result = coverage;
    for (var i = 0; i <= steps; i++) {
      result = scratch(Offset.lerp(from, to, i / steps)!, radius);
    }
    return result;
  }

  /// Marca todo (al pulsar "Revelar todo").
  void fill() {
    _cells.fillRange(0, _cells.length, true);
    _marked = _cells.length;
  }
}
