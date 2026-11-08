import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:gamification_core/gamification_core.dart';
import 'package:gamification_sdk/gamification_sdk.dart';

class _TestGame implements GameModule {
  _TestGame(this.id);
  @override
  final String id;

  @override
  Widget build(BuildContext context, GameSession session) => Column(
    children: [
      Text('JUEGO:$id'),
      ElevatedButton(onPressed: session.onStarted, child: const Text('empezar')),
      ElevatedButton(onPressed: session.onFinished, child: const Text('terminar')),
    ],
  );
}

const _rules = DemoRules(
  balancePercent: 10,
  minPurchase: 10000,
  codeStepAmount: 20000,
  maxCodesPerPurchase: 3,
  maxCodesPerSession: 10,
);

/// Servidor de la muestra simulado: registra los pedidos de código, los ingresos y las compras, y puede fallar.
class _FakeBackend implements DemoBackend {
  _FakeBackend({this.codes = const ['RULETA-50K'], this.capReached = false, this.rules = _rules});
  final DemoRules rules;
  final List<String> codes;
  final bool capReached;
  static const validCode = '123456';
  final requests = <String>[];
  final verifies = <(String, String)>[];
  var signOuts = 0;
  var retryAfter = const Duration(seconds: 60);
  final purchases = <(List<DemoLine>, String)>[];

  /// Fallos de la próxima compra.
  final failures = <DemoException>[];

  /// Fallo del próximo pedido de código / del próximo ingreso.
  DemoException? requestFailure;
  DemoException? verifyFailure;
  Completer<void>? gate;
  var balance = 5000;

  /// Códigos que la cuenta ya tiene al entrar (cuenta que vuelve).
  List<DemoOwnedCode> stored = const [];

  @override
  Future<bool> status() async => true;

  @override
  Future<DemoCodeRequest> requestCode(String email) async {
    requests.add(email);
    if (requestFailure != null) throw requestFailure!;
    return DemoCodeRequest(retryAfter: retryAfter);
  }

  @override
  Future<DemoSession> verifyCode(String email, String code) async {
    verifies.add((email, code));
    if (verifyFailure != null) throw verifyFailure!;
    if (code != validCode) throw const DemoException(DemoFailure.invalidCode);
    return DemoSession(
      displayName: 'Cliente de muestra',
      balance: balance,
      resumed: false,
      rules: rules,
      products: const [
        DemoProduct(id: 'r20', name: 'Recarga 20', price: 20000),
        DemoProduct(id: 'r50', name: 'Recarga 50', price: 50000),
      ],
    );
  }

  @override
  Future<DemoAccount> account() async => DemoAccount(balance: balance, codes: stored);

  @override
  void signOut() => signOuts++;

  @override
  Future<DemoOrder> purchase(List<DemoLine> lines, {required String idempotencyKey}) async {
    purchases.add((lines, idempotencyKey));
    await gate?.future;
    if (failures.isNotEmpty) throw failures.removeAt(0);
    final total = lines.fold(0, (s, l) => s + l.quantity * (l.productId == 'r20' ? 20000 : 50000));
    final credited = total * 10 ~/ 100;
    balance += credited;
    return DemoOrder(
      orderId: 'o-${purchases.length}',
      total: total,
      credited: credited,
      codes: [for (final c in codes) DemoDeliveredCode(code: c, game: 'roulette')],
      capReached: capReached,
      balance: balance,
      replayed: false,
    );
  }
}

Widget _app(_FakeBackend backend, {GamificationRepository? repo, Locale locale = const Locale('es')}) {
  final registry = GameRegistry()..register(_TestGame('roulette'));
  return MaterialApp(
    home: Scaffold(
      body: DemoJourney(
        backend: backend,
        repository: repo ?? MockRepository(),
        registry: registry,
        events: GamificationEvents(),
        locale: locale,
        minPaymentTime: Duration.zero,
        newKey: _keys(),
      ),
    ),
  );
}

String Function() _keys() {
  var n = 0;
  return () => 'clave-${++n}';
}

/// Ingreso completo: correo → código recibido → entra.
Future<void> _enter(WidgetTester t, {String email = 'ana@ejemplo.com', String code = _FakeBackend.validCode}) async {
  await t.enterText(find.byKey(const ValueKey('demo-email')), email);
  await t.tap(find.byKey(const ValueKey('demo-send-code')));
  await t.pumpAndSettle();
  await t.enterText(find.byKey(const ValueKey('demo-code')), code);
  await t.tap(find.byKey(const ValueKey('demo-verify')));
  await t.pumpAndSettle();
}

Future<void> _add(WidgetTester t, String id, [int times = 1]) async {
  for (var i = 0; i < times; i++) {
    await t.tap(find.byKey(ValueKey('demo-add-$id')));
    await t.pump();
  }
}

void main() {
  testWidgets('recorrido completo: acceso, tienda, pago, saldo cargado, códigos y juego con el flujo común', (t) async {
    final backend = _FakeBackend();
    final repo = MockRepository();
    await t.pumpWidget(_app(backend, repo: repo));

    expect(find.text('Bienvenido a la muestra'), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-banner')), findsOneWidget);
    await _enter(t);

    // Tienda: el saldo es el del servidor y la vista previa usa las reglas.
    expect(find.text('Tienda'), findsWidgets);
    expect(find.text('\$ 5.000'), findsOneWidget);
    expect(tester(t, 'demo-pay').onPressed, isNull, reason: 'carrito vacío');
    await _add(t, 'r20', 2);
    expect(find.text('\$ 40.000'), findsOneWidget);
    expect(find.text('Recibirás \$ 4.000 de saldo y 2 códigos'), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();

    // Saldo cargado: lo que dice la orden del servidor.
    expect(find.text('Saldo cargado'), findsOneWidget);
    expect(find.text('+ \$ 4.000 a tu saldo'), findsOneWidget);
    expect(find.text('\$ 9.000'), findsOneWidget);
    expect(find.text('1 código para jugar'), findsOneWidget);
    expect(backend.purchases.single.$1.single.quantity, 2);
    expect(backend.purchases.single.$2, 'clave-1');

    await t.tap(find.byKey(const ValueKey('demo-see-codes')));
    await t.pumpAndSettle();
    expect(find.text('Tus códigos'), findsWidgets);
    expect(find.text('RULETA-50K'), findsOneWidget);
    expect(find.text('Ruleta'), findsOneWidget);

    // Jugar: se canjea solo el código entregado, sin teclearlo.
    await t.tap(find.byKey(const ValueKey('demo-play-0')));
    await t.pumpAndSettle();
    expect(find.text('JUEGO:roulette'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('demo-banner')),
      findsOneWidget,
      reason: 'el aviso de muestra sigue dentro del juego',
    );
    await t.tap(find.text('empezar'));
    await t.pump();
    await t.tap(find.text('terminar'));
    await t.pumpAndSettle();
    await t.tap(find.text('Continuar'));
    await t.pumpAndSettle();

    // De vuelta en la lista: el código quedó jugado y no se puede volver a jugar.
    expect(find.text('Tus códigos'), findsWidgets);
    expect(find.byKey(const ValueKey('demo-played-0')), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-play-0')), findsNothing);
    // Premio de dinero: el saldo se refrescó con el repositorio (120.000 + 50.000 del mock).
    expect(find.text('\$ 170.000'), findsOneWidget);
  });

  testWidgets('el aviso de muestra y la barra de pasos están en todas las etapas', (t) async {
    await t.pumpWidget(_app(_FakeBackend()));
    Finder banner() => find.byKey(const ValueKey('demo-banner'));
    expect(banner(), findsOneWidget);
    await _enter(t);
    expect(banner(), findsOneWidget);
    await _add(t, 'r20');
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(banner(), findsOneWidget);
    for (final label in ['Acceso', 'Tienda', 'Pago', 'Saldo', 'Tus códigos', 'Juego']) {
      expect(find.text(label), findsWidgets, reason: label);
    }
  });

  testWidgets('compra bajo el mínimo: avisa, no deja pagar y no llama al servidor; al alcanzarlo se habilita', (
    t,
  ) async {
    final backend = _FakeBackend(
      rules: const DemoRules(
        balancePercent: 10,
        minPurchase: 30000,
        codeStepAmount: 20000,
        maxCodesPerPurchase: 3,
        maxCodesPerSession: 10,
      ),
    );
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    expect(find.text('La compra mínima es \$ 30.000.'), findsOneWidget);
    expect(tester(t, 'demo-pay').onPressed, isNull);
    await _add(t, 'r20');
    expect(find.text('La compra mínima es \$ 30.000.'), findsNothing);
    expect(tester(t, 'demo-pay').onPressed, isNotNull);
    expect(backend.purchases, isEmpty);
    // Quitar productos vuelve a bloquear.
    await t.tap(find.byKey(const ValueKey('demo-remove-r20')));
    await t.pump();
    expect(tester(t, 'demo-pay').onPressed, isNull);
  });

  testWidgets('sin red la compra se reintenta con la MISMA clave; si el carrito cambia, la clave es nueva', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    backend.failures.add(const DemoException(DemoFailure.network));
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(find.text('Sin conexión. Verifica tu internet e inténtalo de nuevo.'), findsOneWidget);
    expect(find.text('Tienda'), findsWidgets);

    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(backend.purchases.map((p) => p.$2), ['clave-1', 'clave-1']);
    expect(find.text('Saldo cargado'), findsOneWidget);

    // Compra siguiente: clave nueva (la anterior terminó).
    await t.tap(find.byKey(const ValueKey('demo-see-codes')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('demo-buy-more')));
    await t.pumpAndSettle();
    await _add(t, 'r20');
    backend.failures.add(const DemoException(DemoFailure.network));
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    await _add(t, 'r50'); // cambia el carrito: ya no es la misma compra
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(backend.purchases.map((p) => p.$2), ['clave-1', 'clave-1', 'clave-2', 'clave-3']);
  });

  testWidgets('un rechazo del servidor (no de red) muestra el motivo y descarta la clave', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    backend.failures.add(const DemoException(DemoFailure.invalidPurchase, message: 'La compra no alcanza el mínimo'));
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(find.text('La compra no alcanza el mínimo'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(backend.purchases.map((p) => p.$2), ['clave-1', 'clave-2']);
  });

  testWidgets('pool agotado: mensaje claro y se puede volver a intentar', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    backend.failures.add(const DemoException(DemoFailure.poolExhausted));
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(find.text('La muestra se quedó sin códigos. Avisa al organizador.'), findsOneWidget);
    expect(tester(t, 'demo-pay').onPressed, isNotNull);
  });

  testWidgets('mientras se paga se ve el pago simulado y no se puede pagar dos veces', (t) async {
    final backend = _FakeBackend()..gate = Completer<void>();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pump();
    expect(find.byKey(const ValueKey('demo-paying')), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-pay')), findsNothing, reason: 'sin botón durante el pago');
    backend.gate!.complete();
    await t.pumpAndSettle();
    expect(backend.purchases, hasLength(1));
    expect(find.text('Saldo cargado'), findsOneWidget);
  });

  testWidgets('tope por sesión: avisa que se entregaron menos códigos', (t) async {
    await t.pumpWidget(_app(_FakeBackend(capReached: true)));
    await _enter(t);
    await _add(t, 'r20');
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(find.byKey(const ValueKey('demo-cap')), findsOneWidget);
  });

  testWidgets(
    'correo: se pide con el aviso de privacidad, un correo sin @ no llama al servidor y el código sale hacia ese correo',
    (t) async {
      final backend = _FakeBackend();
      await t.pumpWidget(_app(backend));
      expect(find.byKey(const ValueKey('demo-privacy')), findsOneWidget);
      expect(find.textContaining('solo para enviarte el código de ingreso'), findsOneWidget);

      await t.enterText(find.byKey(const ValueKey('demo-email')), 'esto no es un correo');
      await t.tap(find.byKey(const ValueKey('demo-send-code')));
      await t.pumpAndSettle();
      expect(find.text('Escribe un correo válido.'), findsOneWidget);
      expect(backend.requests, isEmpty);

      await t.enterText(find.byKey(const ValueKey('demo-email')), '  ana@ejemplo.com ');
      await t.tap(find.byKey(const ValueKey('demo-send-code')));
      await t.pumpAndSettle();
      expect(backend.requests, ['ana@ejemplo.com']);
      expect(find.text('Revisa tu correo'), findsOneWidget);
      expect(find.textContaining('ana@ejemplo.com'), findsOneWidget);
      await t.pumpWidget(const SizedBox());
    },
  );

  testWidgets('el reenvío espera lo que dice el servidor y luego se habilita', (t) async {
    final backend = _FakeBackend()..retryAfter = const Duration(seconds: 5);
    await t.pumpWidget(_app(backend));
    await t.enterText(find.byKey(const ValueKey('demo-email')), 'ana@ejemplo.com');
    await t.tap(find.byKey(const ValueKey('demo-send-code')));
    await t.pumpAndSettle();
    OutlinedButton resend() => t.widget<OutlinedButton>(find.byKey(const ValueKey('demo-resend')));
    expect(resend().onPressed, isNull);
    expect(find.textContaining('Podrás pedir otro en'), findsOneWidget);
    await t.pump(const Duration(seconds: 6));
    expect(resend().onPressed, isNotNull);
    expect(find.text('Pedir otro código'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('demo-resend')));
    await t.pump();
    expect(backend.requests, ['ana@ejemplo.com', 'ana@ejemplo.com']);
    await t.pumpWidget(const SizedBox());
  });

  testWidgets('un código errado muestra un único mensaje y deja reintentar con el correcto', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t, code: '000000');
    expect(find.text('El código no es válido o ya venció. Pide uno nuevo.'), findsOneWidget);
    expect(find.text('Revisa tu correo'), findsOneWidget, reason: 'sigue en el paso del código');
    expect(find.byKey(const ValueKey('demo-total')), findsNothing);
    await t.enterText(find.byKey(const ValueKey('demo-code')), _FakeBackend.validCode);
    await t.tap(find.byKey(const ValueKey('demo-verify')));
    await t.pumpAndSettle();
    expect(find.text('Tienda'), findsWidgets);
    expect(backend.verifies.map((v) => v.$2), ['000000', '123456']);
  });

  testWidgets('un código incompleto no llama al servidor y el campo solo admite 6 dígitos', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await t.enterText(find.byKey(const ValueKey('demo-email')), 'ana@ejemplo.com');
    await t.tap(find.byKey(const ValueKey('demo-send-code')));
    await t.pumpAndSettle();
    await t.enterText(find.byKey(const ValueKey('demo-code')), '12ab34');
    expect(t.widget<TextField>(find.byKey(const ValueKey('demo-code'))).controller!.text, '1234');
    await t.tap(find.byKey(const ValueKey('demo-verify')));
    await t.pumpAndSettle();
    expect(backend.verifies, isEmpty);
    await t.enterText(find.byKey(const ValueKey('demo-code')), '123456789');
    expect(t.widget<TextField>(find.byKey(const ValueKey('demo-code'))).controller!.text, '123456');
    await t.pumpWidget(const SizedBox());
  });

  testWidgets(
    'correo que no se pudo enviar, muestra apagada y tope de cuentas: mensajes claros y se puede reintentar',
    (t) async {
      final backend = _FakeBackend()..requestFailure = const DemoException(DemoFailure.mailUnavailable);
      await t.pumpWidget(_app(backend));
      await t.enterText(find.byKey(const ValueKey('demo-email')), 'ana@ejemplo.com');
      await t.tap(find.byKey(const ValueKey('demo-send-code')));
      await t.pumpAndSettle();
      expect(find.text('No pudimos enviar el correo. Inténtalo de nuevo en un momento.'), findsOneWidget);
      expect(find.text('Revisa tu correo'), findsNothing, reason: 'no avanza si el correo no salió');

      backend.requestFailure = const DemoException(DemoFailure.off);
      await t.tap(find.byKey(const ValueKey('demo-send-code')));
      await t.pumpAndSettle();
      expect(find.text('La muestra no está disponible en este momento.'), findsOneWidget);

      backend.requestFailure = null;
      backend.verifyFailure = const DemoException(DemoFailure.accountsFull);
      await _enter(t);
      expect(find.text('La muestra llegó a su tope de cuentas. Avisa al organizador.'), findsOneWidget);
      backend.verifyFailure = null;
      await t.tap(find.byKey(const ValueKey('demo-verify')));
      await t.pumpAndSettle();
      expect(find.text('Tienda'), findsWidgets);
      expect(backend.requests, hasLength(3));
    },
  );

  testWidgets('«usar otro correo» vuelve al primer paso', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await t.enterText(find.byKey(const ValueKey('demo-email')), 'ana@ejemplo.com');
    await t.tap(find.byKey(const ValueKey('demo-send-code')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('demo-change-email')));
    await t.pumpAndSettle();
    expect(find.byKey(const ValueKey('demo-email')), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-code')), findsNothing);
    await t.pumpWidget(const SizedBox());
  });

  testWidgets('cuenta que vuelve: entra a «Tus códigos» con el saldo acumulado y cada código en su estado', (t) async {
    final backend = _FakeBackend()
      ..balance = 9000
      ..stored = const [
        DemoOwnedCode(code: 'RULETA-JUGADO', game: 'roulette', played: true),
        DemoOwnedCode(code: 'RULETA-50K', game: 'roulette', played: false),
      ];
    await t.pumpWidget(_app(backend));
    await _enter(t);
    expect(find.text('Tus códigos'), findsWidgets);
    expect(find.text('\$ 9.000'), findsOneWidget, reason: 'el saldo acumulado de la cuenta');
    expect(find.text('RULETA-JUGADO'), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-played-0')), findsOneWidget);
    expect(find.byKey(const ValueKey('demo-play-0')), findsNothing);
    expect(find.byKey(const ValueKey('demo-play-1')), findsOneWidget, reason: 'el pendiente se puede jugar');
    await t.tap(find.byKey(const ValueKey('demo-play-1')));
    await t.pumpAndSettle();
    expect(find.text('JUEGO:roulette'), findsOneWidget);
  });

  testWidgets('«Salir» olvida la sesión de este dispositivo y no deja rastro de la cuenta anterior', (t) async {
    final backend = _FakeBackend()
      ..stored = const [DemoOwnedCode(code: 'RULETA-DE-ANA', game: 'roulette', played: false)];
    await t.pumpWidget(_app(backend));
    await _enter(t);
    expect(find.text('RULETA-DE-ANA'), findsOneWidget);
    await t.ensureVisible(find.byKey(const ValueKey('demo-signout')));
    await t.tap(find.byKey(const ValueKey('demo-signout')));
    await t.pumpAndSettle();
    expect(backend.signOuts, 1);
    expect(find.byKey(const ValueKey('demo-email')), findsOneWidget);
    expect(t.widget<TextField>(find.byKey(const ValueKey('demo-email'))).controller!.text, isEmpty);
    expect(find.text('RULETA-DE-ANA'), findsNothing);

    // Otra persona entra en el mismo dispositivo: ve SU cuenta, no la de Ana.
    backend.stored = const [];
    await _enter(t, email: 'luis@ejemplo.com');
    expect(find.text('Tienda'), findsWidgets);
    expect(find.text('RULETA-DE-ANA'), findsNothing);
    expect(find.byKey(const ValueKey('demo-to-codes')), findsNothing);
  });

  testWidgets('si el servidor cierra la sesión al comprar (cuenta borrada), vuelve al acceso con el motivo', (t) async {
    final backend = _FakeBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20');
    backend.failures.add(const DemoException(DemoFailure.sessionInvalid));
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    expect(find.byKey(const ValueKey('demo-email')), findsOneWidget);
    expect(find.text('Tu sesión de la muestra venció. Vuelve a entrar.'), findsOneWidget);
    expect(backend.signOuts, 1);
  });

  testWidgets('el saldo que se muestra al entrar es el de la cuenta (más reciente que el de la sesión)', (t) async {
    await t.pumpWidget(_app(_FreshBalanceBackend()));
    await _enter(t);
    expect(find.text('\$ 7.777'), findsOneWidget);
    expect(find.text('\$ 5.000'), findsNothing);
  });

  testWidgets('si no se pueden traer los códigos al entrar, igual se entra con el saldo de la sesión', (t) async {
    final backend = _FailingAccountBackend();
    await t.pumpWidget(_app(backend));
    await _enter(t);
    expect(find.text('Tienda'), findsWidgets);
    expect(find.text('\$ 5.000'), findsOneWidget);
  });

  testWidgets('varios códigos: se juegan en secuencia y cada uno queda marcado', (t) async {
    final backend = _FakeBackend(codes: ['RULETA-50K', 'RULETA-NADA']);
    await t.pumpWidget(_app(backend));
    await _enter(t);
    await _add(t, 'r20', 2);
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('demo-see-codes')));
    await t.pumpAndSettle();

    for (var i = 0; i < 2; i++) {
      await t.tap(find.byKey(ValueKey('demo-play-$i')));
      await t.pumpAndSettle();
      await t.tap(find.text('empezar'));
      await t.pump();
      await t.tap(find.text('terminar'));
      await t.pumpAndSettle();
      await t.tap(find.text('Continuar'));
      await t.pumpAndSettle();
      expect(find.byKey(ValueKey('demo-played-$i')), findsOneWidget);
    }
    expect(find.byKey(const ValueKey('demo-play-0')), findsNothing);
    expect(find.byKey(const ValueKey('demo-play-1')), findsNothing);
  });

  testWidgets('cerrar el juego sin haber canjeado (red caída) deja el código disponible', (t) async {
    final repo = _OfflineRepo();
    await t.pumpWidget(_app(_FakeBackend(), repo: repo));
    await _enter(t);
    await _add(t, 'r20');
    await t.tap(find.byKey(const ValueKey('demo-pay')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('demo-see-codes')));
    await t.pumpAndSettle();
    await t.tap(find.byKey(const ValueKey('demo-play-0')));
    await t.pumpAndSettle();
    expect(find.text('Sin conexión. Verifica tu internet e inténtalo de nuevo.'), findsOneWidget);
    expect(
      find.widgetWithText(TextField, 'RULETA-50K'),
      findsOneWidget,
      reason: 'el código queda escrito para reintentar',
    );
    await t.tap(find.byTooltip('Cerrar'));
    await t.pumpAndSettle();
    expect(find.byKey(const ValueKey('demo-play-0')), findsOneWidget, reason: 'no se canjeó: sigue disponible');
    expect(find.byKey(const ValueKey('demo-played-0')), findsNothing);
  });

  testWidgets('en inglés los textos del recorrido salen en inglés', (t) async {
    await t.pumpWidget(_app(_FakeBackend(), locale: const Locale('en')));
    expect(find.text('Welcome to the demo'), findsOneWidget);
    expect(find.text('Email address'), findsOneWidget);
    expect(find.text('Send me the code'), findsOneWidget);
    expect(find.textContaining('We will use your email only'), findsOneWidget);
    expect(find.text('DEMO · Simulated data, no real money'), findsOneWidget);
  });
}

FilledButton tester(WidgetTester t, String key) => t.widget<FilledButton>(find.byKey(ValueKey(key)));

class _OfflineRepo implements GamificationRepository {
  @override
  Future<Redemption> redeem(String code, {required String idempotencyKey}) async =>
      throw const GamificationException(FailureReason.network);

  @override
  Future<Redemption> spin(String parentRedemptionId, {required String idempotencyKey}) async =>
      throw const GamificationException(FailureReason.network);

  @override
  Future<Balance> getBalance() async => throw const GamificationException(FailureReason.network);
}

class _FailingAccountBackend extends _FakeBackend {
  @override
  Future<DemoAccount> account() async => throw const DemoException(DemoFailure.network);
}

class _FreshBalanceBackend extends _FakeBackend {
  @override
  Future<DemoAccount> account() async => const DemoAccount(balance: 7777, codes: []);
}
