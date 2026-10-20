/// Tipos de premio. Un tipo desconocido que llegue del servidor se lee como [unknown]
/// (se muestra solo su etiqueta) para que el backend pueda agregar tipos sin romper clientes viejos.
enum PrizeType {
  money,
  coupon,
  points,
  extraSpin,
  none,
  unknown;

  static PrizeType parse(String? raw) {
    for (final t in PrizeType.values) {
      if (t.name == raw) return t;
    }
    return PrizeType.unknown;
  }
}

class Prize {
  const Prize({required this.id, required this.type, required this.label, this.amount, this.couponCode, this.iconKey});

  factory Prize.fromJson(Map<String, dynamic> json) => Prize(
    id: json['id'] as String,
    type: PrizeType.parse(json['type'] as String?),
    label: json['label'] as String,
    amount: (json['amount'] as num?)?.toInt(),
    couponCode: json['couponCode'] as String?,
    iconKey: json['iconKey'] as String?,
  );

  final String id;
  final PrizeType type;

  /// Texto corto para el segmento y la pantalla de resultado. Lo define la campaña.
  final String label;

  /// COP para [PrizeType.money]; cantidad de puntos para [PrizeType.points].
  final int? amount;
  final String? couponCode;
  final String? iconKey;

  bool get isWin => type != PrizeType.none;

  Map<String, dynamic> toJson() => {
    'id': id,
    'type': type.name,
    'label': label,
    if (amount != null) 'amount': amount,
    if (couponCode != null) 'couponCode': couponCode,
    if (iconKey != null) 'iconKey': iconKey,
  };
}
