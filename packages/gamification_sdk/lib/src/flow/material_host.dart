import 'package:cupertino_ui/cupertino_ui.dart' show CupertinoLocalizations, DefaultCupertinoLocalizations;
import 'package:material_ui/material_ui.dart';

/// Aporta los textos de Material y Cupertino cuando la app anfitriona no usa `material_ui` (sigue con el Material antiguo del SDK de
/// Flutter, o no usa Material): sin ellos los campos de texto, los botones con ayuda y las hojas fallan con «No MaterialLocalizations
/// found» (en iOS, el menú de selección pide además los de Cupertino). Los que el anfitrión ya tenga no se tocan.
///
/// Lo que se abre en una capa del navegador del anfitrión (hojas, menú de selección de texto) queda fuera de este árbol: hay que
/// envolverlo también.
class MaterialHost extends StatelessWidget {
  const MaterialHost({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    final missing = <LocalizationsDelegate<dynamic>>[
      if (Localizations.of<MaterialLocalizations>(context, MaterialLocalizations) == null)
        const _EnglishTexts<MaterialLocalizations>(DefaultMaterialLocalizations.load),
      if (Localizations.of<CupertinoLocalizations>(context, CupertinoLocalizations) == null)
        const _EnglishTexts<CupertinoLocalizations>(DefaultCupertinoLocalizations.load),
    ];
    if (missing.isEmpty) return child;
    return Localizations.override(context: context, delegates: missing, child: child);
  }
}

/// Los textos incluidos en `material_ui` y `cupertino_ui` están en inglés; sirven para cualquier idioma del anfitrión (los del SDK
/// vienen aparte).
class _EnglishTexts<T> extends LocalizationsDelegate<T> {
  const _EnglishTexts(this._load);

  final Future<T> Function(Locale) _load;

  @override
  bool isSupported(Locale locale) => true;

  @override
  Future<T> load(Locale locale) => _load(const Locale('en'));

  @override
  bool shouldReload(_EnglishTexts<T> old) => false;
}

/// Menú de selección de texto de los campos del SDK: el mismo que trae `TextField` por defecto, con los textos garantizados (se dibuja
/// en la capa del navegador del anfitrión).
Widget sdkTextMenu(BuildContext context, EditableTextState state) {
  if (SystemContextMenu.isSupportedByField(state)) return SystemContextMenu.editableText(editableTextState: state);
  return MaterialHost(child: AdaptiveTextSelectionToolbar.editableText(editableTextState: state));
}

/// [showModalBottomSheet] que funciona también con anfitriones sin los textos de Material: la hoja se construye con ellos.
/// Llamar con un [context] que esté debajo de un [MaterialHost].
Future<void> showSdkBottomSheet(BuildContext context, {required WidgetBuilder builder}) {
  final navigator = Navigator.of(context);
  final texts = MaterialLocalizations.of(context);
  return navigator.push(
    _SdkSheetRoute(
      builder: builder,
      capturedThemes: InheritedTheme.capture(from: context, to: navigator.context),
      barrierLabel: texts.scrimLabel,
      barrierOnTapHint: texts.scrimOnTapHint(texts.bottomSheetLabel),
      modalBarrierColor: Theme.of(context).bottomSheetTheme.modalBarrierColor,
    ),
  );
}

class _SdkSheetRoute extends ModalBottomSheetRoute<void> {
  _SdkSheetRoute({
    required super.builder,
    required super.capturedThemes,
    required super.barrierLabel,
    required super.barrierOnTapHint,
    required super.modalBarrierColor,
  }) : super(isScrollControlled: false, showDragHandle: true);

  @override
  Widget buildPage(BuildContext context, Animation<double> animation, Animation<double> secondaryAnimation) =>
      MaterialHost(child: Builder(builder: (context) => super.buildPage(context, animation, secondaryAnimation)));
}
