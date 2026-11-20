class Appointment {
  const Appointment({
    required this.id,
    required this.title,
    required this.status,
    this.startsAt,
    this.endsAt,
    this.contactId,
    this.staffId,
    this.price,
    this.notes,
    this.createdAt,
  });

  final int id;
  final String title;
  final String status;
  final String? startsAt;
  final String? endsAt;
  final int? contactId;
  final int? staffId;
  final double? price;
  final String? notes;
  final String? createdAt;

  String get statusLabel => switch (status) {
        'confirmed' => 'Confirmed',
        'pending' => 'Pending',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
        'no_show' => 'No show',
        _ => status,
      };

  factory Appointment.fromJson(Map<String, dynamic> json) => Appointment(
        id: json['id'] as int,
        title: (json['title'] as String?) ?? 'Appointment',
        status: (json['status'] as String?) ?? 'pending',
        startsAt: json['starts_at'] as String?,
        endsAt: json['ends_at'] as String?,
        contactId: json['contact_id'] as int?,
        staffId: json['staff_id'] as int?,
        price: json['price'] != null ? (json['price'] as num).toDouble() : null,
        notes: json['notes'] as String?,
        createdAt: json['created_at'] as String?,
      );
}

class PaginatedAppointments {
  const PaginatedAppointments({required this.data, required this.total, required this.lastPage, required this.currentPage});

  final List<Appointment> data;
  final int total;
  final int lastPage;
  final int currentPage;

  factory PaginatedAppointments.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? {};
    return PaginatedAppointments(
      data: (json['data'] as List).map((e) => Appointment.fromJson(e as Map<String, dynamic>)).toList(),
      total: (meta['total'] as int?) ?? 0,
      lastPage: (meta['last_page'] as int?) ?? 1,
      currentPage: (meta['current_page'] as int?) ?? 1,
    );
  }
}
