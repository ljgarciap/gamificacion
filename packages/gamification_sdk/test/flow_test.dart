import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';
import 'package:gamification_sdk/src/flow/confetti.dart';

/// Juego de prueba: dos botones que simulan "el usuario empezó" y "terminó la animación".
class _TestGame implements GameModule {
  _TestGame(this.id);
  @override
  final String id;

  @override
  Widget build(BuildContext context, GameSession session) => Column(
    children: [
      Text('JUEGO:$id:${session.redemption.outcome.gameResult}'),
      ElevatedButton(onPressed: session.onStarted, child: const Text('empezar')),
      ElevatedButton(onPressed: session.onFinished, child: const Text('terminar')),
    ],
  );
}

/// Repositorio que falla con red una vez y registra las claves de idempotencia recibidas.
class _FlakyRepo implements GamificationRepository {
  _FlakyRepo(this.inner);
  final MockRepository inner;
  final keys = <String>[];
  var failNext = true;

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async {
    keys.add(idempotencyKey);
    if (failNext) {
      failNext = false;
      throw const GamificationException(FailureReason.network);
    }
    return inner.redeem(code, idempotencyKey: idempotencyKey);
  }

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) =>
      inner.spin(parentRedemptionId, idempotencyKey: idempotencyKey);

  @override
  Future<Balance> getBalance() => inner.getBalance();
}

/// Repositorio que registra las claves de los giros y puede fallar con red en el primero.
class _SpinRepo implements GamificationRepository {
  _SpinRepo(this.inner, {this.failFirst = false, this.failWith});
  final MockRepository inner;
  final bool failFirst;
  final FailureReason? failWith;
  final spinKeys = <String>[];

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) =>
      inner.redeem(code, idempotencyKey: idempotencyKey);

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) async {
    spinKeys.add(idempotencyKey);
    if (failWith != null) throw GamificationException(failWith!);
    if (failFirst && spinKeys.length == 1) throw const GamificationException(FailureReason.network);
    return inner.spin(parentRedemptionId, idempotencyKey: idempotencyKey);
  }

  @override
  Future<Balance> getBalance() => inner.getBalance();
}

/// Repositorio cuyo canje concede `granted` giros (los hijos no conceden más) o devuelve un resultado ya jugado.
class _GrantRepo implements GamificationRepository {
  _GrantRepo(this.inner, {required this.granted, this.alreadyPlayed = false});
  final MockRepository inner;
  final int granted;
  final bool alreadyPlayed;
  final spinKeys = <String>[];
  Redemption? _parent;

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async {
    final r = await inner.redeem(code, idempotencyKey: idempotencyKey);
    return _parent = Redemption(
      redemptionId: r.redemptionId,
      campaign: r.campaign,
      outcome: Outcome(prize: r.outcome.prize, extraSpins: granted, gameResult: r.outcome.gameResult),
      alreadyPlayed: alreadyPlayed,
      playedAt: r.playedAt,
    );
  }

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) async {
    spinKeys.add(idempotencyKey);
    return Redemption(
      redemptionId: 'hijo-${spinKeys.length}',
      campaign: _parent!.campaign,
      outcome: const Outcome(
        prize: Prize(id: 'none', type: PrizeType.none, label: 'Sin premio'),
        gameResult: {'segmentIndex': 1},
      ),
      parentRedemptionId: parentRedemptionId,
      spinNo: spinKeys.length,
    );
  }

  @override
  Future<Balance> getBalance() => inner.getBalance();
}

/// Simulador cuyo primer giro hijo concede OTRO giro (un «giro extra» sacado de la reserva).
class _ChainRepo implements GamificationRepository {
  _ChainRepo(this.inner);
  final MockRepository inner;
  final spinKeys = <String>[];
  Redemption? _parent;

  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async =>
      _parent = await inner.redeem(code, idempotencyKey: idempotencyKey);

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) async {
    spinKeys.add(idempotencyKey);
    final n = spinKeys.length;
    return Redemption(
      redemptionId: 'hijo-$n',
      campaign: _parent!.campaign,
      outcome: Outcome(
        prize: const Prize(id: 'extra', type: PrizeType.extraSpin, label: 'Gira de nuevo'),
        extraSpins: n == 1 ? 1 : 0,
        gameResult: const {'segmentIndex': 6},
      ),
      parentRedemptionId: parentRedemptionId,
      spinNo: 1,
    );
  }

  @override
  Future<Balance> getBalance() => inner.getBalance();
}

Future<void> _playOut(WidgetTester t) async {
  await t.tap(find.text('empezar'));
  await t.pump();
  await t.tap(find.text('terminar'));
  await t.pumpAndSettle();
}

GameRegistry _registry({bool scratch = true}) {
  final r = GameRegistry()..register(_TestGame('roulette'));
  if (scratch) r.register(_TestGame('scratch'));
  return r;
}

Future<void> _pump(
  WidgetTester tester, {
  required GamificationRepository repo,
  GamificationEvents? events,
  GameRegistry? registry,
  Locale locale = const Locale('es'),
  VoidCallback? onClose,
  Map<String, String>? demoCodes,
  String? helpText,
  bool disableAnimations = false,
}) async {
  tester.view.physicalSize = const Size(800, 1600);
  tester.view.devicePixelRatio = 2;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: disableAnimations),
        child: Scaffold(
          body: GamificationFlow(
            repository: repo,
            registry: registry ?? _registry(),
            events: events ?? GamificationEvents(),
            locale: locale,
            onClose: onClose,
            demoCodes: demoCodes,
            helpText: helpText,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _enterAndValidate(WidgetTester tester, String code, {String button = 'Validar código'}) async {
  await tester.enterText(find.byType(TextField), code);
  await tester.pump();
  await tester.tap(find.text(button));
  await tester.pumpAndSettle();
}

void main() {
  late MockRepository repo;
  setUp(() => repo = MockRepository(initialBalance: 120000));

  testWidgets('código inválido muestra el error y permanece en la pantalla de código', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'NOPE');
    expect(find.textContaining('no es válido'), findsOneWidget);
    expect(find.byType(TextField), findsOneWidget);
  });

  testWidgets('el botón validar está deshabilitado con el campo vacío', (t) async {
    await _pump(t, repo: repo);
    final button = t.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
  });

  testWidgets('el campo pasa a mayúsculas y quita espacios', (t) async {
    await _pump(t, repo: repo);
    await t.enterText(find.byType(TextField), 'ruleta 50k');
    expect(t.widget<TextField>(find.byType(TextField)).controller!.text, 'RULETA50K');
  });

  testWidgets('flujo completo ruleta: juego -> resultado, saldo solo cambia al terminar', (t) async {
    await _pump(t, repo: repo);
    expect(find.text(r'$ 120.000'), findsOneWidget);

    await _enterAndValidate(t, 'RULETA-50K');
    expect(find.textContaining('JUEGO:roulette'), findsOneWidget);
    // Validado, pero aún no jugado: el saldo no debe adelantar el premio.
    expect(find.text(r'$ 120.000'), findsOneWidget);

    await t.tap(find.text('empezar'));
    await t.pump();
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();

    expect(find.text('¡Ganaste!'), findsOneWidget);
    expect(find.text(r'$ 50.000'), findsOneWidget);
    expect(find.text('Se acreditó a tu saldo'), findsOneWidget);
    expect(find.text(r'$ 170.000'), findsOneWidget); // saldo en cabecera tras el count-up
  });

  testWidgets('giro extra: el resultado ofrece girar de nuevo y el servidor decide cada giro', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-EXTRA');
    await _playOut(t);

    expect(find.byKey(const ValueKey('spin-again')), findsOneWidget);
    expect(find.text('Girar de nuevo'), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    // Giro hijo: se juega con lo que devolvió el servidor (segmento 2 = $ 10.000 en el simulador).
    expect(find.textContaining("segmentIndex: 2"), findsOneWidget);
    await _playOut(t);

    expect(find.text(r'$ 10.000'), findsOneWidget);
    // Sin más giros: el botón principal vuelve a ser continuar.
    expect(find.byKey(const ValueKey('spin-again')), findsNothing);
    expect(find.text('Continuar'), findsOneWidget);
  });

  testWidgets('un giro hijo que concede más giros los encadena, con claves estables por padre y número', (t) async {
    final chain = _ChainRepo(repo);
    await _pump(t, repo: chain);
    await _enterAndValidate(t, 'RULETA-EXTRA');
    await _playOut(t);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    await _playOut(t);
    // El primer giro hijo concedió otro: se vuelve a ofrecer, y sus giros se juegan antes que los restantes del padre.
    expect(find.byKey(const ValueKey('spin-again')), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    await _playOut(t);

    expect(chain.spinKeys, ['r-1:1', 'hijo-1:1']);
    expect(find.byKey(const ValueKey('spin-again')), findsNothing);
  });

  testWidgets('un canje que concede dos giros los ofrece uno por uno, con la clave de cada número', (t) async {
    final grant = _GrantRepo(repo, granted: 2);
    await _pump(t, repo: grant);
    await _enterAndValidate(t, 'RULETA-EXTRA');
    await _playOut(t);
    expect(find.text('Girar de nuevo (2)'), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    await _playOut(t);
    expect(find.text('Girar de nuevo'), findsOneWidget, reason: 'queda un solo giro');

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    await _playOut(t);
    expect(grant.spinKeys, ['r-1:1', 'r-1:2']);
    expect(find.byKey(const ValueKey('spin-again')), findsNothing);
  });

  testWidgets('un resultado ya jugado (showPrevious) no vuelve a ofrecer giros', (t) async {
    await _pump(t, repo: _GrantRepo(repo, granted: 1, alreadyPlayed: true));
    await _enterAndValidate(t, 'RULETA-EXTRA');
    expect(find.byKey(const ValueKey('spin-again')), findsNothing);
  });

  testWidgets('sin red el giro se puede reintentar con la MISMA clave', (t) async {
    final spinRepo = _SpinRepo(repo, failFirst: true);
    await _pump(t, repo: spinRepo);
    await _enterAndValidate(t, 'RULETA-EXTRA');
    await _playOut(t);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    expect(find.textContaining('Sin conexión'), findsOneWidget);
    expect(find.byKey(const ValueKey('spin-again')), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    expect(spinRepo.spinKeys, hasLength(2));
    expect(spinRepo.spinKeys[0], spinRepo.spinKeys[1], reason: 'reintentar no debe consumir otra casilla');
    expect(find.textContaining('JUEGO:roulette'), findsOneWidget);
  });

  testWidgets('si el servidor dice que no hay giros (caducaron), se quitan y se explica', (t) async {
    final spinRepo = _SpinRepo(repo, failWith: FailureReason.noSpinsLeft);
    await _pump(t, repo: spinRepo);
    await _enterAndValidate(t, 'RULETA-EXTRA');
    await _playOut(t);

    await t.tap(find.byKey(const ValueKey('spin-again')));
    await t.pumpAndSettle();
    expect(find.byKey(const ValueKey('spin-again')), findsNothing);
    expect(find.text('Continuar'), findsOneWidget);
  });

  testWidgets('secuencia de eventos hacia el host (RF-10)', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, repo: repo, events: events);
    await _enterAndValidate(t, 'RULETA-50K');
    await t.tap(find.text('empezar'));
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    expect(seen, [ReadyEvent, CodeValidatedEvent, GameStartedEvent, PrizeWonEvent, BalanceChangedEvent]);
  });

  testWidgets('sin premio: mensaje amable y no se emite PrizeWon', (t) async {
    final events = GamificationEvents();
    final seen = <Type>[];
    events.stream.listen((e) => seen.add(e.runtimeType));
    await _pump(t, repo: repo, events: events);
    await _enterAndValidate(t, 'RULETA-NADA');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    expect(find.text('¡Esta vez no hubo suerte!'), findsOneWidget);
    expect(seen.contains(PrizeWonEvent), isFalse);
  });

  testWidgets('cupón muestra el código y permite copiarlo', (t) async {
    // Portapapeles simulado: registra el texto que la app intenta copiar.
    String? copied;
    t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') {
        copied = (call.arguments as Map)['text'] as String?;
      }
      return null;
    });
    addTearDown(() => t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-CUPON');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    expect(find.textContaining('GIRA20'), findsOneWidget);
    await t.tap(find.textContaining('GIRA20'));
    await t.pumpAndSettle();
    expect(find.textContaining('Copiado'), findsOneWidget);
    expect(copied, 'GIRA20');
  });

  testWidgets('si el portapapeles falla no se anuncia "Copiado"', (t) async {
    t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') throw PlatformException(code: 'denied');
      return null;
    });
    addTearDown(() => t.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-CUPON');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.textContaining('GIRA20'));
    await t.pumpAndSettle();
    expect(find.textContaining('Copiado'), findsNothing);
  });

  testWidgets('puntos se muestran como +N puntos', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-PUNTOS');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    expect(find.text('+250 puntos'), findsOneWidget);
    expect(find.text(r'$ 120.000'), findsOneWidget); // los puntos no tocan el saldo
  });

  testWidgets('código ya usado (rejectOnly) tras "Continuar" -> mensaje de código usado', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-50K');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.text('Continuar'));
    await t.pumpAndSettle();
    await _enterAndValidate(t, 'RULETA-50K');
    expect(find.text('Este código ya fue utilizado.'), findsOneWidget);
  });

  testWidgets('código ya usado (showPrevious) muestra el resultado anterior sin volver a jugar', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'RULETA-REPITE');
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.text('Continuar'));
    await t.pumpAndSettle();

    await _enterAndValidate(t, 'RULETA-REPITE');
    expect(find.textContaining('JUEGO:'), findsNothing);
    expect(find.textContaining('ya fue jugado el'), findsOneWidget);
    expect(find.text(r'$ 10.000'), findsOneWidget);
  });

  testWidgets('rate limit: bloquea el botón y muestra los segundos', (t) async {
    await _pump(t, repo: repo);
    await _enterAndValidate(t, 'LIMITE');
    expect(find.textContaining('30 s'), findsOneWidget);
    expect(t.widget<FilledButton>(find.byType(FilledButton)).onPressed, isNull);
    // Descarta el Timer.periodic de la cuenta regresiva desmontando el árbol.
    await t.pumpWidget(const SizedBox());
  });

  testWidgets('cerrar se bloquea mientras corre la animación', (t) async {
    var closed = false;
    await _pump(t, repo: repo, onClose: () => closed = true);
    await _enterAndValidate(t, 'RULETA-50K');
    expect(find.byTooltip('Cerrar'), findsOneWidget);

    await t.tap(find.text('empezar'));
    await t.pumpAndSettle();
    expect(find.byTooltip('Cerrar'), findsNothing);
    expect(find.text('Tu premio ya está asegurado'), findsOneWidget);

    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.byTooltip('Cerrar'));
    expect(closed, isTrue);
  });

  testWidgets('textos en inglés según el locale', (t) async {
    await _pump(t, repo: repo, locale: const Locale('en'));
    expect(find.text('Validate code'), findsOneWidget);
    await _enterAndValidate(t, 'NOPE', button: 'Validate code');
    expect(find.textContaining('not valid'), findsOneWidget);
  });

  testWidgets('juego no registrado en el host: mensaje claro', (t) async {
    await _pump(t, repo: repo, registry: _registry(scratch: false));
    await _enterAndValidate(t, 'RASPA-GANA');
    expect(find.text('Este juego no está disponible en esta versión.'), findsOneWidget);
  });

  testWidgets('reintento tras fallo de red reutiliza la Idempotency-Key', (t) async {
    final flaky = _FlakyRepo(repo);
    await _pump(t, repo: flaky);
    await _enterAndValidate(t, 'RULETA-50K');
    expect(find.textContaining('Sin conexión'), findsOneWidget);

    await t.tap(find.text('Validar código'));
    await t.pumpAndSettle();
    expect(find.textContaining('JUEGO:roulette'), findsOneWidget);
    expect(flaky.keys, hasLength(2));
    expect(flaky.keys[0], flaky.keys[1]);
  });

  testWidgets('panel demo: tocar un código lo carga en el campo', (t) async {
    await _pump(t, repo: repo, demoCodes: MockRepository.demoCodes);
    await t.tap(find.text('Códigos de prueba'));
    await t.pumpAndSettle();
    await t.tap(find.text('RULETA-50K'));
    await t.pump();
    expect(t.widget<TextField>(find.byType(TextField)).controller!.text, 'RULETA-50K');
  });

  testWidgets('enlace de ayuda solo aparece si el host da el texto', (t) async {
    await _pump(t, repo: repo);
    expect(find.text('¿Dónde encuentro mi código?'), findsNothing);
    await _pump(t, repo: repo, helpText: 'Está en tu comprobante.');
    await t.tap(find.text('¿Dónde encuentro mi código?'));
    await t.pumpAndSettle();
    expect(find.text('Está en tu comprobante.'), findsOneWidget);
  });

  group('confeti', () {
    testWidgets('aparece al ganar', (t) async {
      await _pump(t, repo: repo);
      await _enterAndValidate(t, 'RULETA-50K');
      await t.tap(find.text('terminar'));
      await t.pump();
      expect(find.byType(ConfettiBurst), findsOneWidget);
      await t.pumpAndSettle();
    });

    testWidgets('se omite con "reducir movimiento"', (t) async {
      await _pump(t, repo: repo, disableAnimations: true);
      await _enterAndValidate(t, 'RULETA-50K');
      await t.tap(find.text('terminar'));
      await t.pumpAndSettle();
      expect(find.byType(ConfettiBurst), findsNothing);
    });

    testWidgets('no aparece sin premio', (t) async {
      await _pump(t, repo: repo);
      await _enterAndValidate(t, 'RULETA-NADA');
      await t.tap(find.text('terminar'));
      await t.pumpAndSettle();
      expect(find.byType(ConfettiBurst), findsNothing);
    });
  });
}
