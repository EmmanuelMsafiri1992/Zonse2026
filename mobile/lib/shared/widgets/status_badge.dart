import 'package:flutter/material.dart';

class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.label, required this.color});

  final String label;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Text(
        label,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: color),
      ),
    );
  }

  static Color colorForStatus(String status) => switch (status) {
        'done' || 'active' || 'paid' || 'confirmed' || 'resolved' => const Color(0xFF059669),
        'in_progress' || 'draft' || 'pending' || 'open' => const Color(0xFF4F46E5),
        'cancelled' || 'void' || 'closed' => const Color(0xFF6B7280),
        'overdue' || 'urgent' => const Color(0xFFDC2626),
        'high' => const Color(0xFFD97706),
        _ => const Color(0xFF6B7280),
      };
}
