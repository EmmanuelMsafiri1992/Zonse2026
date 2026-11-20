import 'package:flutter/material.dart';
import '../../../core/theme/app_theme.dart';
import '../models/auth_state.dart';

class WorkspaceSelectScreen extends StatelessWidget {
  const WorkspaceSelectScreen({super.key, required this.workspaces});

  final List<AuthWorkspace> workspaces;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Select Workspace')),
      body: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: workspaces.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, i) {
          final ws = workspaces[i];
          return Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              leading: CircleAvatar(
                backgroundColor: AppColors.primary.withOpacity(0.12),
                child: Text(
                  ws.name.isNotEmpty ? ws.name[0].toUpperCase() : '?',
                  style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w700),
                ),
              ),
              title: Text(ws.name, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(ws.currencyCode, style: const TextStyle(color: AppColors.textSecondary)),
              trailing: const Icon(Icons.arrow_forward_ios, size: 16, color: AppColors.textSecondary),
              onTap: () => Navigator.pop(context, ws),
            ),
          );
        },
      ),
    );
  }
}
