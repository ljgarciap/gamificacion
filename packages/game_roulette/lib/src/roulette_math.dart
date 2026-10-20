import 'dart:math';

/// Geometría de la ruleta, separada del dibujo para poder probarla sin widgets.
///
/// Convención: el puntero está fijo arriba. El segmento `i` ocupa, medido en sentido horario desde
/// la parte superior de la rueda, el arco `[i * paso, (i + 1) * paso)`. La rueda gira `rotation`
/// radianes en sentido horario.
abstract final class RouletteMath {
  static const fullCircle = 2 * pi;

  static double step(int segments) => fullCircle / segments;

  /// Rotación final para que el segmento [index] quede bajo el puntero.
  ///
  /// [offsetFraction] (-0.5..0.5 exclusivo) desplaza el punto de parada dentro del segmento como
  /// fracción de su ancho, para que el giro no se vea siempre igual. El recorrido total es
  /// [fullTurns] vueltas completas más lo necesario para llegar al segmento.
  static double finalRotation({
    required int index,
    required int segments,
    required int fullTurns,
    double offsetFraction = 0,
  }) {
    assert(index >= 0 && index < segments, 'índice fuera de rango');
    assert(offsetFraction.abs() < 0.5, 'el offset debe quedar dentro del segmento');
    final wheelAngle = (index + 0.5 + offsetFraction) * step(segments);
    return fullTurns * fullCircle + (fullCircle - wheelAngle);
  }

  /// Segmento que está bajo el puntero cuando la rueda ha girado [rotation].
  static int segmentAt(double rotation, int segments) {
    final wheelAngle = ((-rotation) % fullCircle + fullCircle) % fullCircle;
    final index = (wheelAngle / step(segments)).floor();
    return index.clamp(0, segments - 1);
  }
}
