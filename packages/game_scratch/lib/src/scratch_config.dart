import 'package:gamification_core/gamification_core.dart';

/// Configuración del raspa, leída de `Campaign.gameConfig` (tamaño, umbral, pincel) y de
/// `Outcome.gameResult` (la cuadrícula que decidió el servidor).
class ScratchConfig {
  const ScratchConfig({
    required this.rows,
    required this.cols,
    required this.grid,
    this.revealThreshold = 0.6,
    this.brushWidth = 28,
  });

  static const minSide = 2;
  static const maxSide = 5;

  final int rows;
  final int cols;

  /// Símbolo de cada casilla, fila por fila. Un premio se gana con 3 símbolos iguales al id del premio.
  final List<String> grid;

  /// Fracción raspada a partir de la cual se revela todo solo.
  final double revealThreshold;

  /// Ancho del trazo, en píxeles lógicos.
  final double brushWidth;

  double get aspect => rows / cols;

  /// Lanza [FormatException] si la campaña no trae un raspa usable.
  factory ScratchConfig.fromRedemption(Redemption redemption) {
    final cfg = redemption.campaign.gameConfig;
    final rows = (cfg['rows'] as num?)?.toInt() ?? 3;
    final cols = (cfg['cols'] as num?)?.toInt() ?? 3;
    if (rows < minSide || rows > maxSide || cols < minSide || cols > maxSide) {
      throw FormatException('la cuadrícula admite de $minSide a $maxSide filas y columnas: ${rows}x$cols');
    }

    final raw = redemption.outcome.gameResult['grid'];
    if (raw is! List || raw.length != rows * cols || raw.any((s) => s is! String || s.isEmpty)) {
      throw FormatException('gameResult.grid debe tener ${rows * cols} símbolos');
    }

    return ScratchConfig(
      rows: rows,
      cols: cols,
      grid: raw.cast<String>(),
      revealThreshold: ((cfg['revealThreshold'] as num?)?.toDouble() ?? 0.6).clamp(0.2, 0.95),
      brushWidth: ((cfg['brushWidth'] as num?)?.toDouble() ?? 28).clamp(12.0, 64.0),
    );
  }

  /// Casillas ganadoras: las que muestran el símbolo del premio (vacío si no hay premio).
  List<int> winningCells(Prize prize) => !prize.isWin
      ? const []
      : [
          for (var i = 0; i < grid.length; i++)
            if (grid[i] == prize.id) i
        ];
}
