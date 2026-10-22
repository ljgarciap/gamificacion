import 'dart:math';

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

/// Un cubo con seis caras: cada una lleva su número (las opuestas suman 7, como un dado real) y se orienta con la rotación que la lleva al frente.
/// El valor de cada cara es el de su normal: +z=1, −z=6, +x=3, −x=4, +y=2, −y=5.
final _faces = <(int, Matrix4)>[
  (1, Matrix4.identity()),
  (6, Matrix4.rotationY(pi)),
  (3, Matrix4.rotationY(pi / 2)),
  (4, Matrix4.rotationY(-pi / 2)),
  (2, Matrix4.rotationX(-pi / 2)),
  (5, Matrix4.rotationX(pi / 2)),
];

/// Rotación de reposo: deja la cara [value] mirando al jugador (antes de la inclinación de la vista).
Matrix4 diceRestOrientation(int value) => switch (value) {
  6 => Matrix4.rotationY(-pi),
  3 => Matrix4.rotationY(-pi / 2),
  4 => Matrix4.rotationY(pi / 2),
  2 => Matrix4.rotationX(pi / 2),
  5 => Matrix4.rotationX(-pi / 2),
  _ => Matrix4.identity(),
};

/// Inclinación fija de la vista: deja ver un poco la cara de arriba y la de un lado, para que se note que es un cubo.
final _viewTilt = Matrix4.rotationX(-0.32)..rotateY(0.36);

/// Orientación con la que queda un dado detenido en [value]: de frente, con la inclinación de la vista (para pruebas).
@visibleForTesting
Matrix4 diceViewOrientation(int value) => _viewTilt.multiplied(diceRestOrientation(value));

/// Valor de la cara que mira más de frente al jugador con la rotación [rot] (para pruebas).
@visibleForTesting
int diceFrontValue(Matrix4 rot) {
  var best = 1;
  var bestZ = -2.0;
  for (final (v, face) in _faces) {
    final z = rot.multiplied(face).entry(2, 2);
    if (z > bestZ) {
      bestZ = z;
      best = v;
    }
  }
  return best;
}

/// Un dado en 3D dibujado con `CustomPainter` (sin imágenes): cubo de esquinas redondeadas con luz, sombra sobre la mesa y puntos hundidos.
///
/// [value] es la cara que queda de frente al terminar. [spin] va de 1 (recién lanzado, dando vueltas) a 0 (detenido en [value]); [turns] son
/// las vueltas por eje a esa velocidad (cosmético: no tiene relación con el resultado). [lift] (0 a 1) es cuánto se despega de la mesa al rebotar.
class DiceFace extends StatelessWidget {
  const DiceFace({
    super.key,
    required this.value,
    required this.size,
    required this.color,
    required this.border,
    required this.pip,
    this.glow,
    this.spin = 0,
    this.turns = (2, 1, 1),
    this.lift = 0,
  });

  final int value;
  final double size;
  final Color color;
  final Color border;
  final Color pip;

  /// Resalta el dado (tirada terminada con premio).
  final Color? glow;

  final double spin;
  final (double, double, double) turns;
  final double lift;

  @override
  Widget build(BuildContext context) => CustomPaint(
    size: Size.square(size),
    painter: _DicePainter(
      value: value,
      color: color,
      border: border,
      pip: pip,
      glow: glow,
      spin: spin,
      turns: turns,
      lift: lift,
    ),
  );
}

class _DicePainter extends CustomPainter {
  _DicePainter({
    required this.value,
    required this.color,
    required this.border,
    required this.pip,
    required this.glow,
    required this.spin,
    required this.turns,
    required this.lift,
  });

  final int value;
  final Color color;
  final Color border;
  final Color pip;
  final Color? glow;
  final double spin;
  final (double, double, double) turns;
  final double lift;

  @override
  void paint(Canvas canvas, Size size) {
    final s = size.width;
    final a = s * 0.62; // arista del cubo
    final h = a / 2;
    final cx = s / 2;
    final cy = s * 0.46 - lift * s * 0.2;

    // Sombra sobre la mesa: se achica y se aclara cuando el dado se levanta.
    final shadowW = a * (1.25 - 0.4 * lift);
    canvas.drawOval(
      Rect.fromCenter(center: Offset(cx, s * 0.9), width: shadowW, height: shadowW * 0.3),
      Paint()
        ..color = Colors.black.withValues(alpha: 0.3 * (1 - 0.55 * lift))
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, s * 0.05),
    );
    if (glow != null) {
      canvas.drawCircle(
        Offset(cx, cy),
        a * 0.78,
        Paint()
          ..color = glow!.withValues(alpha: 0.55)
          ..maskFilter = MaskFilter.blur(BlurStyle.normal, s * 0.1),
      );
    }

    final spinM = Matrix4.rotationX(spin * 2 * pi * turns.$1)
      ..rotateY(spin * 2 * pi * turns.$2)
      ..rotateZ(spin * 2 * pi * turns.$3);
    final rot = _viewTilt.multiplied(spinM).multiplied(diceRestOrientation(value));
    final base = Matrix4.translationValues(cx, cy, 0).multiplied(Matrix4.identity()..setEntry(3, 2, -1 / (s * 5)));

    // Solo se dibujan las caras que miran al jugador, de la más lejana a la más cercana.
    final visible = <({int value, Matrix4 m, double z, double dot})>[];
    for (final (v, face) in _faces) {
      final m = rot.multiplied(face);
      final z = m.entry(2, 2);
      if (z <= 0.02) continue;
      // Luz desde arriba a la izquierda.
      final dot = (m.entry(0, 2) * -0.35 + m.entry(1, 2) * -0.55 + z * 0.75) / 1.0;
      visible.add((value: v, m: m, z: z, dot: dot));
    }
    visible.sort((p, q) => p.z.compareTo(q.z));

    for (final f in visible) {
      canvas.save();
      canvas.transform(base.multiplied(f.m).multiplied(Matrix4.translationValues(0, 0, h)).storage);
      _paintFace(canvas, a, f.value, f.dot.clamp(0.0, 1.0));
      canvas.restore();
    }
  }

  void _paintFace(Canvas canvas, double a, int v, double light) {
    final rect = Rect.fromCenter(center: Offset.zero, width: a, height: a);
    final body = RRect.fromRectAndRadius(rect, Radius.circular(a * 0.17));
    final shade = Color.lerp(Color.lerp(color, Colors.black, 0.5), color, 0.5 + 0.5 * light)!;
    canvas.drawRRect(
      body,
      Paint()
        ..shader = RadialGradient(
          center: const Alignment(-0.3, -0.35),
          radius: 1.1,
          colors: [Color.lerp(shade, Colors.white, 0.35 * light)!, shade],
        ).createShader(rect),
    );
    // Borde fino: marca la arista entre dos caras.
    canvas.drawRRect(
      body,
      Paint()
        ..color = border.withValues(alpha: 0.55)
        ..style = PaintingStyle.stroke
        ..strokeWidth = max(1.0, a * 0.03),
    );

    final step = a * 0.27;
    final r = a * 0.085;
    for (final (c, row) in _pips[v]!) {
      final center = Offset((c - 1) * step, (row - 1) * step);
      // Punto hundido: un poco más oscuro arriba a la izquierda y con un reflejo abajo a la derecha.
      canvas.drawCircle(
        center + Offset(r * 0.12, r * 0.14),
        r,
        Paint()..color = Colors.white.withValues(alpha: 0.5 * light),
      );
      canvas.drawCircle(
        center,
        r,
        Paint()
          ..shader = RadialGradient(
            center: const Alignment(0.3, 0.3),
            colors: [Color.lerp(pip, Colors.white, 0.2)!, pip],
          ).createShader(Rect.fromCircle(center: center, radius: r)),
      );
    }
  }

  @override
  bool shouldRepaint(_DicePainter old) =>
      old.value != value ||
      old.color != color ||
      old.border != border ||
      old.pip != pip ||
      old.glow != glow ||
      old.spin != spin ||
      old.turns != turns ||
      old.lift != lift;
}
