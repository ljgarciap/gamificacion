import 'dart:ui' show Locale;

import '../models/failure.dart';
import '../models/money.dart';
import '../models/prize.dart';

/// Textos de la interfaz (RF-09). Sin intl/codegen: dos implementaciones const, es (default) y en.
/// Los textos de campaña (títulos, ayuda, etiquetas de premio) vienen del backend, no de aquí.
abstract class GamificationStrings {
  const GamificationStrings();

  static const supported = [Locale('es'), Locale('en')];

  static GamificationStrings of(Locale locale) => locale.languageCode == 'en' ? const EnStrings() : const EsStrings();

  String get defaultTitle;
  String get invitation;
  String get codeLabel;
  String get validate;
  String get helpLink;
  String get balanceLabel;
  String get demoBadge;
  String get demoCodes;
  String get close;
  String get resultWon;
  String get resultNone;
  String get creditedToBalance;
  String get copy;
  String get copied;
  String get continueLabel;
  String get backHome;
  String get spin;
  String get prizeSecured;
  String get revealAll;
  String get scratchHere;
  String get unsupportedGame;
  String get rollDice;
  String get memoryHint;
  String get diceHint;
  String get diceTable;
  String get diceRolling;

  String pointsWon(int points);
  String rouletteLabel(int segments);
  String scratchLabel(int rows, int cols);
  String memoryLabel(int pairs);
  String memoryProgress(int found, int total);
  String cardFaceDown(int position, int total);
  String cardFaceUp(int position, int total, String what);
  String diceLabel(int a, int b);
  String diceCombo(int a, int b);
  String previouslyPlayed(DateTime date);
  String failure(FailureReason reason, {Duration? retryAfter});

  /// Texto del premio para pantalla y lectores de pantalla.
  String prizeDescription(Prize prize) => switch (prize.type) {
    PrizeType.money => formatCop(prize.amount ?? 0),
    PrizeType.points => pointsWon(prize.amount ?? 0),
    _ => prize.label,
  };
}

class EsStrings extends GamificationStrings {
  const EsStrings();
  @override
  String get defaultTitle => 'Juega y Gana';
  @override
  String get invitation => 'Ingresa tu código promocional para jugar';
  @override
  String get codeLabel => 'Código promocional';
  @override
  String get validate => 'Validar código';
  @override
  String get helpLink => '¿Dónde encuentro mi código?';
  @override
  String get balanceLabel => 'Saldo disponible';
  @override
  String get demoBadge => 'DEMO';
  @override
  String get demoCodes => 'Códigos de prueba';
  @override
  String get close => 'Cerrar';
  @override
  String get resultWon => '¡Ganaste!';
  @override
  String get resultNone => '¡Esta vez no hubo suerte!';
  @override
  String get creditedToBalance => 'Se acreditó a tu saldo';
  @override
  String get copy => 'Copiar';
  @override
  String get copied => 'Copiado';
  @override
  String get continueLabel => 'Continuar';
  @override
  String get backHome => 'Volver al inicio';
  @override
  String get spin => 'GIRAR';
  @override
  String get prizeSecured => 'Tu premio ya está asegurado';
  @override
  String get revealAll => 'Revelar todo';
  @override
  String get scratchHere => 'RASPA AQUÍ';
  @override
  String get unsupportedGame => 'Este juego no está disponible en esta versión.';
  @override
  String get rollDice => 'TIRAR';
  @override
  String get memoryHint => 'Encuentra las parejas';
  @override
  String get diceHint => 'Toca TIRAR para lanzar los dados';
  @override
  String get diceTable => 'Tabla de premios';
  @override
  String get diceRolling => 'Tirando los dados…';

  @override
  String pointsWon(int points) => '+$points puntos';
  @override
  String rouletteLabel(int segments) => 'Ruleta con $segments premios posibles';
  @override
  String scratchLabel(int rows, int cols) => 'Tarjeta de raspa y gana de $rows por $cols casillas';
  @override
  String memoryLabel(int pairs) => 'Tablero de Concéntrese con $pairs parejas';
  @override
  String memoryProgress(int found, int total) => 'Parejas: $found de $total';
  @override
  String cardFaceDown(int position, int total) => 'Carta $position de $total, boca abajo';
  @override
  String cardFaceUp(int position, int total, String what) => 'Carta $position de $total: $what';
  @override
  String diceLabel(int a, int b) => 'Dados: $a y $b';
  @override
  String diceCombo(int a, int b) => '$a y $b';
  @override
  String previouslyPlayed(DateTime d) =>
      'Este código ya fue jugado el ${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

  @override
  String failure(FailureReason reason, {Duration? retryAfter}) => switch (reason) {
    FailureReason.invalidCode => 'El código no es válido. Revísalo e inténtalo de nuevo.',
    FailureReason.alreadyUsed => 'Este código ya fue utilizado.',
    FailureReason.expired => 'Este código está vencido.',
    FailureReason.campaignInactive => 'Esta promoción no está activa.',
    FailureReason.rateLimited =>
      retryAfter == null
          ? 'Demasiados intentos. Espera un momento.'
          : 'Demasiados intentos. Reintenta en ${retryAfter.inSeconds} s.',
    FailureReason.network => 'Sin conexión. Verifica tu internet e inténtalo de nuevo.',
    FailureReason.unauthorized => 'Tu sesión no es válida. Vuelve a abrir la aplicación e inténtalo de nuevo.',
    FailureReason.unknown => 'Ocurrió un error inesperado. Inténtalo de nuevo.',
  };
}

class EnStrings extends GamificationStrings {
  const EnStrings();
  @override
  String get defaultTitle => 'Play and Win';
  @override
  String get invitation => 'Enter your promo code to play';
  @override
  String get codeLabel => 'Promo code';
  @override
  String get validate => 'Validate code';
  @override
  String get helpLink => 'Where do I find my code?';
  @override
  String get balanceLabel => 'Available balance';
  @override
  String get demoBadge => 'DEMO';
  @override
  String get demoCodes => 'Test codes';
  @override
  String get close => 'Close';
  @override
  String get resultWon => 'You won!';
  @override
  String get resultNone => 'No luck this time!';
  @override
  String get creditedToBalance => 'Credited to your balance';
  @override
  String get copy => 'Copy';
  @override
  String get copied => 'Copied';
  @override
  String get continueLabel => 'Continue';
  @override
  String get backHome => 'Back to home';
  @override
  String get spin => 'SPIN';
  @override
  String get prizeSecured => 'Your prize is already secured';
  @override
  String get revealAll => 'Reveal all';
  @override
  String get scratchHere => 'SCRATCH HERE';
  @override
  String get unsupportedGame => 'This game is not available in this version.';
  @override
  String get rollDice => 'ROLL';
  @override
  String get memoryHint => 'Find the pairs';
  @override
  String get diceHint => 'Tap ROLL to throw the dice';
  @override
  String get diceTable => 'Prize table';
  @override
  String get diceRolling => 'Rolling the dice…';

  @override
  String pointsWon(int points) => '+$points points';
  @override
  String rouletteLabel(int segments) => 'Roulette with $segments possible prizes';
  @override
  String scratchLabel(int rows, int cols) => 'Scratch card with $rows by $cols squares';
  @override
  String memoryLabel(int pairs) => 'Memory board with $pairs pairs';
  @override
  String memoryProgress(int found, int total) => 'Pairs: $found of $total';
  @override
  String cardFaceDown(int position, int total) => 'Card $position of $total, face down';
  @override
  String cardFaceUp(int position, int total, String what) => 'Card $position of $total: $what';
  @override
  String diceLabel(int a, int b) => 'Dice: $a and $b';
  @override
  String diceCombo(int a, int b) => '$a and $b';
  @override
  String previouslyPlayed(DateTime d) =>
      'This code was already played on ${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  String failure(FailureReason reason, {Duration? retryAfter}) => switch (reason) {
    FailureReason.invalidCode => 'The code is not valid. Check it and try again.',
    FailureReason.alreadyUsed => 'This code has already been used.',
    FailureReason.expired => 'This code has expired.',
    FailureReason.campaignInactive => 'This promotion is not active.',
    FailureReason.rateLimited =>
      retryAfter == null
          ? 'Too many attempts. Please wait a moment.'
          : 'Too many attempts. Try again in ${retryAfter.inSeconds} s.',
    FailureReason.network => 'No connection. Check your internet and try again.',
    FailureReason.unauthorized => 'Your session is not valid. Please reopen the app and try again.',
    FailureReason.unknown => 'An unexpected error occurred. Please try again.',
  };
}
