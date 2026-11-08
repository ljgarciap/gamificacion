import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:gamification_core/gamification_core.dart';

import 'gamification_flow.dart';

/// Recorrido de la MUESTRA REAL (SPEC-006): Acceso → Tienda → Pago simulado → Saldo cargado → Tus códigos → Juego.
///
/// El servidor decide todo (precios, saldo cargado, cuántos códigos y cuáles, el resultado de cada uno); aquí solo se muestra y se anima.
/// Los códigos que entrega la compra se canjean con el flujo común ([GamificationFlow] con `initialCode`), sin tocar el núcleo ni los juegos.
/// Un aviso fijo «MUESTRA · datos simulados» está visible en TODAS las etapas, también dentro del juego (T-D10: que nadie confunda esto con
/// un servicio real).
class DemoJourney extends StatefulWidget {
  const DemoJourney({
    super.key,
    required this.backend,
    required this.repository,
    required this.registry,
    required this.events,
    this.preferences = const GamePreferences(),
    this.locale = const Locale('es'),
    this.theme = const GamificationTheme(),
    this.minPaymentTime = const Duration(milliseconds: 1400),
    this.newKey,
  });

  final DemoBackend backend;

  /// Repositorio de canje y saldo, autenticado con la MISMA sesión de la muestra (`DemoApi` es su proveedor de token).
  final GamificationRepository repository;
  final GameRegistry registry;
  final GamificationEvents events;
  final GamePreferences preferences;
  final Locale locale;
  final GamificationTheme theme;

  /// Duración mínima de la pantalla «procesando el pago»: es teatro, el pago es simulado. En pruebas, cero.
  final Duration minPaymentTime;

  /// Generador de `Idempotency-Key` (inyectable en pruebas).
  final String Function()? newKey;

  @override
  State<DemoJourney> createState() => _DemoJourneyState();
}

enum _Stage { access, shop, paying, loaded, codes, play }

class _Owned {
  _Owned(this.code, this.game);
  final String code;
  final String game;
  bool played = false;
}

class _DemoJourneyState extends State<DemoJourney> {
  _Stage _stage = _Stage.access;
  DemoSession? _session;
  int _balance = 0;
  final _cart = <String, int>{};
  final _codes = <_Owned>[];
  DemoOrder? _order;
  _Owned? _playing;
  bool _validated = false;
  bool _busy = false;
  DemoException? _error;

  /// Clave de la compra en curso: sobrevive a un corte de red (el reintento del MISMO carrito no cobra dos veces) y se descarta si el
  /// carrito cambia o la compra termina.
  String? _purchaseKey;
  StreamSubscription<GamificationEvent>? _sub;

  GamificationStrings get _s => GamificationStrings.of(widget.locale);
  GamificationTheme get _t => widget.theme;

  @override
  void initState() {
    super.initState();
    _sub = widget.events.stream.listen((e) {
      if (e is CodeValidatedEvent) _validated = true;
    });
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }

  String _key() => (widget.newKey ?? _randomKey)();

  static String _randomKey() {
    final rnd = Random.secure();
    return List<int>.generate(16, (_) => rnd.nextInt(256)).map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }

  int get _total {
    final products = _session?.products ?? const [];
    return products.fold(0, (sum, p) => sum + p.price * (_cart[p.id] ?? 0));
  }

  List<DemoLine> get _lines => [
    for (final p in _session?.products ?? const <DemoProduct>[])
      if ((_cart[p.id] ?? 0) > 0) DemoLine(p.id, _cart[p.id]!),
  ];

  // --- Acciones -----------------------------------------------------------------------------------------------------

  Future<void> _enter() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final session = await widget.backend.openSession();
      if (!mounted) return;
      setState(() {
        _session = session;
        _balance = session.balance;
        _stage = _Stage.shop;
        _busy = false;
      });
    } on DemoException catch (e) {
      if (mounted) {
        setState(() {
          _error = e;
          _busy = false;
        });
      }
    }
  }

  void _setQuantity(DemoProduct p, int quantity) {
    setState(() {
      quantity <= 0 ? _cart.remove(p.id) : _cart[p.id] = quantity;
      _purchaseKey = null;
      _error = null;
    });
  }

  Future<void> _pay() async {
    final lines = _lines;
    if (lines.isEmpty) return;
    _purchaseKey ??= _key();
    final key = _purchaseKey!;
    final started = DateTime.now();
    setState(() {
      _stage = _Stage.paying;
      _error = null;
    });
    try {
      final order = await widget.backend.purchase(lines, idempotencyKey: key);
      final rest = widget.minPaymentTime - DateTime.now().difference(started);
      if (rest > Duration.zero) await Future<void>.delayed(rest);
      if (!mounted) return;
      setState(() {
        _purchaseKey = null;
        _cart.clear();
        _order = order;
        _balance = order.balance;
        _codes.addAll([for (final c in order.codes) _Owned(c.code, c.game)]);
        _stage = _Stage.loaded;
      });
    } on DemoException catch (e) {
      if (!mounted) return;
      setState(() {
        // Sin red se conserva la clave para reintentar sin cobrar dos veces; cualquier otro fallo es definitivo para esa compra.
        if (e.reason != DemoFailure.network) _purchaseKey = null;
        _error = e;
        _stage = _Stage.shop;
      });
    }
  }

  void _play(_Owned owned) {
    _validated = false;
    setState(() {
      _playing = owned;
      _stage = _Stage.play;
    });
  }

  Future<void> _backToCodes() async {
    final owned = _playing;
    if (owned != null && _validated) owned.played = true;
    setState(() {
      _playing = null;
      _stage = _Stage.codes;
    });
    try {
      final balance = await widget.repository.getBalance();
      if (mounted) setState(() => _balance = balance.amount);
    } catch (_) {
      // El saldo mostrado queda como estaba: es informativo.
    }
  }

  // --- Interfaz -----------------------------------------------------------------------------------------------------

  int get _stepIndex => switch (_stage) {
    _Stage.access => 0,
    _Stage.shop => 1,
    _Stage.paying => 2,
    _Stage.loaded => 3,
    _Stage.codes => 4,
    _Stage.play => 5,
  };

  @override
  Widget build(BuildContext context) {
    return Theme(
      data: _t.toThemeData(),
      child: Material(
        color: _t.background,
        child: SafeArea(
          child: Align(
            alignment: Alignment.topCenter,
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 480),
              child: Column(
                children: [
                  _banner(),
                  _steps(),
                  Expanded(child: _body()),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _banner() => Semantics(
    container: true,
    child: Container(
      key: const ValueKey('demo-banner'),
      width: double.infinity,
      color: _t.accent,
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      child: Text(
        _s.demoBanner,
        textAlign: TextAlign.center,
        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: _t.navy),
      ),
    ),
  );

  Widget _steps() {
    final current = _stepIndex;
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 4),
      child: Row(
        children: [
          for (var i = 0; i < 6; i++)
            Expanded(
              child: Semantics(
                label: _s.demoStepLabel(i),
                selected: i == current,
                child: ExcludeSemantics(
                  child: Column(
                    children: [
                      Container(
                        key: ValueKey('demo-step-$i'),
                        height: 4,
                        margin: const EdgeInsets.symmetric(horizontal: 2),
                        decoration: BoxDecoration(
                          color: i <= current ? _t.primary : _t.navy.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _s.demoStepLabel(i),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 10,
                          fontWeight: i == current ? FontWeight.w800 : FontWeight.w500,
                          color: _t.navy.withValues(alpha: i == current ? 1 : 0.6),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _body() => switch (_stage) {
    _Stage.access => _access(),
    _Stage.shop => _shop(),
    _Stage.paying => _paying(),
    _Stage.loaded => _loaded(),
    _Stage.codes => _codesList(),
    _Stage.play => _game(),
  };

  Widget _title(String text) => Semantics(
    header: true,
    child: Text(
      text,
      textAlign: TextAlign.center,
      style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: _t.navy),
    ),
  );

  Widget _errorText() => _error == null
      ? const SizedBox.shrink()
      : Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Text(
            _s.demoFailure(_error!.reason, message: _error!.message),
            key: const ValueKey('demo-error'),
            textAlign: TextAlign.center,
            style: TextStyle(color: _t.error, fontWeight: FontWeight.w600),
          ),
        );

  Widget _page(List<Widget> children) => SingleChildScrollView(
    padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children),
  );

  Widget _balanceLine() => Semantics(
    container: true,
    label: '${_s.balanceLabel} ${formatCop(_balance)}',
    child: ExcludeSemantics(
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Flexible(
            child: Text(_s.balanceLabel, style: TextStyle(fontSize: 13, color: _t.navy.withValues(alpha: 0.7))),
          ),
          const SizedBox(width: 8),
          Text(
            formatCop(_balance),
            key: const ValueKey('demo-balance'),
            style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: _t.navy),
          ),
        ],
      ),
    ),
  );

  Widget _access() => _page([
    _title(_s.demoAccessTitle),
    const SizedBox(height: 12),
    Text(
      _s.demoAccessBody,
      textAlign: TextAlign.center,
      style: TextStyle(fontSize: 16, color: _t.navy.withValues(alpha: 0.8)),
    ),
    const SizedBox(height: 24),
    FilledButton(
      key: const ValueKey('demo-enter'),
      onPressed: _busy ? null : _enter,
      child: _busy
          ? const SizedBox(
              height: 22,
              width: 22,
              child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
            )
          : Text(_error == null ? _s.demoEnter : _s.demoRetry),
    ),
    _errorText(),
  ]);

  Widget _shop() {
    final session = _session!;
    final total = _total;
    final preview = session.rules.preview(total);
    final canPay = total > 0 && preview.allowed;
    return _page([
      _title(_s.demoShopTitle),
      const SizedBox(height: 4),
      Text(
        _s.demoAccessAs(session.displayName),
        textAlign: TextAlign.center,
        style: TextStyle(color: _t.navy.withValues(alpha: 0.7)),
      ),
      const SizedBox(height: 12),
      Card(
        child: Padding(padding: const EdgeInsets.all(12), child: _balanceLine()),
      ),
      for (final p in session.products) _productRow(p),
      const SizedBox(height: 8),
      Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            _s.demoTotal,
            style: TextStyle(fontWeight: FontWeight.w700, color: _t.navy),
          ),
          Text(
            formatCop(total),
            key: const ValueKey('demo-total'),
            style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: _t.navy),
          ),
        ],
      ),
      const SizedBox(height: 8),
      if (total == 0)
        Text(
          _s.demoEmptyCart,
          key: const ValueKey('demo-hint'),
          textAlign: TextAlign.center,
          style: TextStyle(color: _t.navy.withValues(alpha: 0.7)),
        )
      else if (!preview.allowed)
        Text(
          _s.demoBelowMin(formatCop(session.rules.minPurchase)),
          key: const ValueKey('demo-hint'),
          textAlign: TextAlign.center,
          style: TextStyle(color: _t.error, fontWeight: FontWeight.w600),
        )
      else
        Text(
          _s.demoYouGet(formatCop(preview.balance), preview.codes),
          key: const ValueKey('demo-hint'),
          textAlign: TextAlign.center,
          style: TextStyle(color: _t.navy.withValues(alpha: 0.85)),
        ),
      const SizedBox(height: 16),
      FilledButton(key: const ValueKey('demo-pay'), onPressed: canPay ? _pay : null, child: Text(_s.demoPay)),
      if (_codes.isNotEmpty)
        TextButton(
          key: const ValueKey('demo-to-codes'),
          onPressed: () => setState(() => _stage = _Stage.codes),
          child: Text(_s.demoSeeCodes),
        ),
      _errorText(),
    ]);
  }

  Widget _productRow(DemoProduct p) {
    final n = _cart[p.id] ?? 0;
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    p.name,
                    style: TextStyle(fontWeight: FontWeight.w700, color: _t.navy),
                  ),
                  Text(formatCop(p.price), style: TextStyle(color: _t.navy.withValues(alpha: 0.7))),
                ],
              ),
            ),
            IconButton(
              key: ValueKey('demo-remove-${p.id}'),
              tooltip: _s.demoRemoveOne(p.name),
              onPressed: n > 0 ? () => _setQuantity(p, n - 1) : null,
              icon: const Icon(Icons.remove_circle_outline),
            ),
            Semantics(
              label: _s.demoQuantity(p.name, n),
              child: ExcludeSemantics(
                child: SizedBox(
                  width: 24,
                  child: Text(
                    '$n',
                    key: ValueKey('demo-qty-${p.id}'),
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ),
              ),
            ),
            IconButton(
              key: ValueKey('demo-add-${p.id}'),
              tooltip: _s.demoAddOne(p.name),
              onPressed: n < 10 ? () => _setQuantity(p, n + 1) : null,
              icon: const Icon(Icons.add_circle_outline),
            ),
          ],
        ),
      ),
    );
  }

  Widget _paying() => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const CircularProgressIndicator(),
        const SizedBox(height: 16),
        Text(
          _s.demoPaying,
          key: const ValueKey('demo-paying'),
          style: TextStyle(fontSize: 16, color: _t.navy),
        ),
      ],
    ),
  );

  Widget _loaded() {
    final order = _order!;
    final reduce = (MediaQuery.maybeDisableAnimationsOf(context) == true || widget.preferences.reduceMotion);
    return _page([
      _title(_s.demoLoadedTitle),
      const SizedBox(height: 16),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            children: [
              Text(
                _s.demoCredited(formatCop(order.credited)),
                key: const ValueKey('demo-credited'),
                style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: _t.success),
              ),
              const SizedBox(height: 12),
              Text(_s.demoNewBalance, style: TextStyle(fontSize: 13, color: _t.navy.withValues(alpha: 0.7))),
              TweenAnimationBuilder<int>(
                tween: IntTween(begin: order.balance - order.credited, end: order.balance),
                duration: reduce ? Duration.zero : const Duration(milliseconds: 900),
                curve: Curves.easeOut,
                builder: (_, v, __) => Text(
                  formatCop(v),
                  key: const ValueKey('demo-loaded-balance'),
                  style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: _t.navy),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                _s.demoCodesDelivered(order.codes.length),
                key: const ValueKey('demo-delivered'),
                style: TextStyle(fontWeight: FontWeight.w700, color: _t.navy),
              ),
            ],
          ),
        ),
      ),
      if (order.capReached)
        Padding(
          padding: const EdgeInsets.only(top: 12),
          child: Text(
            _s.demoCapReached,
            key: const ValueKey('demo-cap'),
            textAlign: TextAlign.center,
            style: TextStyle(color: _t.navy.withValues(alpha: 0.8)),
          ),
        ),
      const SizedBox(height: 16),
      FilledButton(
        key: const ValueKey('demo-see-codes'),
        onPressed: () => setState(() => _stage = _Stage.codes),
        child: Text(_s.demoSeeCodes),
      ),
    ]);
  }

  Widget _codesList() => _page([
    _title(_s.demoCodesTitle),
    const SizedBox(height: 12),
    Card(
      child: Padding(padding: const EdgeInsets.all(12), child: _balanceLine()),
    ),
    if (_codes.isEmpty)
      Padding(
        padding: const EdgeInsets.all(16),
        child: Text(
          _s.demoCodesEmpty,
          textAlign: TextAlign.center,
          style: TextStyle(color: _t.navy.withValues(alpha: 0.7)),
        ),
      ),
    for (var i = 0; i < _codes.length; i++) _codeRow(i, _codes[i]),
    const SizedBox(height: 12),
    OutlinedButton(
      key: const ValueKey('demo-buy-more'),
      onPressed: () => setState(() => _stage = _Stage.shop),
      child: Text(_s.demoBuyMore),
    ),
  ]);

  Widget _codeRow(int i, _Owned c) => Card(
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  _s.demoGameName(c.game),
                  style: TextStyle(fontWeight: FontWeight.w700, color: _t.navy),
                ),
                Text(c.code, style: TextStyle(letterSpacing: 1, color: _t.navy.withValues(alpha: 0.7))),
              ],
            ),
          ),
          if (c.played)
            Text(
              _s.demoPlayed,
              key: ValueKey('demo-played-$i'),
              style: TextStyle(fontWeight: FontWeight.w700, color: _t.navy.withValues(alpha: 0.6)),
            )
          else
            FilledButton(
              key: ValueKey('demo-play-$i'),
              style: FilledButton.styleFrom(minimumSize: const Size(88, 48)),
              onPressed: () => _play(c),
              child: Text(_s.demoPlay),
            ),
        ],
      ),
    ),
  );

  Widget _game() {
    final owned = _playing!;
    return GamificationFlow(
      key: ValueKey('demo-flow-${owned.code}'),
      repository: widget.repository,
      registry: widget.registry,
      events: widget.events,
      preferences: widget.preferences,
      locale: widget.locale,
      theme: _t,
      initialCode: owned.code,
      onClose: _backToCodes,
      onContinue: _backToCodes,
      onBackHome: _backToCodes,
    );
  }
}
