class Contact {
  const Contact({
    required this.id,
    required this.name,
    required this.type,
    required this.kind,
    this.companyName,
    this.email,
    this.phone,
    this.mobile,
    this.address,
    this.city,
    this.countryCode,
    this.tags = const [],
    this.notes,
    this.isActive = true,
    this.createdAt,
  });

  final int id;
  final String name;
  final String type;
  final String kind;
  final String? companyName;
  final String? email;
  final String? phone;
  final String? mobile;
  final String? address;
  final String? city;
  final String? countryCode;
  final List<dynamic> tags;
  final String? notes;
  final bool isActive;
  final String? createdAt;

  String get displayName => kind == 'company' && companyName != null ? companyName! : name;

  String get initials {
    final parts = displayName.trim().split(' ');
    if (parts.length >= 2) return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
    return displayName.isNotEmpty ? displayName[0].toUpperCase() : '?';
  }

  String get typeLabel => switch (type) {
        'customer' => 'Customer',
        'supplier' => 'Supplier',
        'lead' => 'Lead',
        _ => 'Other',
      };

  factory Contact.fromJson(Map<String, dynamic> json) => Contact(
        id: json['id'] as int,
        name: (json['name'] as String?) ?? '',
        type: (json['type'] as String?) ?? 'other',
        kind: (json['kind'] as String?) ?? 'person',
        companyName: json['company_name'] as String?,
        email: json['email'] as String?,
        phone: json['phone'] as String?,
        mobile: json['mobile'] as String?,
        address: json['address'] as String?,
        city: json['city'] as String?,
        countryCode: json['country_code'] as String?,
        tags: (json['tags'] as List?) ?? [],
        notes: json['notes'] as String?,
        isActive: (json['is_active'] as bool?) ?? true,
        createdAt: json['created_at'] as String?,
      );
}

class PaginatedContacts {
  const PaginatedContacts({required this.data, required this.total, required this.lastPage, required this.currentPage});

  final List<Contact> data;
  final int total;
  final int lastPage;
  final int currentPage;

  factory PaginatedContacts.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? {};
    return PaginatedContacts(
      data: (json['data'] as List).map((e) => Contact.fromJson(e as Map<String, dynamic>)).toList(),
      total: (meta['total'] as int?) ?? 0,
      lastPage: (meta['last_page'] as int?) ?? 1,
      currentPage: (meta['current_page'] as int?) ?? 1,
    );
  }
}
