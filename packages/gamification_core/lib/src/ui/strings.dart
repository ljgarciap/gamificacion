import 'dart:ui' show Locale;

import '../data/demo_backend.dart' show DemoFailure;
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
  String demoWithPrize(int n);
  String demoWithoutPrize(int n);
  String demoShowAll(int n);
  String get demoShowLess;
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

  /// Bomba 8 (SPEC-005 §3).
  String get bombHint;
  String get bombOrderNote;
  String bombEights(int found, int needed);
  String bombFuse(int left, int total);
  String get bombDefused;
  String get bombExploded;
  String bombLabel(int cards);

  /// Bingo en vivo (SPEC-005 §4).
  String get bingoHint;
  String get bingoStart;
  String get bingoNext;
  String get bingoPause;
  String get bingoResume;
  String get bingoTable;
  String bingoBall(String letter, int number);
  String bingoDrawn(int drawn, int total);
  String bingoPattern(String pattern);
  String bingoEvent(String pattern);
  String bingoWon(String pattern);
  String get bingoNoWin;
  String get bingoFree;
  String bingoLabel(int draws);

  /// Póker del crupier (SPEC-005 §5).
  String get pokerHint;
  String get pokerStart;
  String get pokerNext;
  String get pokerDealer;
  String get pokerYourCards;
  String get pokerTable;
  String get pokerPrizes;
  String pokerHand(String category);
  String pokerCurrent(String category);
  String pokerWon(String category);
  String pokerNoWin(String category);
  String pokerCardName(String card);

  /// Rival simulado de Concéntrese (solo animación: el resultado del código ya está decidido).
  String get rivalNote;
  String get rivalTurnYou;
  String get rivalTurnMachine;
  String get rivalYouWin;
  String get rivalMachineWins;
  String cardFaceDown(int position, int total);
  String cardFaceUp(int position, int total, String what);
  String diceLabel(int a, int b);
  String diceCombo(int a, int b);
  String previouslyPlayed(DateTime date);

  /// Botón para jugar el siguiente giro extra; [remaining] es cuántos quedan por jugar.
  String spinAgain(int remaining);
  String get demoBanner;
  String get demoAccessTitle;
  String get demoAccessBody;
  String demoAccessAs(String name);
  String get demoPrivacy;
  String get demoEmailLabel;
  String get demoSendCode;
  String get demoCodeTitle;
  String demoCodeSent(String email);
  String get demoCodeLabel;
  String get demoVerify;
  String get demoResend;
  String demoResendIn(int seconds);
  String get demoChangeEmail;
  String get demoSignOut;
  String get demoShopTitle;
  String demoAddOne(String name);
  String demoRemoveOne(String name);
  String demoQuantity(String name, int n);
  String get demoTotal;
  String demoYouGet(String balance, int codes);
  String demoBelowMin(String min);
  String get demoEmptyCart;
  String get demoPay;
  String get demoPaying;
  String get demoLoadedTitle;
  String demoCredited(String amount);
  String get demoNewBalance;
  String demoCodesDelivered(int n);
  String get demoCapReached;
  String get demoSeeCodes;
  String get demoCodesTitle;
  String get demoCodesEmpty;
  String get demoPlay;
  String get demoPlayed;
  String get demoBuyMore;
  String get demoRetry;
  String get demoUnavailable;
  String demoStepLabel(int index);
  String demoGameName(String game);
  String demoFailure(DemoFailure reason, {String? message});

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
  String demoWithPrize(int n) => 'Con premio ($n)';

  @override
  String demoWithoutPrize(int n) => 'Sin premio ($n)';

  @override
  String demoShowAll(int n) => 'Ver los $n';

  @override
  String get demoShowLess => 'Ver menos';
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
  String get bombHint => 'Voltea las cartas: encuentra tres 8 antes de que se acabe la mecha';

  @override
  String get bombOrderNote => 'Las cartas se destapan en orden; tu resultado ya está decidido.';

  @override
  String bombEights(int found, int needed) => 'Ochos: $found de $needed';

  @override
  String bombFuse(int left, int total) => 'Mecha: $left de $total';

  @override
  String get bombDefused => '¡Bomba desactivada!';

  @override
  String get bombExploded => '¡Se acabó la mecha!';

  @override
  String bombLabel(int cards) => 'Bomba con $cards cartas';

  @override
  String get bingoHint => 'Toca Empezar: las bolas salen solas y se marcan en tu cartón';

  @override
  String get bingoStart => 'EMPEZAR';

  @override
  String get bingoNext => 'Siguiente bola';

  @override
  String get bingoPause => 'Pausar';

  @override
  String get bingoResume => 'Automático';

  @override
  String get bingoTable => 'Premios por patrón';

  @override
  String bingoBall(String letter, int number) => 'Bola $letter-$number';

  @override
  String bingoDrawn(int drawn, int total) => 'Bolas: $drawn de $total';

  @override
  String bingoPattern(String pattern) => switch (pattern) {
    'line' => 'Línea',
    'corners' => 'Cuatro esquinas',
    'full' => 'Cartón lleno',
    _ => pattern,
  };

  @override
  String bingoEvent(String pattern) => '¡${bingoPattern(pattern)} completa! Sigue jugando…';

  @override
  String bingoWon(String pattern) => '¡Bingo! ${bingoPattern(pattern)}';

  @override
  String get bingoNoWin => 'La partida terminó sin completar un patrón que pague';

  @override
  String get bingoFree => 'Libre';

  @override
  String bingoLabel(int draws) => 'Cartón de Bingo; saldrán $draws bolas';

  @override
  String get pokerHint => 'Tus cartas están listas. Toca ABRIR y el crupier abre las de la mesa';

  @override
  String get pokerStart => 'ABRIR';

  @override
  String get pokerNext => 'Siguiente';

  @override
  String get pokerDealer => 'El crupier abre las cartas';

  @override
  String get pokerYourCards => 'Tus cartas';

  @override
  String get pokerTable => 'La mesa';

  @override
  String get pokerPrizes => 'Premios por mano';

  @override
  String pokerHand(String category) => switch (category) {
    'high_card' => 'Carta alta',
    'pair' => 'Pareja',
    'two_pair' => 'Doble pareja',
    'three_of_a_kind' => 'Trío',
    'straight' => 'Escalera',
    'flush' => 'Color',
    'full_house' => 'Full',
    'four_of_a_kind' => 'Póker',
    'straight_flush' => 'Escalera de color',
    _ => category,
  };

  @override
  String pokerCurrent(String category) => 'Tienes: ${pokerHand(category)}';

  @override
  String pokerWon(String category) => '¡${pokerHand(category)}!';

  @override
  String pokerNoWin(String category) => 'Tu mejor mano: ${pokerHand(category)}. No paga premio';

  @override
  String pokerCardName(String card) {
    const ranks = {
      '2': 'Dos',
      '3': 'Tres',
      '4': 'Cuatro',
      '5': 'Cinco',
      '6': 'Seis',
      '7': 'Siete',
      '8': 'Ocho',
      '9': 'Nueve',
      'T': 'Diez',
      'J': 'Jota',
      'Q': 'Reina',
      'K': 'Rey',
      'A': 'As',
    };
    const suits = {'S': 'picas', 'H': 'corazones', 'D': 'diamantes', 'C': 'tréboles'};
    return '${ranks[card[0]] ?? card[0]} de ${suits[card[1]] ?? card[1]}';
  }

  @override
  String get rivalNote => 'Juegas contra una máquina simulada: es solo una animación, tu resultado ya está decidido.';

  @override
  String get rivalTurnYou => 'Tu turno';

  @override
  String get rivalTurnMachine => 'Turno de la máquina…';

  @override
  String get rivalYouWin => '¡Le ganaste a la máquina!';

  @override
  String get rivalMachineWins => 'Esta vez ganó la máquina.';
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
  String spinAgain(int remaining) => remaining == 1 ? 'Girar de nuevo' : 'Girar de nuevo ($remaining)';

  @override
  String get demoBanner => 'MUESTRA · Datos simulados, sin dinero real';
  @override
  String get demoAccessTitle => 'Bienvenido a la muestra';
  @override
  String get demoAccessBody =>
      'Entra con tu correo: te enviamos un código de un solo uso. Compras en línea, recibes saldo y códigos sorteados al azar, y todo queda guardado para cuando vuelvas.';
  @override
  String demoAccessAs(String name) => 'Entrarás como $name';
  // Aviso de privacidad PROVISIONAL: la redacción definitiva es decisión de negocio (D5, Luis); se reemplaza aquí sin tocar nada más.
  @override
  String get demoPrivacy =>
      'Usaremos tu correo solo para enviarte el código de ingreso y guardar tu saldo y tus códigos de esta muestra. '
      'No lo compartimos y se borra cuando se elimina tu cuenta o tras un tiempo sin ingresar.';
  @override
  String get demoEmailLabel => 'Correo electrónico';
  @override
  String get demoSendCode => 'Enviarme el código';
  @override
  String get demoCodeTitle => 'Revisa tu correo';
  @override
  String demoCodeSent(String email) => 'Enviamos un código de 6 dígitos a $email. Vence en pocos minutos.';
  @override
  String get demoCodeLabel => 'Código de 6 dígitos';
  @override
  String get demoVerify => 'Entrar';
  @override
  String get demoResend => 'Pedir otro código';
  @override
  String demoResendIn(int seconds) => 'Podrás pedir otro en $seconds s';
  @override
  String get demoChangeEmail => 'Usar otro correo';
  @override
  String get demoSignOut => 'Salir';
  @override
  String get demoShopTitle => 'Tienda';
  @override
  String demoAddOne(String name) => 'Agregar uno de $name';
  @override
  String demoRemoveOne(String name) => 'Quitar uno de $name';
  @override
  String demoQuantity(String name, int n) => '$name: $n';
  @override
  String get demoTotal => 'Total';
  @override
  String demoYouGet(String balance, int codes) =>
      codes == 1 ? 'Recibirás $balance de saldo y 1 código' : 'Recibirás $balance de saldo y $codes códigos';
  @override
  String demoBelowMin(String min) => 'La compra mínima es $min.';
  @override
  String get demoEmptyCart => 'Elige al menos un producto.';
  @override
  String get demoPay => 'Pagar (simulado)';
  @override
  String get demoPaying => 'Procesando el pago simulado…';
  @override
  String get demoLoadedTitle => 'Saldo cargado';
  @override
  String demoCredited(String amount) => '+ $amount a tu saldo';
  @override
  String get demoNewBalance => 'Tu saldo ahora';
  @override
  String demoCodesDelivered(int n) => n == 1 ? '1 código para jugar' : '$n códigos para jugar';
  @override
  String get demoCapReached =>
      'Llegaste al tope de códigos de la muestra: te entregamos menos de los que correspondían.';
  @override
  String get demoSeeCodes => 'Ver mis códigos';
  @override
  String get demoCodesTitle => 'Tus códigos';
  @override
  String get demoCodesEmpty => 'Aún no tienes códigos. Compra para recibirlos.';
  @override
  String get demoPlay => 'Jugar';
  @override
  String get demoPlayed => 'Jugado';
  @override
  String get demoBuyMore => 'Seguir comprando';
  @override
  String get demoRetry => 'Reintentar';
  @override
  String get demoUnavailable => 'La muestra no está disponible en este momento.';
  @override
  String demoStepLabel(int index) => const ['Acceso', 'Tienda', 'Pago', 'Saldo', 'Tus códigos', 'Juego'][index];
  @override
  String demoGameName(String game) => switch (game) {
    'roulette' => 'Ruleta',
    'scratch' => 'Raspa y Gana',
    'memory' => 'Concéntrese',
    'dice' => 'Dados',
    'bomb' => 'Bomba 8',
    'bingo' => 'Bingo en vivo',
    'poker' => 'Póker del crupier',
    _ => game,
  };
  @override
  String demoFailure(DemoFailure reason, {String? message}) => switch (reason) {
    DemoFailure.off => 'La muestra no está disponible en este momento.',
    DemoFailure.sessionInvalid => 'Tu sesión de la muestra venció. Vuelve a entrar.',
    DemoFailure.poolExhausted => 'La muestra se quedó sin códigos. Avisa al organizador.',
    DemoFailure.invalidEmail => 'Escribe un correo válido.',
    DemoFailure.invalidCode => 'El código no es válido o ya venció. Pide uno nuevo.',
    DemoFailure.mailUnavailable => 'No pudimos enviar el correo. Inténtalo de nuevo en un momento.',
    DemoFailure.accountsFull => 'La muestra llegó a su tope de cuentas. Avisa al organizador.',
    DemoFailure.invalidPurchase => message ?? 'La compra no es válida.',
    DemoFailure.rateLimited => 'Demasiados intentos. Espera un momento.',
    DemoFailure.network => 'Sin conexión. Verifica tu internet e inténtalo de nuevo.',
    DemoFailure.unknown => 'Ocurrió un error inesperado. Inténtalo de nuevo.',
  };

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
    FailureReason.noSpinsLeft => 'Ya no tienes giros por jugar.',
    FailureReason.spinReserveExhausted => 'Los giros extra de esta promoción se agotaron.',
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
  String demoWithPrize(int n) => 'With prize ($n)';

  @override
  String demoWithoutPrize(int n) => 'No prize ($n)';

  @override
  String demoShowAll(int n) => 'Show all $n';

  @override
  String get demoShowLess => 'Show less';
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
  String get bombHint => 'Flip the cards: find three 8s before the fuse runs out';

  @override
  String get bombOrderNote => 'Cards are revealed in order; your result is already decided.';

  @override
  String bombEights(int found, int needed) => '8s: $found of $needed';

  @override
  String bombFuse(int left, int total) => 'Fuse: $left of $total';

  @override
  String get bombDefused => 'Bomb defused!';

  @override
  String get bombExploded => 'The fuse ran out!';

  @override
  String bombLabel(int cards) => 'Bomb with $cards cards';

  @override
  String get bingoHint => 'Tap Start: balls are drawn automatically and marked on your card';

  @override
  String get bingoStart => 'START';

  @override
  String get bingoNext => 'Next ball';

  @override
  String get bingoPause => 'Pause';

  @override
  String get bingoResume => 'Automatic';

  @override
  String get bingoTable => 'Prizes by pattern';

  @override
  String bingoBall(String letter, int number) => 'Ball $letter-$number';

  @override
  String bingoDrawn(int drawn, int total) => 'Balls: $drawn of $total';

  @override
  String bingoPattern(String pattern) => switch (pattern) {
    'line' => 'Line',
    'corners' => 'Four corners',
    'full' => 'Full card',
    _ => pattern,
  };

  @override
  String bingoEvent(String pattern) => '${bingoPattern(pattern)} complete! Keep playing…';

  @override
  String bingoWon(String pattern) => 'Bingo! ${bingoPattern(pattern)}';

  @override
  String get bingoNoWin => 'The game ended without completing a paying pattern';

  @override
  String get bingoFree => 'Free';

  @override
  String bingoLabel(int draws) => 'Bingo card; $draws balls will be drawn';

  @override
  String get pokerHint => 'Your cards are ready. Tap OPEN and the dealer opens the table cards';

  @override
  String get pokerStart => 'OPEN';

  @override
  String get pokerNext => 'Next';

  @override
  String get pokerDealer => 'The dealer opens the cards';

  @override
  String get pokerYourCards => 'Your cards';

  @override
  String get pokerTable => 'The table';

  @override
  String get pokerPrizes => 'Prizes by hand';

  @override
  String pokerHand(String category) => switch (category) {
    'high_card' => 'High card',
    'pair' => 'Pair',
    'two_pair' => 'Two pair',
    'three_of_a_kind' => 'Three of a kind',
    'straight' => 'Straight',
    'flush' => 'Flush',
    'full_house' => 'Full house',
    'four_of_a_kind' => 'Four of a kind',
    'straight_flush' => 'Straight flush',
    _ => category,
  };

  @override
  String pokerCurrent(String category) => 'You have: ${pokerHand(category)}';

  @override
  String pokerWon(String category) => '${pokerHand(category)}!';

  @override
  String pokerNoWin(String category) => 'Your best hand: ${pokerHand(category)}. It does not pay';

  @override
  String pokerCardName(String card) {
    const ranks = {
      '2': 'Two',
      '3': 'Three',
      '4': 'Four',
      '5': 'Five',
      '6': 'Six',
      '7': 'Seven',
      '8': 'Eight',
      '9': 'Nine',
      'T': 'Ten',
      'J': 'Jack',
      'Q': 'Queen',
      'K': 'King',
      'A': 'Ace',
    };
    const suits = {'S': 'spades', 'H': 'hearts', 'D': 'diamonds', 'C': 'clubs'};
    return '${ranks[card[0]] ?? card[0]} of ${suits[card[1]] ?? card[1]}';
  }

  @override
  String get rivalNote =>
      'You play against a simulated machine: it is only an animation, your result is already decided.';

  @override
  String get rivalTurnYou => 'Your turn';

  @override
  String get rivalTurnMachine => "Machine's turn…";

  @override
  String get rivalYouWin => 'You beat the machine!';

  @override
  String get rivalMachineWins => 'The machine won this time.';
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
  String spinAgain(int remaining) => remaining == 1 ? 'Spin again' : 'Spin again ($remaining)';

  @override
  String get demoBanner => 'DEMO · Simulated data, no real money';
  @override
  String get demoAccessTitle => 'Welcome to the demo';
  @override
  String get demoAccessBody =>
      'Sign in with your email: we send you a one-time code. You buy online, receive balance and randomly drawn codes, and everything is kept for when you come back.';
  @override
  String demoAccessAs(String name) => 'You will enter as $name';
  // PROVISIONAL privacy notice: the final wording is a business decision (D5, Luis).
  @override
  String get demoPrivacy =>
      'We will use your email only to send you the sign-in code and keep your balance and codes for this demo. '
      'We do not share it, and it is deleted when your account is removed or after a while without signing in.';
  @override
  String get demoEmailLabel => 'Email address';
  @override
  String get demoSendCode => 'Send me the code';
  @override
  String get demoCodeTitle => 'Check your email';
  @override
  String demoCodeSent(String email) => 'We sent a 6-digit code to $email. It expires in a few minutes.';
  @override
  String get demoCodeLabel => '6-digit code';
  @override
  String get demoVerify => 'Sign in';
  @override
  String get demoResend => 'Send another code';
  @override
  String demoResendIn(int seconds) => 'You can ask for another in $seconds s';
  @override
  String get demoChangeEmail => 'Use another email';
  @override
  String get demoSignOut => 'Sign out';
  @override
  String get demoShopTitle => 'Shop';
  @override
  String demoAddOne(String name) => 'Add one $name';
  @override
  String demoRemoveOne(String name) => 'Remove one $name';
  @override
  String demoQuantity(String name, int n) => '$name: $n';
  @override
  String get demoTotal => 'Total';
  @override
  String demoYouGet(String balance, int codes) => codes == 1
      ? 'You will receive $balance of balance and 1 code'
      : 'You will receive $balance of balance and $codes codes';
  @override
  String demoBelowMin(String min) => 'The minimum purchase is $min.';
  @override
  String get demoEmptyCart => 'Pick at least one product.';
  @override
  String get demoPay => 'Pay (simulated)';
  @override
  String get demoPaying => 'Processing the simulated payment…';
  @override
  String get demoLoadedTitle => 'Balance loaded';
  @override
  String demoCredited(String amount) => '+ $amount to your balance';
  @override
  String get demoNewBalance => 'Your balance now';
  @override
  String demoCodesDelivered(int n) => n == 1 ? '1 code to play' : '$n codes to play';
  @override
  String get demoCapReached => 'You reached the demo code limit: you received fewer codes than you earned.';
  @override
  String get demoSeeCodes => 'See my codes';
  @override
  String get demoCodesTitle => 'Your codes';
  @override
  String get demoCodesEmpty => 'You have no codes yet. Buy to receive some.';
  @override
  String get demoPlay => 'Play';
  @override
  String get demoPlayed => 'Played';
  @override
  String get demoBuyMore => 'Keep shopping';
  @override
  String get demoRetry => 'Retry';
  @override
  String get demoUnavailable => 'The demo is not available right now.';
  @override
  String demoStepLabel(int index) => const ['Access', 'Shop', 'Payment', 'Balance', 'Your codes', 'Game'][index];
  @override
  String demoGameName(String game) => switch (game) {
    'roulette' => 'Roulette',
    'scratch' => 'Scratch & Win',
    'memory' => 'Memory',
    'dice' => 'Dice',
    'bomb' => 'Bomb 8',
    'bingo' => 'Live bingo',
    'poker' => 'Dealer poker',
    _ => game,
  };
  @override
  String demoFailure(DemoFailure reason, {String? message}) => switch (reason) {
    DemoFailure.off => 'The demo is not available right now.',
    DemoFailure.sessionInvalid => 'Your demo session expired. Sign in again.',
    DemoFailure.poolExhausted => 'The demo ran out of codes. Tell the organizer.',
    DemoFailure.invalidEmail => 'Enter a valid email address.',
    DemoFailure.invalidCode => 'The code is not valid or has expired. Ask for a new one.',
    DemoFailure.mailUnavailable => 'We could not send the email. Try again in a moment.',
    DemoFailure.accountsFull => 'The demo reached its account limit. Tell the organizer.',
    DemoFailure.invalidPurchase => message ?? 'The purchase is not valid.',
    DemoFailure.rateLimited => 'Too many attempts. Wait a moment.',
    DemoFailure.network => 'No connection. Check your internet and try again.',
    DemoFailure.unknown => 'An unexpected error occurred. Try again.',
  };

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
    FailureReason.noSpinsLeft => 'You have no spins left to play.',
    FailureReason.spinReserveExhausted => 'The extra spins of this promotion have run out.',
    FailureReason.unauthorized => 'Your session is not valid. Please reopen the app and try again.',
    FailureReason.unknown => 'An unexpected error occurred. Please try again.',
  };
}
