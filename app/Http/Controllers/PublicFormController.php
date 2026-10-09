<?php

namespace App\Http\Controllers;

use App\Models\WebForm;
use App\Support\Branding;
use App\Support\Notifier;
use App\Support\WebForms;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Validation\ValidationException;

/**
 * A workspace's public form, at its own link or embedded on another website. Answers pass the
 * form's rules, a hidden trap field and a minimum fill time, then become a record.
 */
class PublicFormController extends Controller
{
    /** Seconds a person needs at least to fill in a form; quicker posts are bots. */
    public const MIN_FILL_SECONDS = 3;

    public function __construct(protected WorkspaceContext $context) {}

    public function show(Request $request, string $uuid): Response
    {
        $form = $this->open($request, $uuid);

        return $this->framed($request, view('forms.public.show', [
            'form' => $form,
            'open' => $this->accepting($form),
            'fields' => WebForms::placed($form),
            'started' => Crypt::encryptString((string) now()->getTimestamp()),
        ]));
    }

    public function store(Request $request, string $uuid): RedirectResponse
    {
        $form = $this->open($request, $uuid);
        abort_unless($this->accepting($form), 404);

        [$rules, $attributes] = WebForms::rules($form);
        $answers = $request->validate($rules + ['website' => ['prohibited'], 'started' => ['required', 'string']], [], $attributes);
        $this->checkPace((string) $answers['started']);

        $submission = WebForms::submit($form, $answers, $request->ip());

        Notifier::send(Notifier::admins($form->workspace), 'forms', 'New answer to "'.$form->name.'"',
            $submission->summary(),
            route('forms.show', $form), 'clipboard-list', $form->workspace);

        return redirect()->route('forms.public.thanks', ['uuid' => $form->uuid, 'embed' => $request->boolean('embed') ? 1 : null]);
    }

    public function thanks(Request $request, string $uuid): Response
    {
        return $this->framed($request, view('forms.public.thanks', ['form' => $this->open($request, $uuid)]));
    }

    /** Find the form and load its workspace for the visitor; unknown links and closed workspaces are 404s. */
    protected function open(Request $request, string $uuid): WebForm
    {
        $form = WebForm::allWorkspaces()->where('uuid', $uuid)->with('workspace')->firstOrFail();
        abort_unless($form->workspace?->is_active, 404);
        $this->context->set($form->workspace);

        ViewFacade::share([
            'publicWorkspace' => $form->workspace,
            'brand' => app(Branding::class)->for($form->workspace),
            'embed' => $request->boolean('embed'),
        ]);

        return $form;
    }

    /** Switched off, or its app turned off since it was built: the form says it is closed. */
    protected function accepting(WebForm $form): bool
    {
        return $form->is_active && WebForms::allowsTarget($form->workspace, $form->target);
    }

    protected function checkPace(string $started): void
    {
        try {
            $startedAt = (int) Crypt::decryptString($started);
        } catch (\Throwable) {
            $startedAt = 0;
        }

        if ($startedAt <= 0 || now()->getTimestamp() - $startedAt < self::MIN_FILL_SECONDS || now()->getTimestamp() - $startedAt > 86400) {
            throw ValidationException::withMessages(['started' => 'Something went wrong sending the form. Please reload the page and try again.']);
        }
    }

    /** Embedded copies may be shown inside any website's page; the normal page keeps the default framing rules. */
    protected function framed(Request $request, View $view): Response
    {
        $response = response($view);
        if ($request->boolean('embed')) {
            $response->headers->set('Content-Security-Policy', 'frame-ancestors *');
            $response->headers->set('X-Frame-Options', 'ALLOWALL');
        }

        return $response;
    }
}
