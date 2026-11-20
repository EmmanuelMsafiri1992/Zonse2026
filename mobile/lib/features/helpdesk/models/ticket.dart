class Ticket {
  const Ticket({
    required this.id,
    required this.number,
    required this.subject,
    required this.status,
    required this.priority,
    this.body,
    this.channel,
    this.category,
    this.contactId,
    this.requesterName,
    this.requesterEmail,
    this.assigneeId,
    this.resolvedAt,
    this.createdAt,
  });

  final int id;
  final String number;
  final String subject;
  final String status;
  final String priority;
  final String? body;
  final String? channel;
  final String? category;
  final int? contactId;
  final String? requesterName;
  final String? requesterEmail;
  final int? assigneeId;
  final String? resolvedAt;
  final String? createdAt;

  String get statusLabel => switch (status) {
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        _ => status,
      };

  String get priorityLabel => switch (priority) {
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
        _ => priority,
      };

  factory Ticket.fromJson(Map<String, dynamic> json) => Ticket(
        id: json['id'] as int,
        number: (json['number'] as String?) ?? '',
        subject: (json['subject'] as String?) ?? '',
        status: (json['status'] as String?) ?? 'open',
        priority: (json['priority'] as String?) ?? 'normal',
        body: json['body'] as String?,
        channel: json['channel'] as String?,
        category: json['category'] as String?,
        contactId: json['contact_id'] as int?,
        requesterName: json['requester_name'] as String?,
        requesterEmail: json['requester_email'] as String?,
        assigneeId: json['assignee_id'] as int?,
        resolvedAt: json['resolved_at'] as String?,
        createdAt: json['created_at'] as String?,
      );
}

class PaginatedTickets {
  const PaginatedTickets({required this.data, required this.total, required this.lastPage, required this.currentPage});

  final List<Ticket> data;
  final int total;
  final int lastPage;
  final int currentPage;

  factory PaginatedTickets.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? {};
    return PaginatedTickets(
      data: (json['data'] as List).map((e) => Ticket.fromJson(e as Map<String, dynamic>)).toList(),
      total: (meta['total'] as int?) ?? 0,
      lastPage: (meta['last_page'] as int?) ?? 1,
      currentPage: (meta['current_page'] as int?) ?? 1,
    );
  }
}
