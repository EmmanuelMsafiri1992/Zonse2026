import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/task.dart';
import '../providers/tasks_provider.dart';
import 'task_detail_screen.dart';
import 'task_form_screen.dart';

class TasksScreen extends ConsumerStatefulWidget {
  const TasksScreen({super.key});

  @override
  ConsumerState<TasksScreen> createState() => _TasksScreenState();
}

class _TasksScreenState extends ConsumerState<TasksScreen> with SingleTickerProviderStateMixin {
  late TabController _tabCtrl;

  static const _tabs = [
    ('', 'All'),
    ('todo', 'To do'),
    ('in_progress', 'In Progress'),
    ('done', 'Done'),
  ];

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: _tabs.length, vsync: this);
    _tabCtrl.addListener(() {
      if (!_tabCtrl.indexIsChanging) {
        ref.read(tasksProvider.notifier).filterByStatus(_tabs[_tabCtrl.index].$1);
      }
    });
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(tasksProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Tasks', style: TextStyle(fontWeight: FontWeight.w700)),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            onPressed: () async {
              final created = await Navigator.push<bool>(
                context,
                MaterialPageRoute(builder: (_) => const TaskFormScreen()),
              );
              if (created == true) ref.read(tasksProvider.notifier).load(refresh: true);
            },
          ),
        ],
        bottom: TabBar(
          controller: _tabCtrl,
          isScrollable: true,
          tabAlignment: TabAlignment.start,
          tabs: _tabs.map((t) => Tab(text: t.$2)).toList(),
        ),
      ),
      body: state.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(
          message: e.toString(),
          onRetry: () => ref.read(tasksProvider.notifier).load(refresh: true),
        ),
        data: (paginated) {
          if (paginated.data.isEmpty) {
            return const EmptyStateWidget(icon: Icons.task_alt, message: 'No tasks here.');
          }
          return RefreshIndicator(
            onRefresh: () => ref.read(tasksProvider.notifier).load(refresh: true),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
              itemCount: paginated.data.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (_, i) => _TaskCard(task: paginated.data[i]),
            ),
          );
        },
      ),
    );
  }
}

class _TaskCard extends ConsumerWidget {
  const _TaskCard({required this.task});

  final Task task;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => TaskDetailScreen(taskId: task.id)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              InkWell(
                onTap: () {
                  final next = task.status == 'done' ? 'todo' : 'done';
                  ref.read(tasksProvider.notifier).updateStatus(task.id, next);
                },
                child: Padding(
                  padding: const EdgeInsets.only(top: 1, right: 10),
                  child: Icon(
                    task.isDone ? Icons.check_circle_rounded : Icons.radio_button_unchecked,
                    color: task.isDone ? AppColors.success : AppColors.textSecondary,
                    size: 22,
                  ),
                ),
              ),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      task.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        fontSize: 15,
                        decoration: task.isDone ? TextDecoration.lineThrough : null,
                        color: task.isDone ? AppColors.textSecondary : AppColors.textPrimary,
                      ),
                    ),
                    if (task.dueDate != null) ...[
                      const SizedBox(height: 4),
                      Row(children: [
                        const Icon(Icons.calendar_today_outlined, size: 12, color: AppColors.textSecondary),
                        const SizedBox(width: 4),
                        Text(task.dueDate!, style: const TextStyle(fontSize: 12, color: AppColors.textSecondary)),
                      ]),
                    ],
                  ],
                ),
              ),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 110),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    StatusBadge(label: task.priorityLabel, color: _priorityColor(task.priority)),
                    const SizedBox(height: 4),
                    StatusBadge(label: task.statusLabel, color: StatusBadge.colorForStatus(task.status)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Color _priorityColor(String priority) => switch (priority) {
        'urgent' => AppColors.error,
        'high' => AppColors.warning,
        'normal' => AppColors.primary,
        _ => AppColors.textSecondary,
      };
}
