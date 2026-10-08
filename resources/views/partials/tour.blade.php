@push('modals')
    <div x-data="zTour(@js($tour['steps']), @js(route('help.tours.done', $tour['key'])), @js($autoStart))" x-cloak
         @zonseo:tour.window="start()" @keydown.window="key($event)" @resize.window="place()" @scroll.window="place()"
         class="z-tour" :class="{ 'is-open': open }" data-tour-key="{{ $tour['key'] }}">
        <template x-if="open">
            <div>
                <div class="z-tour-shade" @click="close('dismissed')"></div>
                <div class="z-tour-ring" x-show="ring" :style="ring"></div>
                <div class="z-tour-card" role="dialog" aria-modal="true" :aria-label="step.title" :style="card" x-ref="card">
                    <div class="d-flex align-items-start gap-2 mb-1">
                        <div class="fw-600 flex-grow-1" x-text="step.title"></div>
                        <button type="button" class="btn-close btn-sm" aria-label="Close tour" @click="close('dismissed')"></button>
                    </div>
                    <p class="fs-7 text-muted mb-3" x-text="step.text"></p>
                    <div class="d-flex align-items-center gap-2">
                        <span class="fs-8 text-muted me-auto" x-text="(index + 1) + ' of ' + steps.length"></span>
                        <button type="button" class="btn btn-sm btn-white" x-show="index > 0" @click="go(index - 1)">Back</button>
                        <button type="button" class="btn btn-sm btn-primary" @click="index < steps.length - 1 ? go(index + 1) : close('completed')" x-text="index < steps.length - 1 ? 'Next' : 'Done'"></button>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endpush
