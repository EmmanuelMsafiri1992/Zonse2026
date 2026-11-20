import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/task.dart';
import '../providers/tasks_provider.dart';
import 'task_form_screen.dart';

class TaskDetailScreen extends ConsumerWidget {
  const TaskDetailScreen({super.key, required this.taskId});

  final int taskId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final taskAsync = ref.watch(taskDetailProvider(taskId));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Task'),
        actions: [
          taskAsync.whenOrNull(
                data: (t) => IconButton(
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => TaskFormScreen(task: t)),
                  ),
                ),
              ) ??
              const SizedBox.shrink(),
        ],
      ),
      body: taskAsync.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(message: e.toString()),
        data: (task) => _TaskDetail(task: task),
      ),
    );
  }
}

class _TaskDetail extends ConsumerWidget {
  const _TaskDetail({required this.task});

  final Task task;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          task.title,
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    children: [
                      StatusBadge(label: task.statusLabel, color: StatusBadge.colorForStatus(task.status)),
                      StatusBadge(label: task.priorityLabel, color: _priorityColor(task.priority)),
                    ],
                  ),
                  if (task.dueDate != null) ...[
                    const SizedBox(height: 12),
                    _infoRow(Icons.calendar_today_outlined, 'Due date', task.dueDate!),
                  ],
                  if (task.description != null && task.description!.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 8),
                    Text(task.description!, style: const TextStyle(height: 1.6, color: AppColors.textSecondary)),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              for (final status in ['todo', 'in_progress', 'done'])
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    child: OutlinedButton(
                      style: OutlinedButton.styleFrom(
                        backgroundColor: task.status == status ? AppColors.primary.withOpacity(0.1) : null,
                        side: BorderSide(
                          color: task.status == status ? AppColors.primary : AppColors.border,
                        ),
                      ),
                      onPressed: task.status == status
                          ? null
                          : () => ref.read(tasksProvider.notifier).updateStatus(task.id, status),
                      child: Text(
                        Task(id: 0, title: '', status: status, priority: 'normal').statusLabel,
                        style: TextStyle(
                          fontSize: 11,
                          color: task.status == status ? AppColors.primary : AppColors.textSecondary,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _infoRow(IconData icon, String label, String value) => Row(
        children: [
          Icon(icon, size: 16, color: AppColors.textSecondary),
          const SizedBox(width: 8),
          Text('$label: ', style: const TextStyle(color: AppColors.textSecondary, fontSize: 13)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13)),
        ],
      );

  Color _priorityColor(String priority) => switch (priority) {
        'urgent' => AppColors.error,
        'high' => AppColors.warning,
        'normal' => AppColors.primary,
        _ => AppColors.textSecondary,
      };
}
