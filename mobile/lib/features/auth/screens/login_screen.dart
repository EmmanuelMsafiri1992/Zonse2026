import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../core/theme/app_theme.dart';
import '../models/auth_state.dart';
import '../providers/auth_provider.dart';
import 'workspace_select_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  bool _obscurePassword = true;
  bool _loading = false;
  String? _error;

  @override
  void dispose() {
    _emailCtrl.dispose();
    _passwordCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final workspaces = await ref.read(authProvider.notifier).fetchWorkspaces(
            _emailCtrl.text.trim(),
            _passwordCtrl.text,
          );

      if (!mounted) return;

      if (workspaces.length == 1) {
        await _loginWithWorkspace(workspaces.first);
      } else {
        final selected = await Navigator.push<AuthWorkspace>(
          context,
          MaterialPageRoute(builder: (_) => WorkspaceSelectScreen(workspaces: workspaces)),
        );
        if (selected != null && mounted) {
          await _loginWithWorkspace(selected);
        }
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loginWithWorkspace(AuthWorkspace workspace) async {
    await ref.read(authProvider.notifier).login(
          email: _emailCtrl.text.trim(),
          password: _passwordCtrl.text,
          workspaceId: workspace.id,
        );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.surface,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 48),
              _buildLogo(),
              const SizedBox(height: 32),
              _buildForm(),
              const SizedBox(height: 16),
              if (_error != null) _buildError(),
              const SizedBox(height: 8),
              _buildSubmitButton(),
              const SizedBox(height: 24),
              _buildFooter(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildLogo() => Column(
        children: [
          Image.asset(
            'assets/images/logo-mark.png',
            width: 80,
            height: 80,
          ),
          const SizedBox(height: 16),
          Image.asset(
            'assets/images/logo.png',
            height: 32,
            fit: BoxFit.contain,
          ),
          const SizedBox(height: 6),
          const Text(
            'Sign in to your workspace',
            style: TextStyle(fontSize: 15, color: AppColors.textSecondary),
          ),
        ],
      );

  Widget _buildForm() => Form(
        key: _formKey,
        child: Column(
          children: [
            TextFormField(
              controller: _emailCtrl,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autocorrect: false,
              decoration: const InputDecoration(
                labelText: 'Email address',
                prefixIcon: Icon(Icons.email_outlined),
              ),
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'Enter your email';
                if (!v.contains('@')) return 'Enter a valid email';
                return null;
              },
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _passwordCtrl,
              obscureText: _obscurePassword,
              textInputAction: TextInputAction.done,
              onFieldSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Password',
                prefixIcon: const Icon(Icons.lock_outline),
                suffixIcon: IconButton(
                  icon: Icon(_obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                  onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                ),
              ),
              validator: (v) => (v == null || v.isEmpty) ? 'Enter your password' : null,
            ),
          ],
        ),
      );

  Widget _buildError() => Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: AppColors.error.withOpacity(0.08),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: AppColors.error.withOpacity(0.3)),
        ),
        child: Row(
          children: [
            const Icon(Icons.error_outline, color: AppColors.error, size: 18),
            const SizedBox(width: 8),
            Expanded(
              child: Text(_error!, style: const TextStyle(color: AppColors.error, fontSize: 13)),
            ),
          ],
        ),
      );

  Widget _buildSubmitButton() => SizedBox(
        width: double.infinity,
        child: ElevatedButton(
          onPressed: _loading ? null : _submit,
          child: _loading
              ? const SizedBox(
                  height: 20, width: 20, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
              : const Text('Sign in'),
        ),
      );

  Widget _buildFooter() => Text(
        'Use the same email and password as the Zonseob web app.',
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 12, color: AppColors.textSecondary),
      );
}
