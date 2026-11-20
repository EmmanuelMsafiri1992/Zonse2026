import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../core/providers/navigation_provider.dart';
import '../../core/theme/app_theme.dart';
import '../../features/auth/providers/auth_provider.dart';

class AppDrawer extends ConsumerWidget {
  const AppDrawer({super.key});

  static const _sections = [
    _NavItem(0, Icons.dashboard_outlined, Icons.dashboard_rounded, 'Dashboard', 'Overview & stats'),
    _NavItem(1, Icons.people_outline, Icons.people_rounded, 'Contacts', 'Customers & leads'),
    _NavItem(2, Icons.task_alt, Icons.task_alt, 'Tasks', 'To-dos & follow-ups'),
    _NavItem(3, Icons.event_outlined, Icons.event_rounded, 'Calendar', 'Appointments'),
    _NavItem(4, Icons.receipt_long_outlined, Icons.receipt_long_rounded, 'Invoices', 'Billing & payments'),
    _NavItem(5, Icons.support_agent_outlined, Icons.support_agent_rounded, 'Helpdesk', 'Support tickets'),
    _NavItem(6, Icons.person_outline, Icons.person_rounded, 'Profile', 'Account & workspace'),
  ];

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(authProvider).valueOrNull;
    final currentTab = ref.watch(currentTabProvider);

    return Drawer(
      child: Column(
        children: [
          _buildHeader(session),
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(vertical: 8),
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
                  child: Text('NAVIGATE',
                      style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: AppColors.textSecondary,
                          letterSpacing: 0.8)),
                ),
                ..._sections.map((item) => _NavTile(
                      item: item,
                      selected: currentTab == item.index,
                      onTap: () {
                        ref.read(currentTabProvider.notifier).state = item.index;
                        Navigator.of(context).pop();
                      },
                    )),
                const Divider(height: 24),
                ListTile(
                  leading: Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: AppColors.error.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Icon(Icons.logout, color: AppColors.error, size: 18),
                  ),
                  title: const Text('Sign out',
                      style: TextStyle(color: AppColors.error, fontWeight: FontWeight.w600)),
                  onTap: () async {
                    Navigator.of(context).pop();
                    final confirmed = await showDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: const Text('Sign out?'),
                        content: const Text('You will need to sign in again to access Zonseob.'),
                        actions: [
                          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                          TextButton(
                            onPressed: () => Navigator.pop(ctx, true),
                            child: const Text('Sign out', style: TextStyle(color: AppColors.error)),
                          ),
                        ],
                      ),
                    );
                    if (confirmed == true) {
                      ref.read(authProvider.notifier).logout();
                    }
                  },
                ),
              ],
            ),
          ),
          _buildFooter(session),
        ],
      ),
    );
  }

  Widget _buildHeader(AuthSession? session) {
    return Container(
      width: double.infinity,
      decoration: const BoxDecoration(color: AppColors.primary),
      padding: const EdgeInsets.fromLTRB(20, 52, 20, 20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          CircleAvatar(
            radius: 30,
            backgroundColor: Colors.white.withOpacity(0.2),
            child: Text(
              session?.user.initials ?? '?',
              style: const TextStyle(
                  color: Colors.white, fontWeight: FontWeight.w800, fontSize: 18),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            session?.user.name ?? '',
            style: const TextStyle(
                color: Colors.white, fontWeight: FontWeight.w700, fontSize: 16),
          ),
          Text(
            session?.user.email ?? '',
            style: TextStyle(color: Colors.white.withOpacity(0.75), fontSize: 12),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white.withOpacity(0.18),
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(color: Colors.white.withOpacity(0.3)),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.business_outlined, size: 12, color: Colors.white),
                    const SizedBox(width: 4),
                    Text(
                      session?.workspace.name ?? '',
                      style: const TextStyle(
                          color: Colors.white, fontSize: 12, fontWeight: FontWeight.w500),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildFooter(AuthSession? session) {
    return Container(
      padding: const EdgeInsets.all(16),
      child: Row(
        children: [
          const Icon(Icons.attach_money, size: 14, color: AppColors.textSecondary),
          const SizedBox(width: 4),
          Text(
            session?.workspace.currencyCode ?? 'USD',
            style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
          ),
          const SizedBox(width: 12),
          const Icon(Icons.access_time_outlined, size: 14, color: AppColors.textSecondary),
          const SizedBox(width: 4),
          Flexible(
            child: Text(
              session?.workspace.timezone ?? '',
              style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
    );
  }
}

class _NavTile extends StatelessWidget {
  const _NavTile({required this.item, required this.selected, required this.onTap});

  final _NavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
      child: ListTile(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        selected: selected,
        selectedTileColor: AppColors.primary.withOpacity(0.1),
        leading: Container(
          padding: const EdgeInsets.all(6),
          decoration: BoxDecoration(
            color: selected
                ? AppColors.primary.withOpacity(0.12)
                : AppColors.textSecondary.withOpacity(0.08),
            borderRadius: BorderRadius.circular(8),
          ),
          child: Icon(
            selected ? item.activeIcon : item.icon,
            color: selected ? AppColors.primary : AppColors.textSecondary,
            size: 18,
          ),
        ),
        title: Text(
          item.label,
          style: TextStyle(
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected ? AppColors.primary : AppColors.textPrimary,
            fontSize: 14,
          ),
        ),
        subtitle: Text(
          item.subtitle,
          style: const TextStyle(fontSize: 11, color: AppColors.textSecondary),
        ),
        onTap: onTap,
      ),
    );
  }
}

class _NavItem {
  const _NavItem(this.index, this.icon, this.activeIcon, this.label, this.subtitle);

  final int index;
  final IconData icon;
  final IconData activeIcon;
  final String label;
  final String subtitle;
}
