import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/providers/navigation_provider.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/app_drawer.dart';
import '../../auth/providers/auth_provider.dart';
import '../../contacts/providers/contacts_provider.dart';
import '../../contacts/screens/contact_form_screen.dart';
import '../../tasks/providers/tasks_provider.dart';
import '../../tasks/screens/task_form_screen.dart';
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
        leading: Builder(
          builder: (ctx) => IconButton(
            icon: const Icon(Icons.menu_rounded),
            onPressed: () => Scaffold.of(ctx).openDrawer(),
          ),
        ),
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
      drawer: const AppDrawer(),
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
              _buildQuickActions(context, ref),
              const SizedBox(height: 24),
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
                  _StatCard(
                    title: 'Revenue',
                    icon: Icons.payments_outlined,
                    color: const Color(0xFF0891B2),
                    value: invoices.whenOrNull(
                      data: (p) {
                        final total = p.data
                            .where((i) => i.status == 'paid')
                            .fold(0.0, (sum, i) => sum + i.total);
                        return total > 0 ? total.toStringAsFixed(0) : '0';
                      },
                    ),
                    loading: invoices.isLoading,
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
              const SizedBox(height: 24),
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text('Recent Contacts', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
                  TextButton(
                    onPressed: () => ref.read(currentTabProvider.notifier).state = 1,
                    child: const Text('See all'),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              contacts.when(
                loading: () => const LinearProgressIndicator(),
                error: (_, __) => const SizedBox.shrink(),
                data: (p) {
                  final recent = p.data.take(4).toList();
                  if (recent.isEmpty) {
                    return const Text('No contacts yet.', style: TextStyle(color: AppColors.textSecondary));
                  }
                  return Column(
                    children: recent.map((c) => Card(
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: AppColors.primary.withOpacity(0.12),
                          child: Text(c.initials,
                              style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w700, fontSize: 13)),
                        ),
                        title: Text(c.displayName,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: c.email != null
                            ? Text(c.email!, maxLines: 1, overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 12))
                            : c.phone != null
                                ? Text(c.phone!, style: const TextStyle(fontSize: 12))
                                : null,
                        trailing: Text(c.typeLabel,
                            style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
                      ),
                    )).toList(),
                  );
                },
              ),
              const SizedBox(height: 16),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildQuickActions(BuildContext context, WidgetRef ref) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Quick Actions', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 17)),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: _QuickActionCard(
                icon: Icons.person_add_outlined,
                label: 'New Contact',
                color: const Color(0xFF4F46E5),
                onTap: () async {
                  final created = await Navigator.push<bool>(
                    context,
                    MaterialPageRoute(builder: (_) => const ContactFormScreen()),
                  );
                  if (created == true) ref.invalidate(contactsProvider);
                },
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _QuickActionCard(
                icon: Icons.add_task,
                label: 'New Task',
                color: const Color(0xFF059669),
                onTap: () async {
                  final created = await Navigator.push<bool>(
                    context,
                    MaterialPageRoute(builder: (_) => const TaskFormScreen()),
                  );
                  if (created == true) ref.invalidate(tasksProvider);
                },
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _QuickActionCard(
                icon: Icons.receipt_long_outlined,
                label: 'Invoices',
                color: const Color(0xFF7C3AED),
                onTap: () => ref.read(currentTabProvider.notifier).state = 4,
              ),
            ),
          ],
        ),
      ],
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

class _QuickActionCard extends StatelessWidget {
  const _QuickActionCard({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 8),
          child: Column(
            children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: color.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(icon, color: color, size: 22),
              ),
              const SizedBox(height: 8),
              Text(label,
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      ),
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
