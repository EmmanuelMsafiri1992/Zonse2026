class AuthUser {
  const AuthUser({required this.id, required this.name, required this.email, this.role});

  final int id;
  final String name;
  final String email;
  final String? role;

  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
        id: json['id'] as int,
        name: json['name'] as String,
        email: json['email'] as String,
        role: json['role'] as String?,
      );

  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'email': email, 'role': role};

  String get initials {
    final parts = name.trim().split(' ');
    if (parts.length >= 2) {
      return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
    }
    return name.isNotEmpty ? name[0].toUpperCase() : '?';
  }
}

class AuthWorkspace {
  const AuthWorkspace({
    required this.id,
    required this.name,
    required this.currencyCode,
    required this.timezone,
  });

  final int id;
  final String name;
  final String currencyCode;
  final String timezone;

  factory AuthWorkspace.fromJson(Map<String, dynamic> json) => AuthWorkspace(
        id: json['id'] as int,
        name: json['name'] as String,
        currencyCode: (json['currency_code'] as String?) ?? 'USD',
        timezone: (json['timezone'] as String?) ?? 'UTC',
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'name': name, 'currency_code': currencyCode, 'timezone': timezone};
}
