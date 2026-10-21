@extends('layouts.marketing')
@section('title', 'Privacy policy')
@section('content')
    @php
        $sections = [
            'Who we are' => [
                config('app.name').' (zonseob.com) is business software for many professions: one account gives a workspace its apps for clients, sales, stock, staff, documents and more.',
            ],
            'What we collect' => [
                'When you register we keep your name, email address, phone number and workspace details, and billing details when you choose a paid plan.',
                'Each workspace keeps its own records in the apps it uses. Those records belong to the workspace, which decides what is entered.',
                'Like most websites we keep basic server logs (IP address, browser, pages requested) to run and secure the service.',
            ],
            'How we use it' => [
                'To provide the service, sign you in, send account and billing messages, and give support.',
                'We do not sell personal data, and we do not use workspace records for advertising.',
            ],
            'Who can see it' => [
                "A workspace's records are visible only to its own members, according to their role. Our team looks at them only to give support or fix a problem.",
                'We use hosting, email, SMS and payment providers to run the service; they process data only on our behalf.',
            ],
            'Facebook' => [
                "We post about Zonseob on our own Facebook Page through Meta's Pages API. That connection is used only to publish posts on the Zonseob Page; we do not read or store data about Facebook users. Workspaces that connect their own Facebook inbox control that connection from their settings.",
            ],
            'Keeping and deleting data' => [
                'We keep data while the workspace has an account. A workspace can export its data or ask us to delete it at any time, and anyone can ask what we hold about them or ask for it to be corrected or deleted.',
            ],
            'Contact' => [
                'Ask through the chat on this site, or message the Zonseob Facebook Page.',
            ],
        ];
    @endphp
    <section class="z-mk-section">
        <div class="container" style="max-width: 760px">
            <h1 class="z-mk-title mb-2">Privacy policy</h1>
            <p class="text-muted mb-5">Last updated 21 October 2026</p>
            @foreach($sections as $title => $paragraphs)
                <h2 class="h5 fw-semibold mt-4 mb-2">{{ $title }}</h2>
                @foreach($paragraphs as $p)
                    <p class="text-muted">{{ $p }}</p>
                @endforeach
            @endforeach
        </div>
    </section>
@endsection
