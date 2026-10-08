<div class="row">
    <div class="col-sm-6"><x-form.input name="name" label="Your name" maxlength="120" required autocomplete="name" /></div>
    <div class="col-sm-6"><x-form.input name="email" type="email" label="Email" maxlength="190" required autocomplete="email" /></div>
</div>
<x-form.input name="phone" type="tel" label="Phone" maxlength="40" autocomplete="tel" />
<div class="d-none" aria-hidden="true"><label for="website">Leave this empty</label><input type="text" name="website" id="website" tabindex="-1" autocomplete="off"></div>
