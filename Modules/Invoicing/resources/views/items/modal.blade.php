@php $sfx = $item?->id ?? 'new'; $isOld = old('_item', 'new') === (string) $sfx; @endphp
<div class="modal fade" id="itemModal_{{ $sfx }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ $item ? route('items.update', $item) : route('items.store') }}">
            @csrf
            @if($item) @method('PUT') @endif
            <input type="hidden" name="_item" value="{{ $sfx }}">
            <div class="modal-header"><h5 class="modal-title">{{ $item ? 'Edit '.$item->name : 'New product or service' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4"><x-form.select name="type" label="Type" :options="$types" :value="$isOld ? old('type') : ($item?->type ?? 'service')" id="i_type_{{ $sfx }}" required /></div>
                    <div class="col-md-8"><x-form.input name="name" label="Name" :value="$isOld ? old('name') : $item?->name" id="i_name_{{ $sfx }}" required /></div>
                </div>
                <x-form.textarea name="description" label="Description" :value="$isOld ? old('description') : $item?->description" rows="2" id="i_desc_{{ $sfx }}" />
                <div class="row">
                    <div class="col-md-4"><x-form.input name="price" type="number" step="0.01" min="0" label="Selling price" :value="$isOld ? old('price') : $item?->price" id="i_price_{{ $sfx }}" required /></div>
                    <div class="col-md-4"><x-form.input name="cost" type="number" step="0.01" min="0" label="Cost (optional)" :value="$isOld ? old('cost') : $item?->cost" id="i_cost_{{ $sfx }}" /></div>
                    <div class="col-md-4"><x-form.select name="tax_rate_id" label="Tax rate" :options="$taxRates" :value="$isOld ? old('tax_rate_id') : $item?->tax_rate_id" placeholder="None" id="i_tax_{{ $sfx }}" /></div>
                </div>
                <div class="row">
                    <div class="col-md-4"><x-form.input name="sku" label="SKU / code" :value="$isOld ? old('sku') : $item?->sku" id="i_sku_{{ $sfx }}" help="For items sold by weight, the scale's item number." /></div>
                    <div class="col-md-5"><x-form.input name="barcode" label="Barcode" :value="$isOld ? old('barcode') : $item?->barcode" id="i_barcode_{{ $sfx }}" maxlength="64" placeholder="Scan it here" help="Leave blank, then use Make a barcode to get one." /></div>
                    <div class="col-md-3"><x-form.input name="unit" label="Unit" placeholder="each, kg…" :value="$isOld ? old('unit') : $item?->unit" id="i_unit_{{ $sfx }}" /></div>
                </div>
                <div class="row">
                    <div class="col-md-6"><x-form.input name="stock_qty" type="number" step="0.001" label="In stock (products, optional)" :value="$isOld ? old('stock_qty') : $item?->stock_qty" id="i_stock_{{ $sfx }}" help="Leave blank to skip stock counting." /></div>
                    <div class="col-md-6"><x-form.input name="reorder_level" type="number" step="0.001" min="0" label="Reorder at" :value="$isOld ? old('reorder_level') : $item?->reorder_level" id="i_reorder_{{ $sfx }}" /></div>
                </div>
                @if($item)<x-form.check name="is_active" label="Active (shown in pickers)" :checked="$isOld ? (bool) old('is_active') : (bool) $item->is_active" id="i_active_{{ $sfx }}" switch />@endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><x-icon name="check" /> {{ $item ? 'Save changes' : 'Add item' }}</button>
            </div>
        </form>
    </div>
</div>
