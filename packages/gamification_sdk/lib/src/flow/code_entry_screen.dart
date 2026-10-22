import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:gamification_core/gamification_core.dart';

import 'flow_controller.dart';
import 'demo_codes_panel.dart';
import 'flow_state.dart';

/// Pantalla 1 (ux-spec-001): ingreso y validación del código.
class CodeEntryScreen extends ConsumerStatefulWidget {
  const CodeEntryScreen({super.key, required this.title, this.helpText, this.demoCodes, this.demoCodesLoader});

  final String title;

  /// Contenido del bottom sheet "¿Dónde encuentro mi código?". Sin texto, el enlace no se muestra.
  final String? helpText;

  /// Código -> descripción. Solo en builds no productivos (RF-08).
  final Map<String, String>? demoCodes;

  /// Cargador de códigos de prueba; si existe, manda sobre [demoCodes]. Se consulta al aparecer la pantalla.
  final Future<Map<String, String>> Function()? demoCodesLoader;

  @override
  ConsumerState<CodeEntryScreen> createState() => _CodeEntryScreenState();
}

class _CodeEntryScreenState extends ConsumerState<CodeEntryScreen> {
  final _controller = TextEditingController();
  Timer? _tick;

  /// Códigos que devolvió el cargador (null mientras carga o si no hay cargador).
  Map<String, String>? _loadedDemoCodes;

  @override
  void initState() {
    super.initState();
    _controller.addListener(() => setState(() {}));
    _loadDemoCodes();
  }

  Future<void> _loadDemoCodes() async {
    final loader = widget.demoCodesLoader;
    if (loader == null) return;
    try {
      final codes = await loader();
      if (mounted) setState(() => _loadedDemoCodes = codes);
    } catch (_) {
      // Es solo una ayuda de desarrollo: si falla, el panel simplemente queda vacío.
    }
  }

  @override
  void dispose() {
    _tick?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _submit() => ref.read(flowControllerProvider.notifier).submit(_controller.text);

  /// Refresca la cuenta regresiva del bloqueo por rate limit una vez por segundo.
  void _syncTicker(FlowState state) {
    if (state.isBlocked && _tick == null) {
      _tick = Timer.periodic(const Duration(seconds: 1), (_) {
        if (!mounted) return;
        setState(() {});
        if (!ref.read(flowControllerProvider).isBlocked) {
          _tick?.cancel();
          _tick = null;
        }
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final scope = GamificationScope.of(context);
    final s = scope.strings;
    final theme = scope.theme;
    final state = ref.watch(flowControllerProvider);
    _syncTicker(state);

    final validating = state.stage == FlowStage.validating;
    final blocked = state.isBlocked;
    final canSubmit = !validating && !blocked && _controller.text.trim().isNotEmpty;

    // Segundos restantes redondeados hacia arriba: nunca muestra "0 s" con el botón aún bloqueado.
    final remaining = blocked
        ? Duration(seconds: (state.blockedUntil!.difference(DateTime.now()).inMilliseconds / 1000).ceil())
        : state.retryAfter;
    final errorText = state.unsupportedGame
        ? s.unsupportedGame
        : state.failure == null
        ? null
        : s.failure(state.failure!, retryAfter: blocked ? remaining : null);

    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SizedBox(height: 8),
          Semantics(
            header: true,
            child: Text(
              widget.title,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 30, fontWeight: FontWeight.w800, color: theme.navy),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            s.invitation,
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 16, color: theme.navy.withValues(alpha: 0.8)),
          ),
          const SizedBox(height: 24),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextField(
                    controller: _controller,
                    enabled: !validating,
                    autocorrect: false,
                    enableSuggestions: false,
                    autofillHints: null,
                    textCapitalization: TextCapitalization.characters,
                    textInputAction: TextInputAction.done,
                    inputFormatters: [
                      FilteringTextInputFormatter.deny(RegExp(r'\s')),
                      TextInputFormatter.withFunction((old, value) => value.copyWith(text: value.text.toUpperCase())),
                    ],
                    onSubmitted: (_) {
                      if (canSubmit) _submit();
                    },
                    style: const TextStyle(fontSize: 20, letterSpacing: 2, fontWeight: FontWeight.w600),
                    decoration: InputDecoration(
                      labelText: s.codeLabel,
                      errorText: errorText,
                      errorMaxLines: 3,
                      errorStyle: TextStyle(color: theme.error, fontWeight: FontWeight.w600),
                    ),
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: canSubmit ? _submit : null,
                    child: validating
                        ? const SizedBox(
                            height: 22,
                            width: 22,
                            child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
                          )
                        : Text(s.validate),
                  ),
                ],
              ),
            ),
          ),
          if (widget.helpText != null)
            TextButton(onPressed: () => _showHelp(context, widget.helpText!), child: Text(s.helpLink)),
          if ((_loadedDemoCodes ?? widget.demoCodes)?.isNotEmpty ?? false) _demoPanel(s, theme),
        ],
      ),
    );
  }

  void _showHelp(BuildContext context, String text) {
    final scope = GamificationScope.of(context);
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              scope.strings.helpLink,
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: scope.theme.navy),
            ),
            const SizedBox(height: 12),
            Text(text),
            const SizedBox(height: 16),
            OutlinedButton(onPressed: () => Navigator.pop(ctx), child: Text(scope.strings.close)),
          ],
        ),
      ),
    );
  }

  Widget _demoPanel(GamificationStrings s, GamificationTheme theme) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: ExpansionTile(
        tilePadding: EdgeInsets.zero,
        title: Row(
          children: [
            Chip(
              label: Text(s.demoBadge),
              backgroundColor: theme.accent,
              visualDensity: VisualDensity.compact,
              labelStyle: TextStyle(color: theme.navy, fontWeight: FontWeight.w800),
            ),
            const SizedBox(width: 8),
            Flexible(child: Text(s.demoCodes, overflow: TextOverflow.ellipsis)),
          ],
        ),
        children: [
          DemoCodesPanel(
            codes: (_loadedDemoCodes ?? widget.demoCodes)!,
            strings: s,
            theme: theme,
            onPick: (code) => _controller.text = code,
          ),
        ],
      ),
    );
  }
}
