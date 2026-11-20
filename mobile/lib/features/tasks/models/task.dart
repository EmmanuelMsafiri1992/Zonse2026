class Task {
  const Task({
    required this.id,
    required this.title,
    required this.status,
    required this.priority,
    this.description,
    this.dueDate,
    this.completedAt,
    this.assigneeId,
    this.contactId,
    this.createdAt,
  });

  final int id;
  final String title;
  final String status;
  final String priority;
  final String? description;
  final String? dueDate;
  final String? completedAt;
  final int? assigneeId;
  final int? contactId;
  final String? createdAt;

  String get statusLabel => switch (status) {
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'done' => 'Done',
        _ => status,
      };

  String get priorityLabel => switch (priority) {
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
        _ => priority,
      };

  bool get isOpen => status == 'todo' || status == 'in_progress';
  bool get isDone => status == 'done';

  factory Task.fromJson(Map<String, dynamic> json) => Task(
        id: json['id'] as int,
        title: (json['title'] as String?) ?? '',
        status: (json['status'] as String?) ?? 'todo',
        priority: (json['priority'] as String?) ?? 'normal',
        description: json['description'] as String?,
        dueDate: json['due_date'] as String?,
        completedAt: json['completed_at'] as String?,
        assigneeId: json['assignee_id'] as int?,
        contactId: json['contact_id'] as int?,
        createdAt: json['created_at'] as String?,
      );
}

class PaginatedTasks {
  const PaginatedTasks({required this.data, required this.total, required this.lastPage, required this.currentPage});

  final List<Task> data;
  final int total;
  final int lastPage;
  final int currentPage;

  factory PaginatedTasks.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'] as Map<String, dynamic>? ?? {};
    return PaginatedTasks(
      data: (json['data'] as List).map((e) => Task.fromJson(e as Map<String, dynamic>)).toList(),
      total: (meta['total'] as int?) ?? 0,
      lastPage: (meta['last_page'] as int?) ?? 1,
      currentPage: (meta['current_page'] as int?) ?? 1,
    );
  }
}
