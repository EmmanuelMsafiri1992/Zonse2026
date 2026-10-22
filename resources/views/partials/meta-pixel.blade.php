{{-- Meta Pixel on the public, sign-up and setup pages only, so Facebook adverts can optimise for sign-ups. --}}
@php
    $metaPixelId = app(\App\Social\SocialPoster::class)->pixelId();
    $reportRegistration = $metaPixelId !== '' && session()->pull('meta_pixel.registered', false);
@endphp
@if($metaPixelId !== '')
    <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', @js($metaPixelId));
        fbq('track', 'PageView');
        @if($reportRegistration)
        fbq('track', 'CompleteRegistration');
        @endif
    </script>
    <noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ $metaPixelId }}&ev=PageView&noscript=1"></noscript>
@endif
