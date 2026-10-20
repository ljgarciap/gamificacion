/// Formatea un monto en pesos colombianos: `50000` -> `$ 50.000`. Sin decimales (moneda fija COP).
String formatCop(int amount) {
  final digits = amount.abs().toString();
  final buf = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) buf.write('.');
    buf.write(digits[i]);
  }
  return '${amount < 0 ? '-' : ''}\$ $buf';
}

/// Saldo disponible del usuario. La moneda es siempre COP.
class Balance {
  const Balance(this.amount);
  factory Balance.fromJson(Map<String, dynamic> json) => Balance((json['amount'] as num).toInt());

  final int amount;

  Map<String, dynamic> toJson() => {'amount': amount, 'currency': 'COP'};

  @override
  bool operator ==(Object other) => other is Balance && other.amount == amount;
  @override
  int get hashCode => amount.hashCode;
}
