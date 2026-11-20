import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/ticket.dart';
import '../providers/tickets_provider.dart';

class TicketDetailScreen extends ConsumerWidget {
  const TicketDetailScreen({super.key, required this.ticketId});

  final int ticketId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ticketAsync = ref.watch(ticketDetailProvider(ticketId));

    return Scaffold(
      appBar: AppBar(title: const Text('Ticket')),
      body: ticketAsync.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(message: e.toString()),
        data: (ticket) => _TicketDetail(ticket: ticket),
      ),
    );
  }
}

class _TicketDetail extends ConsumerWidget {
  const _TicketDetail({required this.ticket});

  final Ticket ticket;

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
                        child: Text(ticket.subject, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Wrap(
                    spacing: 8,
                    children: [
                      StatusBadge(label: ticket.statusLabel, color: StatusBadge.colorForStatus(ticket.status)),
                      StatusBadge(label: ticket.priorityLabel, color: _priorityColor(ticket.priority)),
                      if (ticket.channel != null)
                        StatusBadge(label: ticket.channel!, color: AppColors.primary),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _infoRow('Ticket #', ticket.number),
                  if (ticket.requesterName != null) _infoRow('From', ticket.requesterName!),
                  if (ticket.requesterEmail != null) _infoRow('Email', ticket.requesterEmail!),
                  if (ticket.category != null) _infoRow('Category', ticket.category!),
                  if (ticket.createdAt != null) _infoRow('Opened', _formatDate(ticket.createdAt!)),
                ],
              ),
            ),
          ),
          if (ticket.body != null && ticket.body!.isNotEmpty) ...[
            const SizedBox(height: 12),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Description', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
                    const SizedBox(height: 10),
                    Text(ticket.body!, style: const TextStyle(height: 1.6, color: AppColors.textSecondary)),
                  ],
                ),
              ),
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              for (final status in ['open', 'in_progress', 'resolved', 'closed'])
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 3),
                    child: OutlinedButton(
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        backgroundColor: ticket.status == status ? AppColors.primary.withOpacity(0.1) : null,
                        side: BorderSide(color: ticket.status == status ? AppColors.primary : AppColors.border),
                      ),
                      onPressed: ticket.status == status
                          ? null
                          : () => ref.read(ticketsProvider.notifier).update(ticket.id, {'status': status}),
                      child: Text(
                        Ticket(id: 0, number: '', subject: '', status: status, priority: 'normal').statusLabel,
                        style: TextStyle(fontSize: 10, color: ticket.status == status ? AppColors.primary : AppColors.textSecondary),
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

  Widget _infoRow(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            SizedBox(
              width: 90,
              child: Text(label, style: const TextStyle(color: AppColors.textSecondary, fontSize: 13)),
            ),
            Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13))),
          ],
        ),
      );

  String _formatDate(String iso) {
    try {
      final dt = DateTime.parse(iso).toLocal();
      return '${dt.day}/${dt.month}/${dt.year}';
    } catch (_) {
      return iso;
    }
  }

  Color _priorityColor(String priority) => switch (priority) {
        'urgent' => AppColors.error,
        'high' => AppColors.warning,
        'normal' => AppColors.primary,
        _ => AppColors.textSecondary,
      };
}
