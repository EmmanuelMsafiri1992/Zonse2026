import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/loading_widget.dart';
import '../../../shared/widgets/status_badge.dart';
import '../models/contact.dart';
import '../providers/contacts_provider.dart';
import 'contact_form_screen.dart';

class ContactDetailScreen extends ConsumerWidget {
  const ContactDetailScreen({super.key, required this.contactId});

  final int contactId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final contactAsync = ref.watch(contactDetailProvider(contactId));

    return Scaffold(
      appBar: AppBar(
        title: const Text('Contact'),
        actions: [
          contactAsync.whenOrNull(
                data: (c) => IconButton(
                  icon: const Icon(Icons.edit_outlined),
                  onPressed: () => Navigator.push(
                    context,
                    MaterialPageRoute(builder: (_) => ContactFormScreen(contact: c)),
                  ),
                ),
              ) ??
              const SizedBox.shrink(),
        ],
      ),
      body: contactAsync.when(
        loading: () => const LoadingWidget(),
        error: (e, _) => AppErrorWidget(message: e.toString()),
        data: (contact) => _ContactDetail(contact: contact),
      ),
    );
  }
}

class _ContactDetail extends StatelessWidget {
  const _ContactDetail({required this.contact});

  final Contact contact;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _buildHeader(),
          const SizedBox(height: 16),
          _buildInfoCard(),
          if (contact.notes != null && contact.notes!.isNotEmpty) ...[
            const SizedBox(height: 12),
            _buildNotesCard(),
          ],
        ],
      ),
    );
  }

  Widget _buildHeader() => Card(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Row(
            children: [
              CircleAvatar(
                radius: 32,
                backgroundColor: AppColors.primary.withOpacity(0.12),
                child: Text(
                  contact.initials,
                  style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.primary),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(contact.displayName,
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                    if (contact.kind == 'company' && contact.companyName != null)
                      Text(contact.name, style: const TextStyle(color: AppColors.textSecondary)),
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        StatusBadge(label: contact.typeLabel, color: StatusBadge.colorForStatus(contact.type)),
                        const SizedBox(width: 6),
                        if (!contact.isActive)
                          const StatusBadge(label: 'Inactive', color: Color(0xFF6B7280)),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );

  Widget _buildInfoCard() => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Details', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
              const SizedBox(height: 12),
              if (contact.email != null) _infoRow(Icons.email_outlined, 'Email', contact.email!),
              if (contact.phone != null) _infoRow(Icons.phone_outlined, 'Phone', contact.phone!),
              if (contact.mobile != null) _infoRow(Icons.smartphone_outlined, 'Mobile', contact.mobile!),
              if (contact.address != null) _infoRow(Icons.location_on_outlined, 'Address', contact.address!),
              if (contact.city != null) _infoRow(Icons.location_city_outlined, 'City', contact.city!),
              if (contact.countryCode != null)
                _infoRow(Icons.flag_outlined, 'Country', contact.countryCode!),
              if (contact.tags.isNotEmpty) _tagsRow(),
            ],
          ),
        ),
      );

  Widget _infoRow(IconData icon, String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: AppColors.textSecondary),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(label, style: const TextStyle(fontSize: 11, color: AppColors.textSecondary)),
                  Text(value, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500)),
                ],
              ),
            ),
          ],
        ),
      );

  Widget _tagsRow() => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.label_outline, size: 18, color: AppColors.textSecondary),
            const SizedBox(width: 10),
            Expanded(
              child: Wrap(
                spacing: 6,
                runSpacing: 4,
                children: contact.tags
                    .map((t) => StatusBadge(label: t.toString(), color: AppColors.primary))
                    .toList(),
              ),
            ),
          ],
        ),
      );

  Widget _buildNotesCard() => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Notes', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 15)),
              const SizedBox(height: 8),
              Text(contact.notes!, style: const TextStyle(color: AppColors.textSecondary, height: 1.5)),
            ],
          ),
        ),
      );
}
