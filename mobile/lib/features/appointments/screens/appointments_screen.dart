import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/appointment.dart';
import '../providers/appointments_provider.dart';

class AppointmentsScreen extends ConsumerWidget {
  const AppointmentsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(appointmentsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Appointments', style: TextStyle(fontWeight: FontWeight.w700)),
        actions: [
          PopupMenuButton<String?>(
            icon: const Icon(Icons.filter_list),
            onSelected: (v) => ref.read(appointmentsProvider.notifier).filterByStatus(v),
            itemBuilder: (_) => [
              const PopupMenuItem(value: null, child: Text('All')),
              const PopupMenuItem(value: 'pending', child: Text('Pending')),
              const PopupMenuItem(value: 'confirmed', child: Text('Confirmed')),
              const PopupMenuItem(value: 'completed', child: Text('Completed')),
              const PopupMenuItem(value: 'cancelled', child: Text('Cancelled')),
            ],
          ),
        ],
      ),
      body: state.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(
          message: e.toString(),
          onRetry: () => ref.read(appointmentsProvider.notifier).load(refresh: true),
        ),
        data: (paginated) {
          if (paginated.data.isEmpty) {
            return const EmptyStateWidget(icon: Icons.event_outlined, message: 'No appointments found.');
          }
          return RefreshIndicator(
            onRefresh: () => ref.read(appointmentsProvider.notifier).load(refresh: true),
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 100),
              itemCount: paginated.data.length,
              separatorBuilder: (_, __) => const SizedBox(height: 8),
              itemBuilder: (_, i) => _AppointmentCard(appointment: paginated.data[i]),
            ),
          );
        },
      ),
    );
  }
}

class _AppointmentCard extends StatelessWidget {
  const _AppointmentCard({required this.appointment});

  final Appointment appointment;

  @override
  Widget build(BuildContext context) {
    final starts = appointment.startsAt != null ? DateTime.tryParse(appointment.startsAt!) : null;
    final ends = appointment.endsAt != null ? DateTime.tryParse(appointment.endsAt!) : null;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (starts != null)
              Container(
                width: 48,
                padding: const EdgeInsets.symmetric(vertical: 6),
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Column(
                  children: [
                    Text(
                      _month(starts.month),
                      style: const TextStyle(fontSize: 10, color: AppColors.primary, fontWeight: FontWeight.w600),
                    ),
                    Text(
                      '${starts.day}',
                      style: const TextStyle(fontSize: 20, color: AppColors.primary, fontWeight: FontWeight.w800, height: 1.1),
                    ),
                  ],
                ),
              ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(appointment.title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
                  if (starts != null) ...[
                    const SizedBox(height: 4),
                    Row(children: [
                      const Icon(Icons.access_time, size: 13, color: AppColors.textSecondary),
                      const SizedBox(width: 4),
                      Text(
                        '${_time(starts)}${ends != null ? ' – ${_time(ends)}' : ''}',
                        style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
                      ),
                    ]),
                  ],
                  if (appointment.price != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      appointment.price!.toStringAsFixed(2),
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: AppColors.textPrimary),
                    ),
                  ],
                ],
              ),
            ),
            StatusBadge(label: appointment.statusLabel, color: StatusBadge.colorForStatus(appointment.status)),
          ],
        ),
      ),
    );
  }

  String _month(int m) => const ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1];

  String _time(DateTime dt) {
    final h = dt.hour.toString().padLeft(2, '0');
    final m = dt.minute.toString().padLeft(2, '0');
    return '$h:$m';
  }
}
