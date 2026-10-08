@extends('layouts.app')
@section('title', $automation->exists ? $automation->name : 'New automation')
@section('content')
    <x-page-header :title="$automation->exists ? $automation->name : 'New automation'" sub="When something happens, and the conditions hold, the actions run in order." :crumbs="['Settings' => route('settings.workspace.edit'), 'Automations' => route('settings.automations.index'), $automation->exists ? 'Edit' : 'New']" />

    <script>
        function automationBuilder(builder, initial) {
            const blankAction = (type) => ({type, to: 'admins', user_id: '', message: '', title: '', description: '', assignee: '', due_in_days: '', priority: 'normal', subject: '', body: '', field: '', value: ''});
            return {
                ...builder,
                trigger: initial.trigger,
                conditions: initial.conditions.map(c => ({field: c.field ?? '', operator: c.operator ?? 'equals', value: c.value ?? ''})),
                actions: initial.actions.map(a => Object.assign(blankAction(a.type), a)),
                get subject() { return this.trigger.split('.')[0]; },
                get subjectFields() { return this.fields[this.subject] ?? {}; },
                get updatableFields() { return this.updatable[this.subject] ?? []; },
                fieldDef(name) { return this.subjectFields[name] ?? {type: 'text'}; },
                needsValue(c) { return !this.valueless.includes(c.operator); },
                tag(p) { return '{' + '{' + p + '}' + '}'; },
                triggerChanged() { this.conditions = this.conditions.filter(c => c.field in this.subjectFields); this.actions.forEach(a => { if (a.type === 'update_record' && !this.updatableFields.includes(a.field)) { a.field = ''; a.value = ''; } }); },
                addCondition() { this.conditions.push({field: Object.keys(this.subjectFields)[0] ?? '', operator: 'equals', value: ''}); },
                addAction() { this.actions.push(blankAction('notify')); },
            };
        }
    </script>

    @if($errors->any())
        <div class="alert alert-danger fs-7"><div class="fw-600 mb-1">Please fix these and save again:</div><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row g-4">
        <div class="{{ $automation->exists ? 'col-xl-8' : 'col-xl-9' }}">
            <form method="POST" action="{{ $automation->exists ? route('settings.automations.update', $automation) : route('settings.automations.store') }}"
                  x-data="automationBuilder(@js($builder), @js(['trigger' => old('trigger', $automation->trigger), 'conditions' => old('conditions', $automation->conditions ?? []), 'actions' => old('actions', $automation->actions ?? [])]))">
                @csrf
                @if($automation->exists) @method('PUT') @endif

                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label" for="automation-name">Name</label>
                        <input type="text" class="form-control" id="automation-name" name="name" maxlength="120" value="{{ old('name', $automation->name) }}" placeholder="e.g. Follow up new leads" required>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0"><x-icon name="zap" /> When</h5></div>
                    <div class="card-body">
                        <select class="form-select" name="trigger" x-model="trigger" @change="triggerChanged()">
                            <template x-for="(label, key) in triggers" :key="key"><option :value="key" x-text="label" :selected="key === trigger"></option></template>
                        </select>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><x-icon name="filter" /> Only if <span class="text-muted fw-normal fs-7">(optional, all must be true)</span></h5>
                        <button type="button" class="btn btn-sm btn-white" @click="addCondition()" x-show="conditions.length < {{ \App\Support\Automations::MAX_CONDITIONS }}"><x-icon name="plus" /> Condition</button>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-7 mb-0" x-show="conditions.length === 0">No conditions: it runs every time.</p>
                        <template x-for="(condition, index) in conditions" :key="index">
                            <div class="row g-2 align-items-center mb-2">
                                <div class="col-sm-4">
                                    <select class="form-select form-select-sm" :name="`conditions[${index}][field]`" x-model="condition.field">
                                        <template x-for="(def, key) in subjectFields" :key="key"><option :value="key" x-text="def.label" :selected="key === condition.field"></option></template>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <select class="form-select form-select-sm" :name="`conditions[${index}][operator]`" x-model="condition.operator">
                                        <template x-for="(label, key) in operators" :key="key"><option :value="key" x-text="label" :selected="key === condition.operator"></option></template>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <template x-if="needsValue(condition) && fieldDef(condition.field).type === 'choice'">
                                        <select class="form-select form-select-sm" :name="`conditions[${index}][value]`" x-model="condition.value">
                                            <option value="">Choose…</option>
                                            <template x-for="(label, key) in fieldDef(condition.field).options" :key="key"><option :value="key" x-text="label" :selected="key === condition.value"></option></template>
                                        </select>
                                    </template>
                                    <template x-if="needsValue(condition) && fieldDef(condition.field).type === 'person'">
                                        <select class="form-select form-select-sm" :name="`conditions[${index}][value]`" x-model="condition.value">
                                            <option value="">Choose…</option>
                                            <template x-for="(name, id) in people" :key="id"><option :value="id" x-text="name" :selected="String(id) === String(condition.value)"></option></template>
                                        </select>
                                    </template>
                                    <template x-if="needsValue(condition) && ['text', 'number'].includes(fieldDef(condition.field).type)">
                                        <input :type="fieldDef(condition.field).type === 'number' ? 'number' : 'text'" step="any" class="form-control form-control-sm" :name="`conditions[${index}][value]`" x-model="condition.value" maxlength="255">
                                    </template>
                                </div>
                                <div class="col-sm-1 text-end">
                                    <button type="button" class="btn btn-sm btn-white" @click="conditions.splice(index, 1)" title="Remove"><x-icon name="x" /></button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0"><x-icon name="play" /> Then</h5>
                        <button type="button" class="btn btn-sm btn-white" @click="addAction()" x-show="actions.length < {{ \App\Support\Automations::MAX_ACTIONS }}"><x-icon name="plus" /> Action</button>
                    </div>
                    <div class="card-body">
                        <template x-for="(action, index) in actions" :key="index">
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex gap-2 mb-2">
                                    <select class="form-select form-select-sm" :name="`actions[${index}][type]`" x-model="action.type">
                                        @foreach(\App\Support\Automations::ACTIONS as $type => $def)
                                            <option value="{{ $type }}">{{ $def['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="btn btn-sm btn-white" @click="actions.splice(index, 1)" x-show="actions.length > 1" title="Remove"><x-icon name="x" /></button>
                                </div>

                                <template x-if="action.type === 'notify'">
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <label class="form-label fs-8">Who</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][to]`" x-model="action.to">
                                                <template x-for="(label, key) in recipients" :key="key"><option :value="key" x-text="label" :selected="key === action.to"></option></template>
                                            </select>
                                        </div>
                                        <div class="col-sm-6" x-show="action.to === 'user'">
                                            <label class="form-label fs-8">Person</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][user_id]`" x-model="action.user_id">
                                                <option value="">Choose…</option>
                                                <template x-for="(name, id) in people" :key="id"><option :value="id" x-text="name" :selected="String(id) === String(action.user_id)"></option></template>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fs-8">Message</label>
                                            <input type="text" class="form-control form-control-sm" :name="`actions[${index}][message]`" x-model="action.message" maxlength="500" placeholder="e.g. New lead: @{{name}}">
                                        </div>
                                    </div>
                                </template>

                                <template x-if="action.type === 'create_task'">
                                    <div class="row g-2">
                                        <div class="col-12" x-show="!tasksOn"><div class="alert alert-warning fs-8 py-1 mb-0">The Tasks app is off in this workspace, so this action will be skipped.</div></div>
                                        <div class="col-12">
                                            <label class="form-label fs-8">Task title</label>
                                            <input type="text" class="form-control form-control-sm" :name="`actions[${index}][title]`" x-model="action.title" maxlength="200" placeholder="e.g. Call @{{name}}">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fs-8">Details <span class="text-muted">(optional)</span></label>
                                            <textarea class="form-control form-control-sm" rows="2" :name="`actions[${index}][description]`" x-model="action.description" maxlength="2000"></textarea>
                                        </div>
                                        <div class="col-sm-4">
                                            <label class="form-label fs-8">Assign to</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][assignee]`" x-model="action.assignee">
                                                <option value="">Nobody</option>
                                                <option value="assignee">Whoever the record is assigned to</option>
                                                <option value="user">A particular person</option>
                                            </select>
                                        </div>
                                        <div class="col-sm-4" x-show="action.assignee === 'user'">
                                            <label class="form-label fs-8">Person</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][user_id]`" x-model="action.user_id">
                                                <option value="">Choose…</option>
                                                <template x-for="(name, id) in people" :key="id"><option :value="id" x-text="name" :selected="String(id) === String(action.user_id)"></option></template>
                                            </select>
                                        </div>
                                        <div class="col-sm-2">
                                            <label class="form-label fs-8">Due in (days)</label>
                                            <input type="number" min="0" max="365" class="form-control form-control-sm" :name="`actions[${index}][due_in_days]`" x-model="action.due_in_days">
                                        </div>
                                        <div class="col-sm-2">
                                            <label class="form-label fs-8">Priority</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][priority]`" x-model="action.priority">
                                                <template x-for="(label, key) in priorities" :key="key"><option :value="key" x-text="label" :selected="key === action.priority"></option></template>
                                            </select>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="action.type === 'send_email'">
                                    <div class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label fs-8">Subject</label>
                                            <input type="text" class="form-control form-control-sm" :name="`actions[${index}][subject]`" x-model="action.subject" maxlength="200">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fs-8">Message <span class="text-muted">(sent to the customer's email address)</span></label>
                                            <textarea class="form-control form-control-sm" rows="4" :name="`actions[${index}][body]`" x-model="action.body" maxlength="5000"></textarea>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="action.type === 'send_sms'">
                                    <div>
                                        <div class="alert alert-warning fs-8 py-1 mb-2" x-show="!smsOn">Text messages are not set up yet (Text messages › Settings), so this action will be skipped.</div>
                                        <label class="form-label fs-8">Text <span class="text-muted">(sent to the customer's mobile)</span></label>
                                        <textarea class="form-control form-control-sm" rows="2" :name="`actions[${index}][message]`" x-model="action.message" maxlength="480"></textarea>
                                        <div class="fs-8 text-muted mt-1"><span x-text="action.message.length"></span> characters</div>
                                    </div>
                                </template>

                                <template x-if="action.type === 'update_record'">
                                    <div class="row g-2">
                                        <div class="col-12" x-show="updatableFields.length === 0"><div class="alert alert-warning fs-8 py-1 mb-0">This kind of record has nothing an automation can change.</div></div>
                                        <div class="col-sm-6" x-show="updatableFields.length">
                                            <label class="form-label fs-8">Set</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][field]`" x-model="action.field" @change="action.value = ''">
                                                <option value="">Choose…</option>
                                                <template x-for="key in updatableFields" :key="key"><option :value="key" x-text="fieldDef(key).label" :selected="key === action.field"></option></template>
                                            </select>
                                        </div>
                                        <div class="col-sm-6" x-show="action.field">
                                            <label class="form-label fs-8">To</label>
                                            <select class="form-select form-select-sm" :name="`actions[${index}][value]`" x-model="action.value">
                                                <option value="">Choose…</option>
                                                <template x-for="(label, key) in (fieldDef(action.field).options ?? {})" :key="key"><option :value="key" x-text="label" :selected="key === action.value"></option></template>
                                            </select>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div class="fs-8 text-muted">
                            Messages can include details from the record:
                            <template x-for="p in placeholders[trigger] ?? []" :key="p"><code class="me-1" x-text="tag(p)"></code></template>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <input type="hidden" name="is_active" value="0">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="automation-active" @checked(old('is_active', $automation->is_active))>
                        <label class="form-check-label" for="automation-active">On</label>
                    </div>
                    <button class="btn btn-primary ms-auto">Save automation</button>
                </div>
            </form>
        </div>

        @if($automation->exists)
            <div class="col-xl-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Recent runs</h5></div>
                    @if($runs->isEmpty())
                        <div class="card-body"><x-empty icon="history" title="Not run yet" text="Runs appear here each time it is set off." /></div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($runs as $run)
                                <div class="list-group-item">
                                    <div class="d-flex gap-2 align-items-center">
                                        <x-pill :status="['succeeded' => 'success', 'failed' => 'danger', 'skipped' => 'inactive'][$run->status] ?? 'info'">{{ $run->statusLabel() }}</x-pill>
                                        <span class="fs-7 text-truncate">{{ $run->subject_label ?? '—' }}</span>
                                        <span class="fs-8 text-muted ms-auto text-nowrap">{{ $run->created_at->diffForHumans() }}</span>
                                    </div>
                                    <ul class="list-unstyled fs-8 mb-0 mt-1">
                                        @foreach($run->log ?? [] as $line)
                                            <li class="{{ $line['ok'] ? 'text-muted' : 'text-danger' }}"><x-icon :name="$line['ok'] ? 'check' : 'alert-triangle'" /> {{ $line['text'] }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <form method="POST" action="{{ route('settings.automations.destroy', $automation) }}" onsubmit="return confirm('Delete this automation and its history?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-soft-danger"><x-icon name="trash-2" /> Delete automation</button>
                </form>
            </div>
        @endif
    </div>
@endsection
