import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../auth/providers/auth_provider.dart';
import '../../contacts/providers/contacts_provider.dart';
import '../../tasks/providers/tasks_provider.dart';
import '../../appointments/providers/appointments_provider.dart';
import '../../invoices/providers/invoices_provider.dart';
import '../../helpdesk/providers/tickets_provider.dart';

class DashboardScreen extends ConsumerWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(authProvider).valueOrNull;
    final contacts = ref.watch(contactsProvider);
    final tasks = ref.watch(tasksProvider);
    final appointments = ref.watch(appointmentsProvider);
    final invoices = ref.watch(invoicesProvider);
    final tickets = ref.watch(ticketsProvider);

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Dashboard', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 18)),
            if (session != null)
              Text(session.workspace.name,
                  style: const TextStyle(fontSize: 12, color: AppColors.textSecondary, fontWeight: FontWeight.normal)),
          ],
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(contactsProvider);
          ref.invalidate(tasksProvider);
          ref.invalidate(appointmentsProvider);
          ref.invalidate(invoicesProvider);
          ref.invalidate(ticketsProvider);
        },
        child: SingleChildScrollView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (session != null) _buildGreeting(session),
              const SizedBox(height: 20),
              const Text('Overview', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
              const SizedBox(height: 12),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: 2,
                mainAxisSpacing: 12,
                crossAxisSpacing: 12,
                childAspectRatio: 1.45,
                children: [
                  _StatCard(
                    title: 'Contacts',
                    icon: Icons.people_outline,
                    color: const Color(0xFF4F46E5),
                    value: contacts.whenOrNull(data: (p) => '${p.total}'),
                    loading: contacts.isLoading,
                  ),
                  _StatCard(
                    title: 'Open Tasks',
                    icon: Icons.task_alt,
                    color: const Color(0xFF059669),
                    value: tasks.whenOrNull(data: (p) => '${p.data.where((t) => t.isOpen).length}'),
                    loading: tasks.isLoading,
                  ),
                  _StatCard(
                    title: 'Appointments',
                    icon: Icons.event_outlined,
                    color: const Color(0xFFD97706),
                    value: appointments.whenOrNull(data: (p) => '${p.total}'),
                    loading: appointments.isLoading,
                  ),
                  _StatCard(
                    title: 'Invoices',
                    icon: Icons.receipt_long_outlined,
                    color: const Color(0xFF7C3AED),
                    value: invoices.whenOrNull(data: (p) => '${p.total}'),
                    loading: invoices.isLoading,
                  ),
                  _StatCard(
                    title: 'Open Tickets',
                    icon: Icons.support_agent_outlined,
                    color: const Color(0xFFDC2626),
                    value: tickets.whenOrNull(data: (p) => '${p.data.where((t) => t.status == 'open' || t.status == 'in_progress').length}'),
                    loading: tickets.isLoading,
                  ),
                ],
              ),
              const SizedBox(height: 24),
              const Text('Recent Tasks', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
              const SizedBox(height: 12),
              tasks.when(
                loading: () => const LinearProgressIndicator(),
                error: (_, __) => const SizedBox.shrink(),
                data: (p) {
                  final recent = p.data.take(5).toList();
                  if (recent.isEmpty) {
                    return const Text('No tasks yet.', style: TextStyle(color: AppColors.textSecondary));
                  }
                  return Column(
                    children: recent.map((task) => Card(
                      child: ListTile(
                        leading: Icon(
                          task.isDone ? Icons.check_circle_rounded : Icons.radio_button_unchecked,
                          color: task.isDone ? AppColors.success : AppColors.textSecondary,
                        ),
                        title: Text(task.title, style: TextStyle(
                          decoration: task.isDone ? TextDecoration.lineThrough : null,
                          color: task.isDone ? AppColors.textSecondary : AppColors.textPrimary,
                        )),
                        trailing: Text(task.priorityLabel,
                            style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
                      ),
                    )).toList(),
                  );
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildGreeting(AuthSession session) {
    final hour = DateTime.now().hour;
    final greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('$greeting,', style: const TextStyle(fontSize: 14, color: AppColors.textSecondary)),
        Text(
          session.user.name,
          style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.textPrimary),
        ),
      ],
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({
    required this.title,
    required this.icon,
    required this.color,
    this.value,
    this.loading = false,
  });

  final String title;
  final IconData icon;
  final Color color;
  final String? value;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Container(
              padding: const EdgeInsets.all(6),
              decoration: BoxDecoration(color: color.withOpacity(0.12), borderRadius: BorderRadius.circular(8)),
              child: Icon(icon, color: color, size: 18),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                loading
                    ? SizedBox(
                        height: 22,
                        width: 48,
                        child: LinearProgressIndicator(color: color, backgroundColor: color.withOpacity(0.1)),
                      )
                    : Text(
                        value ?? '—',
                        style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: color),
                      ),
                Text(title, style: const TextStyle(fontSize: 11, color: AppColors.textSecondary, fontWeight: FontWeight.w500)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
