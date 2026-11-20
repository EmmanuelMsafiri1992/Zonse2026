import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../providers/tickets_provider.dart';

class TicketFormScreen extends ConsumerStatefulWidget {
  const TicketFormScreen({super.key});

  @override
  ConsumerState<TicketFormScreen> createState() => _TicketFormScreenState();
}

class _TicketFormScreenState extends ConsumerState<TicketFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _subjectCtrl = TextEditingController();
  final _bodyCtrl = TextEditingController();
  final _nameCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  String _priority = 'normal';
  bool _saving = false;

  @override
  void dispose() {
    _subjectCtrl.dispose();
    _bodyCtrl.dispose();
    _nameCtrl.dispose();
    _emailCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    try {
      await ref.read(ticketsProvider.notifier).create({
        'subject': _subjectCtrl.text.trim(),
        'priority': _priority,
        if (_bodyCtrl.text.trim().isNotEmpty) 'body': _bodyCtrl.text.trim(),
        if (_nameCtrl.text.trim().isNotEmpty) 'requester_name': _nameCtrl.text.trim(),
        if (_emailCtrl.text.trim().isNotEmpty) 'requester_email': _emailCtrl.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString()), backgroundColor: AppColors.error),
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('New Ticket'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: FilledButton(
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : const Text('Submit'),
            ),
          ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              TextFormField(
                controller: _subjectCtrl,
                decoration: const InputDecoration(labelText: 'Subject *'),
                validator: (v) => (v == null || v.trim().isEmpty) ? 'Subject is required' : null,
              ),
              const SizedBox(height: 16),
              _sectionLabel('Priority'),
              Wrap(
                spacing: 8,
                children: [
                  for (final e in {'low': 'Low', 'normal': 'Normal', 'high': 'High', 'urgent': 'Urgent'}.entries)
                    ChoiceChip(
                      label: Text(e.value),
                      selected: _priority == e.key,
                      onSelected: (_) => setState(() => _priority = e.key),
                    ),
                ],
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(labelText: 'Requester name'),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _emailCtrl,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Requester email'),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _bodyCtrl,
                maxLines: 5,
                decoration: const InputDecoration(labelText: 'Description', alignLabelWithHint: true),
              ),
              const SizedBox(height: 40),
            ],
          ),
        ),
      ),
    );
  }

  Widget _sectionLabel(String label) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Text(label, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: AppColors.textSecondary)),
      );
}
