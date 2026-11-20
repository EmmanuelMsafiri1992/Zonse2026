import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/ticket.dart';
import '../providers/tickets_provider.dart';
import 'ticket_detail_screen.dart';
import 'ticket_form_screen.dart';

class TicketsScreen extends ConsumerWidget {
  const TicketsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(ticketsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Helpdesk', style: TextStyle(fontWeight: FontWeight.w700)),
        actions: [
          PopupMenuButton<String?>(
            icon: const Icon(Icons.filter_list),
            onSelected: (v) => ref.read(ticketsProvider.notifier).filterByStatus(v),
            itemBuilder: (_) => [
              const PopupMenuItem(value: null, child: Text('All')),
              const PopupMenuItem(value: 'open', child: Text('Open')),
              const PopupMenuItem(value: 'in_progress', child: Text('In Progress')),
              const PopupMenuItem(value: 'resolved', child: Text('Resolved')),
              const PopupMenuItem(value: 'closed', child: Text('Closed')),
            ],
          ),
          IconButton(
            icon: const Icon(Icons.add),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const TicketFormScreen()),
            ),
          ),
        ],
      ),
      body: state.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(
          message: e.toString(),
          onRetry: () => ref.read(ticketsProvider.notifier).load(refresh: true),
        ),
        data: (paginated) {
          if (paginated.data.isEmpty) {
            return const EmptyStateWidget(icon: Icons.support_agent_outlined, message: 'No support tickets.');
          }
          return RefreshIndicator(
            onRefresh: () => ref.read(ticketsProvider.notifier).load(refresh: true),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
              itemCount: paginated.data.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (_, i) => _TicketCard(ticket: paginated.data[i]),
            ),
          );
        },
      ),
    );
  }
}

class _TicketCard extends StatelessWidget {
  const _TicketCard({required this.ticket});

  final Ticket ticket;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => TicketDetailScreen(ticketId: ticket.id)),
        ),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      ticket.subject,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                  const SizedBox(width: 8),
                  StatusBadge(label: ticket.statusLabel, color: StatusBadge.colorForStatus(ticket.status)),
                ],
              ),
              const SizedBox(height: 6),
              Row(
                children: [
                  Text('#${ticket.number}', style: const TextStyle(color: AppColors.textSecondary, fontSize: 12)),
                  if (ticket.requesterName != null) ...[
                    const Text(' · ', style: TextStyle(color: AppColors.textSecondary, fontSize: 12)),
                    Text(ticket.requesterName!, style: const TextStyle(color: AppColors.textSecondary, fontSize: 12)),
                  ],
                  const Spacer(),
                  StatusBadge(label: ticket.priorityLabel, color: _priorityColor(ticket.priority)),
                ],
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
