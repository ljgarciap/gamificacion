{{flutter_js}}
{{flutter_build_config}}

// Sin service worker (SPEC-008 RF-5): no se registra ninguno. El flutter_service_worker.js que genera Flutter se publica igual
// para que los navegadores que guardaban uno antiguo lo den de baja.
_flutter.loader.load();
